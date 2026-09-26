<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ], 201)]);
        $url = URL::temporarySignedRoute('orders.payments.initiate', now()->addMinutes(5), ['public_id' => $order->public_id]);
        $key = (string) Str::uuid();

        $first = $this->withHeader('Idempotency-Key', $key)->post($url);
        $first->assertCreated()->assertJsonStructure(['paymentAttemptId', 'status', 'checkoutUrl', 'expiresAt']);
        $second = $this->withHeader('Idempotency-Key', $key)->post($url);
        $second->assertOk()->assertJson(['status' => 'pending']);

        $this->assertSame($first->json('checkoutUrl'), $second->json('checkoutUrl'));
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('test-secret', $first->getContent());
        $this->assertStringContainsString('client-secret', $first->json('checkoutUrl'));
    }

    public function test_signed_initiation_accepts_an_empty_json_object(): void
    {
        $order = $this->cardOrder();
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int-public-empty-object',
            'client_secret' => 'client-secret-empty-object',
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
    }

    public function test_reusing_a_key_after_provider_rejection_conflicts_but_a_new_key_can_retry(): void
    {
        $order = $this->cardOrder();
        Http::fake(['https://paymob.test/*' => Http::sequence()
            ->push(['detail' => 'Invalid request'], 422)
            ->push(['id' => 'int-retry', 'client_secret' => 'client-secret-retry'], 201)]);
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
            ->assertJsonPath('status', 'pending');

        Http::assertSentCount(2);
        $this->assertDatabaseCount('payment_attempts', 2);
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
            ->assertJsonPath('orderPublicId', $order->public_id)
            ->assertJsonPath('paymentStatus', PaymentStatus::Pending->value)
            ->assertJsonMissingPath('customer')
            ->assertJsonMissingPath('providerClientSecret')
            ->assertJsonMissingPath('id');
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
}
