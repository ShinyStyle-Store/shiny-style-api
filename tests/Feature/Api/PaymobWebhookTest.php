<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymobWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paymob.hmac_secret' => 'test-hmac-secret',
            'services.paymob.card_integration_id' => '456',
        ]);
    }

    public function test_invalid_hmac_does_not_mutate_the_attempt(): void
    {
        $attempt = $this->attempt();
        $payload = $this->transactionCallbackPayload($attempt, true);

        $response = $this->withHeader('Content-Type', 'application/json')
            ->postJson('/api/v1/payments/paymob/webhook?hmac='.str_repeat('0', 128), $payload);

        $response->assertStatus(401);
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->order->fresh()->payment_status);
    }

    public function test_matching_success_marks_payment_paid_but_does_not_confirm_order(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['provider_order_id' => '987654321']);
        $payload = $this->transactionCallbackPayload($attempt, true);
        $hmac = $this->hmac($payload['obj']);

        $response = $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$hmac, $payload);
        $response->assertOk()->assertJson(['received' => true]);

        $stored = $attempt->fresh();
        $this->assertSame(PaymentAttemptStatus::Paid, $stored->status);
        $this->assertNotNull($stored->paid_at);
        $this->assertSame(PaymentStatus::Paid, $stored->order->payment_status);
        $this->assertSame(OrderStatus::PendingConfirmation, $stored->order->status);
        $this->assertSame('123456789', $stored->provider_transaction_id);
    }

    public function test_duplicate_success_and_failure_after_paid_do_not_downgrade_state(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['provider_order_id' => '987654321']);
        $success = $this->transactionCallbackPayload($attempt, true);
        $successHmac = $this->hmac($success['obj']);
        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$successHmac, $success)->assertOk();
        $paidAt = $attempt->fresh()->paid_at->toDateTimeString();

        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$successHmac, $success)->assertOk();
        $this->assertSame($paidAt, $attempt->fresh()->paid_at->toDateTimeString());
    }

    public function test_pending_callback_does_not_mark_order_paid(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['provider_order_id' => '987654321']);
        $payload = $this->transactionCallbackPayload($attempt, false);
        $payload['obj']['pending'] = true;
        $payload['obj']['success'] = false;
        $hmac = $this->hmac($payload['obj']);

        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$hmac, $payload)->assertOk();
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->order->fresh()->payment_status);
    }

    public function test_wrong_amount_requires_review_and_never_marks_paid(): void
    {
        $attempt = $this->attempt();
        $attempt->update(['provider_order_id' => '987654321']);
        $payload = $this->transactionCallbackPayload($attempt, true);
        $payload['obj']['amount_cents'] = 17001;

        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$this->hmac($payload['obj']), $payload)->assertOk();
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->order->fresh()->payment_status);
    }

    public function test_unknown_provider_order_is_acknowledged_without_creating_records(): void
    {
        $payload = $this->transactionCallbackPayload(null, true);

        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$this->hmac($payload['obj']), $payload)
            ->assertStatus(202);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_unsupported_callback_type_is_rejected_without_mutation(): void
    {
        $response = $this->postJson('/api/v1/payments/paymob/webhook?hmac='.str_repeat('0', 128), [
            'type' => 'TOKEN',
            'obj' => [],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_success_for_a_cancelled_order_requires_review(): void
    {
        $attempt = $this->attempt();
        $attempt->order->update(['status' => OrderStatus::Cancelled]);
        $payload = $this->transactionCallbackPayload($attempt, true);

        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$this->hmac($payload['obj']), $payload)->assertOk();
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->order->fresh()->payment_status);
    }

    private function attempt(): PaymentAttempt
    {
        $order = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_expires_at' => now()->addMinutes(30),
            'total' => '170.00',
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        return PaymentAttempt::create([
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => 'card',
            'status' => 'pending',
            'amount_minor' => 17000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'card'),
            'provider_order_id' => '987654321',
            'integration_id' => 456,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /** @return array{type:string,obj:array<string,mixed>} */
    private function transactionCallbackPayload(?PaymentAttempt $attempt, bool $success): array
    {
        return [
            'type' => 'TRANSACTION',
            'obj' => [
                'id' => 123456789,
                'pending' => false,
                'amount_cents' => 17000,
                'success' => $success,
                'is_auth' => false,
                'is_capture' => false,
                'is_standalone_payment' => true,
                'is_voided' => false,
                'is_refunded' => false,
                'is_3d_secure' => true,
                'integration_id' => 456,
                'has_parent_transaction' => false,
                'order' => ['id' => 987654321],
                'created_at' => '2026-09-26T12:00:00',
                'currency' => 'EGP',
                'owner' => 654,
                'error_occured' => ! $success,
                'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            ],
        ];
    }

    /** @param array<string,mixed> $object */
    private function hmac(array $object): string
    {
        $source = $object['source_data'];
        $values = [
            $object['amount_cents'], $object['created_at'], $object['currency'], $object['error_occured'],
            $object['has_parent_transaction'], $object['id'], $object['integration_id'], $object['is_3d_secure'],
            $object['is_auth'], $object['is_capture'], $object['is_refunded'], $object['is_standalone_payment'],
            $object['is_voided'], $object['order']['id'], $object['owner'], $object['pending'], $source['pan'],
            $source['sub_type'], $source['type'], $object['success'],
        ];
        $string = implode('', array_map(static fn (mixed $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $values));
        return hash_hmac('sha512', $string, 'test-hmac-secret');
    }
}
