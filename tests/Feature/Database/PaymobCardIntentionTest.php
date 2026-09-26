<?php

namespace Tests\Feature\Database;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\PaymentAttemptException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\PaymobCardIntentionService;
use App\Services\PaymentAttemptService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymobCardIntentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.paymob.secret_key' => 'test-secret',
            'services.paymob.public_key' => 'test-public',
            'services.paymob.card_integration_id' => '123',
            'services.paymob.api_base_url' => 'https://paymob.test',
            'services.paymob.intention_endpoint' => '/v1/intention/',
            'services.paymob.redirect_url' => 'https://shop.test/payments/return',
            'services.paymob.webhook_url' => 'https://shop.test/api/v1/payments/paymob/webhook',
            'services.paymob.unified_checkout_base_url' => 'https://paymob.test/unifiedcheckout/',
        ]);
    }

    public function test_it_builds_and_persists_a_card_intention_without_mutating_the_order(): void
    {
        $attempt = $this->makeAttempt();
        $orderBefore = $attempt->order->fresh();

        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int_123',
            'client_secret' => 'client-secret-value',
            'intention_order_id' => 456,
            'amount' => 17000,
            'currency' => 'EGP',
            'special_reference' => $attempt->merchant_reference,
            'payment_methods' => [['integration_id' => 123]],
        ], 201)]);

        $result = app(PaymobCardIntentionService::class)->initiate($attempt);
        $stored = $attempt->fresh();

        $this->assertSame(PaymentAttemptStatus::Pending, $stored->status);
        $this->assertSame('int_123', $stored->provider_intention_id);
        $this->assertSame('456', $stored->provider_order_id);
        $this->assertSame('client-secret-value', $stored->provider_client_secret);
        $this->assertStringNotContainsString('client-secret-value', (string) \DB::table('payment_attempts')->whereKey($stored->getKey())->value('provider_client_secret'));
        $this->assertArrayNotHasKey('provider_client_secret', $stored->toArray());
        $this->assertStringContainsString('publicKey=test-public', $result->checkoutUrl);
        $this->assertStringContainsString('clientSecret=client-secret-value', $result->checkoutUrl);
        $this->assertSame($orderBefore->status, $stored->order->fresh()->status);
        $this->assertSame($orderBefore->payment_status, $stored->order->fresh()->payment_status);
        Http::assertSent(function ($request) use ($attempt): bool {
            $data = $request->data();
            return $data['amount'] === 17000
                && $data['payment_methods'] === [123]
                && $data['special_reference'] === $attempt->merchant_reference
                && $data['notification_url'] === 'https://shop.test/api/v1/payments/paymob/webhook'
                && str_starts_with($data['redirection_url'], 'https://shop.test/payments/return#payment_return=')
                && ! str_contains($data['redirection_url'], $attempt->order->public_id)
                && ! str_contains($data['redirection_url'], $attempt->public_id)
                && $data['items'][0]['amount'] === 10000
                && $data['items'][0]['quantity'] === 1
                && $data['items'][1]['name'] === 'Shipping'
                && $data['items'][1]['amount'] === 7000;
        });
    }

    public function test_intention_expiration_is_a_positive_integer_for_fractional_remaining_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 12:00:00.250000'));

        try {
            $attempt = $this->makeAttempt([
                'payment_expires_at' => Carbon::parse('2026-09-26 12:30:00'),
            ]);

            Http::fake(['https://paymob.test/*' => Http::response([
                'id' => 'int_fractional_expiration',
                'client_secret' => 'client-secret',
                'amount' => 17000,
                'currency' => 'EGP',
            ], 201)]);

            app(PaymobCardIntentionService::class)->initiate($attempt);

            Http::assertSent(function ($request): bool {
                $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertIsInt($payload['expiration']);
                $this->assertGreaterThan(0, $payload['expiration']);
                $this->assertSame(1799, $payload['expiration']);

                return true;
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pending_attempt_is_replayed_without_a_second_provider_request(): void
    {
        $attempt = $this->makeAttempt();
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int_123', 'client_secret' => 'client-secret', 'amount' => 17000, 'currency' => 'EGP',
        ], 201)]);
        $service = app(PaymobCardIntentionService::class);

        $first = $service->initiate($attempt);
        $second = $service->initiate($attempt->fresh());

        $this->assertSame($first->checkoutUrl, $second->checkoutUrl);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_provider_outcomes_block_resubmission(): void
    {
        $attempt = $this->makeAttempt();
        Http::fake(['https://paymob.test/*' => Http::response(['provider_error' => 'sensitive'], 503)]);

        try {
            app(PaymobCardIntentionService::class)->initiate($attempt);
            $this->fail('An ambiguous provider response must fail safely.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('payment_outcome_uncertain', $exception->errorCode);
        }

        $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->fresh()->status);
        try {
            app(PaymobCardIntentionService::class)->initiate($attempt->fresh());
            $this->fail('A requires-review attempt must not be resubmitted.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('payment_attempt_not_retryable', $exception->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_provider_rejection_keeps_public_diagnostics_out_of_the_exception_and_logs(): void
    {
        $attempt = $this->makeAttempt();
        Log::spy();
        Http::fake(['https://paymob.test/*' => Http::response([
            'detail' => 'Invalid customer@example.com secret=do-not-log',
            'errors' => ['billing_data.email' => 'Invalid customer@example.com'],
            'raw_response' => 'customer phone 01012345678 client-secret-value',
        ], 422)]);

        try {
            app(PaymobCardIntentionService::class)->initiate($attempt);
            $this->fail('A provider rejection should be raised.');
        } catch (\App\Exceptions\PaymobRequestException $exception) {
            $this->assertSame('provider_client_error', $exception->errorCode);
            $this->assertStringNotContainsString('customer@example.com', $exception->getMessage());
            $this->assertStringNotContainsString('client-secret-value', $exception->getMessage());
        }

        $attempt->refresh();
        $this->assertSame(PaymentAttemptStatus::Failed, $attempt->status);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'Paymob request rejected by provider.'
                && ! str_contains($serialized, 'customer@example.com')
                && ! str_contains($serialized, 'client-secret-value')
                && ! str_contains($serialized, '01012345678')
                && ! str_contains($serialized, 'raw_response');
        });
    }

    public function test_local_validation_fails_before_http_and_does_not_claim_the_attempt(): void
    {
        $attempt = $this->makeAttempt();
        $attempt->order->update(['payment_expires_at' => now()->subSecond()]);
        Http::fake();

        $this->expectException(PaymentAttemptException::class);
        app(PaymobCardIntentionService::class)->initiate($attempt);

        $this->assertSame(PaymentAttemptStatus::Created, $attempt->fresh()->status);
        Http::assertNothingSent();
    }

    private function makeAttempt(array $orderOverrides = []): PaymentAttempt
    {
        $order = Order::factory()->create(array_merge([
            'customer_name' => 'Test Customer',
            'payment_method' => PaymentMethod::Card,
            'subtotal' => '100.00',
            'shipping_fee' => '70.00',
            'total' => '170.00',
            'payment_expires_at' => now()->addMinutes(30),
        ], $orderOverrides));
        $order->items()->create([
            'sku' => 'SNAPSHOT-1',
            'product_name_en' => 'Stored Snapshot',
            'product_name_ar' => 'Stored Snapshot',
            'options_snapshot' => [],
            'unit_price' => '100.00',
            'quantity' => 1,
            'line_total' => '100.00',
        ]);

        return app(PaymentAttemptService::class)->create($order, PaymentMethod::Card, (string) Str::uuid());
    }
}
