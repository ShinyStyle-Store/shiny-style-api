<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\PaymobTransaction;
use App\Services\PaymobTransactionWebhookService;
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
        $this->assertSame(PaymentStatus::Pending, $attempt->order->fresh()->payment_status);
    }

    public function test_success_callback_after_pending_callback_for_the_same_transaction_marks_payment_paid(): void
    {
        $attempt = $this->attempt();
        $pending = $this->transactionCallbackPayload($attempt, false);
        $pending['obj']['pending'] = true;
        $pending['obj']['success'] = false;
        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$this->hmac($pending['obj']), $pending)->assertOk();

        $success = $this->transactionCallbackPayload($attempt, true);
        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$this->hmac($success['obj']), $success)->assertOk();

        $this->assertSame(PaymentAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $attempt->order->fresh()->payment_status);
        $this->assertSame(OrderStatus::PendingConfirmation, $attempt->order->fresh()->status);
    }

    public function test_late_failure_from_previous_attempt_does_not_downgrade_newer_pending_payment(): void
    {
        $previous = $this->attempt();
        $order = $previous->order;
        $previous->update(['status' => PaymentAttemptStatus::Failed]);
        $order->update(['payment_status' => PaymentStatus::Pending]);
        $this->createNewerPendingAttempt($order);

        $failure = $this->transactionCallbackPayload($previous, false);
        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$this->hmac($failure['obj']), $failure)->assertOk();

        $this->assertSame(PaymentAttemptStatus::Failed, $previous->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_successful_earlier_attempt_is_recorded_even_when_a_newer_attempt_is_pending(): void
    {
        $previous = $this->attempt();
        $order = $previous->order;
        $previous->update(['status' => PaymentAttemptStatus::Failed]);
        $order->update(['payment_status' => PaymentStatus::Pending]);
        $this->createNewerPendingAttempt($order);

        $success = $this->transactionCallbackPayload($previous, true);
        $this->postJson('/api/v1/payments/paymob/webhook?hmac='.$this->hmac($success['obj']), $success)->assertOk();

        $this->assertSame(PaymentAttemptStatus::Paid, $previous->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);
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

    public function test_wallet_transaction_success_updates_payment_without_confirming_order(): void
    {
        $attempt = $this->walletAttempt();

        app(PaymobTransactionWebhookService::class)->process($this->walletTransaction($attempt, success: true));

        $this->assertSame(PaymentAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $attempt->order->fresh()->payment_status);
        $this->assertSame(OrderStatus::PendingConfirmation, $attempt->order->fresh()->status);
    }

    public function test_duplicate_wallet_success_and_failure_after_success_do_not_change_paid_state(): void
    {
        $attempt = $this->walletAttempt();
        $success = $this->walletTransaction($attempt, success: true);
        $service = app(PaymobTransactionWebhookService::class);

        $service->process($success);
        $paidAt = $attempt->fresh()->paid_at->toISOString();
        $service->process($success);
        $service->process($this->walletTransaction($attempt, errorOccurred: true));

        $this->assertSame(PaymentAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame($paidAt, $attempt->fresh()->paid_at->toISOString());
        $this->assertSame(PaymentStatus::Paid, $attempt->order->fresh()->payment_status);
    }

    public function test_wallet_failure_and_pending_are_applied_only_for_consistent_flags(): void
    {
        $failedAttempt = $this->walletAttempt();
        app(PaymobTransactionWebhookService::class)->process($this->walletTransaction($failedAttempt, errorOccurred: true));
        $this->assertSame(PaymentAttemptStatus::Failed, $failedAttempt->fresh()->status);
        $this->assertTrue($failedAttempt->fresh()->isDefinitiveFailure());
        $this->assertSame(PaymentStatus::Failed, $failedAttempt->order->fresh()->payment_status);

        $pendingAttempt = $this->walletAttempt();
        app(PaymobTransactionWebhookService::class)->process($this->walletTransaction($pendingAttempt, pending: true));
        $this->assertSame(PaymentAttemptStatus::Pending, $pendingAttempt->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $pendingAttempt->order->fresh()->payment_status);

        $inconsistentAttempt = $this->walletAttempt();
        app(PaymobTransactionWebhookService::class)->process($this->walletTransaction(
            $inconsistentAttempt,
            success: true,
            errorOccurred: true,
        ));
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $inconsistentAttempt->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $inconsistentAttempt->order->fresh()->payment_status);
    }

    public function test_wallet_mismatched_merchant_reference_and_old_success_require_review(): void
    {
        $mismatchedAttempt = $this->walletAttempt();
        app(PaymobTransactionWebhookService::class)->process($this->walletTransaction(
            $mismatchedAttempt,
            success: true,
            merchantOrderId: 'different-reference',
        ));
        $this->assertSame(PaymentAttemptStatus::RequiresReview, $mismatchedAttempt->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $mismatchedAttempt->order->fresh()->payment_status);

        $oldAttempt = $this->walletAttempt();
        $order = $oldAttempt->order;
        $oldAttempt->update(['status' => PaymentAttemptStatus::Failed]);
        $order->update(['payment_status' => PaymentStatus::Pending]);
        $this->createNewerWalletPendingAttempt($order);

        app(PaymobTransactionWebhookService::class)->process($this->walletTransaction($oldAttempt, success: true));

        $this->assertSame(PaymentAttemptStatus::RequiresReview, $oldAttempt->fresh()->status);
        $this->assertSame('late_wallet_success_with_newer_attempt', $oldAttempt->fresh()->failure_code);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
    }

    public function test_wallet_method_integration_amount_and_currency_mismatches_never_mark_paid(): void
    {
        $cases = [
            ['transaction' => ['integrationId' => 790]],
            ['transaction' => ['amountMinor' => 17001]],
            ['transaction' => ['currency' => 'USD']],
            ['order_method' => PaymentMethod::Card],
        ];

        foreach ($cases as $case) {
            $attempt = $this->walletAttempt();
            $transactionOverrides = $case['transaction'] ?? [];
            if (isset($case['order_method'])) {
                $attempt->order->update(['payment_method' => $case['order_method']]);
            }

            app(PaymobTransactionWebhookService::class)->process($this->walletTransaction(
                $attempt,
                success: true,
                integrationId: $transactionOverrides['integrationId'] ?? null,
                amountMinor: $transactionOverrides['amountMinor'] ?? null,
                currency: $transactionOverrides['currency'] ?? null,
            ));

            $this->assertSame(PaymentAttemptStatus::RequiresReview, $attempt->fresh()->status);
            $this->assertSame(PaymentStatus::Unpaid, $attempt->order->fresh()->payment_status);
        }
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

    private function walletAttempt(): PaymentAttempt
    {
        $order = Order::factory()->create([
            'payment_method' => PaymentMethod::Wallet,
            'payment_expires_at' => now()->addMinutes(30),
            'total' => '170.00',
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        return PaymentAttempt::create([
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => PaymentMethod::Wallet,
            'status' => PaymentAttemptStatus::Pending,
            'amount_minor' => 17000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'wallet'),
            'provider_order_id' => (string) (980000000 + $order->getKey()),
            'integration_id' => 789,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function createNewerWalletPendingAttempt(Order $order): PaymentAttempt
    {
        return PaymentAttempt::create([
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => PaymentMethod::Wallet,
            'status' => PaymentAttemptStatus::Pending,
            'amount_minor' => 17000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'wallet-newer'),
            'provider_order_id' => (string) (990000000 + $order->getKey()),
            'integration_id' => 789,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function walletTransaction(
        PaymentAttempt $attempt,
        bool $success = false,
        bool $pending = false,
        bool $errorOccurred = false,
        ?string $merchantOrderId = null,
        ?int $integrationId = null,
        ?int $amountMinor = null,
        ?string $currency = null,
    ): PaymobTransaction {
        return new PaymobTransaction(
            transactionId: 123456790 + (int) $attempt->getKey(),
            providerOrderId: (int) $attempt->provider_order_id,
            integrationId: $integrationId ?? (int) $attempt->integration_id,
            amountMinor: $amountMinor ?? $attempt->amount_minor,
            currency: $currency ?? $attempt->currency,
            success: $success,
            pending: $pending,
            errorOccurred: $errorOccurred,
            refunded: false,
            voided: false,
            sourceType: '',
            sourceSubtype: '',
            merchantOrderId: $merchantOrderId ?? $attempt->merchant_reference,
            createdAt: null,
            updatedAt: null,
        );
    }

    private function createNewerPendingAttempt(Order $order): PaymentAttempt
    {
        return PaymentAttempt::create([
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => 'card',
            'status' => PaymentAttemptStatus::Pending,
            'amount_minor' => 17000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'card-newer'),
            'provider_order_id' => '987654322',
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
