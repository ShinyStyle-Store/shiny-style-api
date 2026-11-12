<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicCardPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.paymob.secret_key' => 'test-secret',
            'services.paymob.public_key' => 'test-public',
            'services.paymob.card_integration_id' => '456',
            'services.paymob.wallet_integration_id' => '789',
            'services.paymob.api_base_url' => 'https://paymob.test',
            'services.paymob.intention_endpoint' => '/v1/intention/',
            'services.paymob.redirect_url' => 'https://shop.test/return',
            'services.paymob.webhook_url' => 'https://shop.test/api/v1/payments/paymob/webhook',
            'services.paymob.unified_checkout_base_url' => 'https://paymob.test/unifiedcheckout/',
        ]);
    }

    public function test_signed_initiation_returns_checkout_url_and_replays_without_second_provider_request(): void
    {
        $order = $this->cardOrder();
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int-public-1',
            'client_secret' => 'client-secret',
            'intention_order_id' => 987001,
        ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $key = (string) Str::uuid();

        $first = $this->withHeader('Idempotency-Key', $key)->post($url);
        $first->assertCreated()->assertJsonStructure([
            'data' => ['paymentAttemptId', 'status', 'checkoutUrl', 'expiresAt'],
        ]);
        $returnTokenHash = DB::table('payment_attempts')->value('payment_return_token_hash');
        $second = $this->withHeader('Idempotency-Key', $key)->post($url);
        $second->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertSame($first->json('data.checkoutUrl'), $second->json('data.checkoutUrl'));
        $this->assertSame($returnTokenHash, DB::table('payment_attempts')->value('payment_return_token_hash'));
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('test-secret', $first->getContent());
        $this->assertStringContainsString('client-secret', $first->json('data.checkoutUrl'));
    }

    public function test_signed_wallet_initiation_uses_the_stored_method_and_wallet_integration_on_replay(): void
    {
        $order = $this->walletOrder();
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int-wallet-1',
            'client_secret' => 'wallet-client-secret',
            'intention_order_id' => 987101,
            'payment_methods' => [['integration_id' => 789]],
        ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $key = (string) Str::uuid();

        $first = $this->withHeader('Idempotency-Key', $key)->post($url);
        $first->assertCreated()->assertJsonStructure([
            'data' => ['paymentAttemptId', 'status', 'checkoutUrl', 'expiresAt'],
        ]);
        $second = $this->withHeader('Idempotency-Key', $key)->post($url);

        $second->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame($first->json('data.checkoutUrl'), $second->json('data.checkoutUrl'));
        $attempt = PaymentAttempt::query()->firstOrFail();
        $this->assertSame(PaymentMethod::Wallet, $attempt->method);
        $this->assertSame(789, $attempt->integration_id);
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['payment_methods'] === [789]
                && $data['billing_data']['phone_number'] === '01110007513'
                && $data['billing_data']['email'] === 'wallet@example.com'
                && $data['notification_url'] === 'https://shop.test/api/v1/payments/paymob/webhook'
                && str_starts_with($data['redirection_url'], 'https://shop.test/return#payment_return=');
        });
    }

    public function test_wallet_initiation_does_not_accept_a_client_selected_method_or_integration(): void
    {
        $order = $this->walletOrder();
        Http::fake(['https://paymob.test/*' => Http::response()]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($url, ['payment_method' => 'card', 'integration_id' => 456])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->assertDatabaseCount('payment_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_wallet_provider_connection_failure_requires_review_without_a_stuck_submission(): void
    {
        $order = $this->walletOrder();
        Http::fake(['https://paymob.test/*' => Http::failedConnection('connection refused')]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $statusUrl = URL::temporarySignedRoute('orders.payments.status', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $idempotencyKey = (string) Str::uuid();

        $response = $this->withHeader('Idempotency-Key', $idempotencyKey)->post($url);

        $response->assertStatus(503)->assertJsonPath('code', 'payment_outcome_uncertain');
        $attempt = PaymentAttempt::query()->firstOrFail();
        $this->assertSame(PaymentMethod::Wallet, $attempt->method);
        $this->assertSame(789, $attempt->integration_id);
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        $this->get($statusUrl)->assertOk()->assertJsonPath('data.retryable', false);
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->post($url)
            ->assertStatus(503)
            ->assertJsonPath('code', 'payment_outcome_uncertain');
        Http::assertSentCount(1);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post($url)
            ->assertConflict()
            ->assertJsonPath('code', 'payment_attempt_not_retryable');
        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertSentCount(1);
    }

    public function test_wallet_provider_rejection_can_be_retried_with_a_new_key(): void
    {
        $order = $this->walletOrder();
        Http::fake(['https://paymob.test/*' => Http::sequence()
            ->push(['detail' => 'Invalid wallet intention'], 404)
            ->push([
                'id' => 'int-wallet-retry',
                'client_secret' => 'wallet-retry-secret',
                'intention_order_id' => 987103,
                'payment_methods' => [['integration_id' => 789]],
            ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $statusUrl = URL::temporarySignedRoute('orders.payments.status', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $firstKey = (string) Str::uuid();
        $retryKey = (string) Str::uuid();

        $this->withHeader('Idempotency-Key', $firstKey)
            ->post($url)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'payment_provider_rejected');
        $failedAttempt = PaymentAttempt::query()->firstOrFail();
        $this->assertSame(PaymentAttemptStatus::Failed, $failedAttempt->status);
        $this->assertSame('provider_client_error', $failedAttempt->failure_code);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->get($statusUrl)->assertOk()
            ->assertJsonPath('data.retryable', true)
            ->assertJsonPath('data.resultCode', 'payment_failed_retryable');
        $this->withHeader('Idempotency-Key', $firstKey)
            ->post($url)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'payment_provider_rejected');
        Http::assertSentCount(1);

        $this->withHeader('Idempotency-Key', $retryKey)
            ->post($url)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $attempts = PaymentAttempt::query()->orderBy('id')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame(PaymentAttemptStatus::Pending, $attempts->last()->status);
        $this->assertSame(789, $attempts->last()->integration_id);
        $this->assertNotSame($failedAttempt->merchant_reference, $attempts->last()->merchant_reference);
        $this->assertNotSame($failedAttempt->idempotency_key, $attempts->last()->idempotency_key);
        $this->assertSame($firstKey, $failedAttempt->idempotency_key);
        $this->assertSame($retryKey, $attempts->last()->idempotency_key);
        $this->assertSame(PaymentAttemptStatus::Failed, $failedAttempt->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        Http::assertSentCount(2);
    }

    public function test_active_wallet_attempt_cannot_be_retried(): void
    {
        $order = $this->walletOrder();
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int-wallet-active',
            'client_secret' => 'wallet-active-secret',
            'intention_order_id' => 987104,
            'payment_methods' => [['integration_id' => 789]],
        ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $statusUrl = URL::temporarySignedRoute('orders.payments.status', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $idempotencyKey = (string) Str::uuid();

        $this->withHeader('Idempotency-Key', $idempotencyKey)->post($url)->assertCreated();
        $this->get($statusUrl)->assertOk()->assertJsonPath('data.retryable', false);
        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->post($url)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post($url)
            ->assertConflict()
            ->assertJsonPath('code', 'active_payment_attempt_exists');

        $this->assertDatabaseCount('payment_attempts', 1);
        Http::assertSentCount(1);
    }

    public function test_confirmed_wallet_transaction_failure_can_be_retried(): void
    {
        $order = $this->walletOrder();
        Http::fake(['https://paymob.test/*' => Http::sequence()
            ->push([
                'id' => 'int-wallet-failed-transaction',
                'client_secret' => 'wallet-failed-transaction-secret',
                'intention_order_id' => 987105,
                'payment_methods' => [['integration_id' => 789]],
            ], 201)
            ->push([
                'id' => 'int-wallet-after-confirmed-failure',
                'client_secret' => 'wallet-after-confirmed-failure-secret',
                'intention_order_id' => 987106,
                'payment_methods' => [['integration_id' => 789]],
            ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $statusUrl = URL::temporarySignedRoute('orders.payments.status', now()->addMinutes(5), ['public_id' => $order->public_id]);

        $initialKey = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $initialKey)->post($url)->assertCreated();
        $attempt = PaymentAttempt::query()->firstOrFail();
        $attempt->update([
            'status' => PaymentAttemptStatus::Failed,
            'failure_code' => 'provider_transaction_failed',
        ]);
        $order->update(['payment_status' => PaymentStatus::Failed]);

        $this->get($statusUrl)->assertOk()
            ->assertJsonPath('data.retryable', true)
            ->assertJsonPath('data.resultCode', 'payment_failed_retryable');
        $this->withHeader('Idempotency-Key', $initialKey)
            ->post($url)
            ->assertConflict()
            ->assertJsonPath('code', 'payment_failed_retryable');
        Http::assertSentCount(1);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post($url)
            ->assertCreated();

        $this->assertDatabaseCount('payment_attempts', 2);
        Http::assertSentCount(2);
    }

    public function test_malformed_wallet_provider_response_requires_review_without_persisting_an_incomplete_result(): void
    {
        $order = $this->walletOrder();
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int-wallet-malformed',
            'intention_order_id' => 987102,
        ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->post($url);

        $response->assertStatus(503)->assertJsonPath('code', 'payment_outcome_uncertain');
        $attempt = PaymentAttempt::query()->firstOrFail();
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->status);
        $this->assertNull($attempt->provider_intention_id);
        $this->assertNull($attempt->provider_order_id);
    }

    public function test_signed_initiation_accepts_an_empty_json_object(): void
    {
        $order = $this->cardOrder();
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int-public-empty-object',
            'client_secret' => 'client-secret-empty-object',
            'intention_order_id' => 987002,
        ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);

        $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
        ], '{}')
            ->assertCreated();
    }

    public function test_provider_rejection_remains_generic_to_the_public_api(): void
    {
        $order = $this->cardOrder();
        Http::fake(['https://paymob.test/*' => Http::response([
            'detail' => 'Raw provider detail customer@example.com secret=do-not-expose',
            'raw_response' => 'client-secret-value and customer phone 01012345678',
        ], 422)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->post($url);

        $response->assertUnprocessable()
            ->assertJson([
                'code' => 'payment_provider_rejected',
                'message' => 'The payment provider rejected the payment request.',
            ]);
        $this->assertStringNotContainsString('Raw provider detail', $response->getContent());
        $this->assertStringNotContainsString('client-secret-value', $response->getContent());
        $this->assertStringNotContainsString('customer@example.com', $response->getContent());
        $this->assertSame(PaymentAttemptStatus::Failed, PaymentAttempt::query()->firstOrFail()->status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
    }

    public function test_reusing_a_key_after_provider_rejection_conflicts_but_a_new_key_can_retry(): void
    {
        $order = $this->cardOrder();
        Http::fake(['https://paymob.test/*' => Http::sequence()
            ->push(['detail' => 'Invalid request'], 422)
            ->push(['id' => 'int-retry', 'client_secret' => 'client-secret-retry', 'intention_order_id' => 987003], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $key = (string) Str::uuid();

        $this->withHeader('Idempotency-Key', $key)
            ->post($url)
            ->assertUnprocessable()
            ->assertJson(['code' => 'payment_provider_rejected']);

        $this->withHeader('Idempotency-Key', $key)
            ->post($url)
            ->assertConflict()
            ->assertJson(['code' => 'payment_attempt_not_retryable']);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post($url)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('payment_attempts', 2);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_missing_provider_order_id_returns_safe_uncertain_error_and_replay_does_not_resubmit(): void
    {
        $order = $this->cardOrder();
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int-public-unmatchable',
            'client_secret' => 'client-secret-unmatchable',
            'checkoutUrl' => 'https://paymob.test/checkout/unmatchable',
        ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $key = (string) Str::uuid();

        $first = $this->withHeader('Idempotency-Key', $key)->post($url);
        $first->assertServiceUnavailable()
            ->assertJson([
                'code' => 'payment_outcome_uncertain',
                'message' => 'The payment request could not be completed.',
            ])
            ->assertJsonMissingPath('data.checkoutUrl');

        $attempt = PaymentAttempt::query()->firstOrFail();
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->status);
        $this->assertNull($attempt->provider_order_id);

        $this->withHeader('Idempotency-Key', $key)->post($url)
            ->assertConflict()
            ->assertJson(['code' => 'payment_attempt_not_retryable']);
        Http::assertSentCount(1);
    }

    public function test_signed_initiation_rejects_any_request_body_field(): void
    {
        $order = $this->cardOrder();
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);

        foreach ([['amount' => 100], ['method' => 'card'], ['unexpected' => 'value']] as $body) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson($url, $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('body');
        }

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_signed_initiation_requires_a_valid_idempotency_key(): void
    {
        $order = $this->cardOrder();
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);

        $this->post($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->withHeader('Idempotency-Key', 'not-a-uuid')
            ->post($url)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_signed_initiation_rejects_missing_and_expired_signatures(): void
    {
        $order = $this->cardOrder();
        $signedUrl = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $unsignedUrl = strtok($signedUrl, '?');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post($unsignedUrl)
            ->assertForbidden()
            ->assertJson(['code' => 'invalid_signature']);

        $expiredUrl = URL::temporarySignedRoute('orders.payments.initiate', now()->subMinute(), ['public_id' => $order->public_id]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post($expiredUrl)
            ->assertForbidden()
            ->assertJson(['code' => 'invalid_signature']);
    }

    public function test_signed_status_returns_minimal_data_without_customer_information(): void
    {
        $order = $this->cardOrder();
        $url = URL::temporarySignedRoute('orders.payments.status', now()->addHour(), ['public_id' => $order->public_id]);

        $response = $this->get($url);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'orderPublicId', 'orderStatus', 'paymentStatus', 'paymentMethod',
                    'latestAttemptStatus', 'paidAt', 'paymentExpiresAt', 'retryable', 'resultCode',
                ],
            ])
            ->assertJsonPath('data.orderPublicId', $order->public_id)
            ->assertJsonPath('data.paymentStatus', PaymentStatus::Pending->value)
            ->assertJsonPath('data.latestAttemptStatus', null)
            ->assertJsonPath('data.resultCode', 'payment_not_started')
            ->assertJsonMissingPath('data.customer')
            ->assertJsonMissingPath('data.providerClientSecret')
            ->assertJsonMissingPath('data.id');

        $order->update(['payment_status' => PaymentStatus::Paid]);

        $this->get($url)
            ->assertOk()
            ->assertJsonPath('data.paymentStatus', PaymentStatus::Paid->value)
            ->assertJsonPath('data.resultCode', 'paid');
    }

    public function test_modified_signed_order_identifier_is_rejected(): void
    {
        $order = $this->cardOrder();
        $other = $this->cardOrder();
        $url = URL::temporarySignedRoute('orders.payments.status', now()->addHour(), ['public_id' => $order->public_id]);
        $modified = str_replace($order->public_id, $other->public_id, $url);

        $this->get($modified)
            ->assertForbidden()
            ->assertJson(['code' => 'invalid_signature']);
    }

    private function cardOrder(): Order
    {
        $order = Order::factory()->create([
            'customer_name' => 'Card Customer',
            'customer_email' => 'card@example.com',
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Pending,
            'subtotal' => '100.00',
            'shipping_fee' => '70.00',
            'total' => '170.00',
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => '100.00',
            'quantity' => 1,
            'line_total' => '100.00',
        ]);

        return $order;
    }

    private function walletOrder(): Order
    {
        $order = Order::factory()->create([
            'customer_name' => 'Wallet Customer',
            'customer_phone' => '01110007513',
            'customer_email' => 'wallet@example.com',
            'payment_method' => PaymentMethod::Wallet,
            'payment_status' => PaymentStatus::Pending,
            'subtotal' => '100.00',
            'shipping_fee' => '70.00',
            'total' => '170.00',
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => '100.00',
            'quantity' => 1,
            'line_total' => '100.00',
        ]);

        return $order;
    }
}
