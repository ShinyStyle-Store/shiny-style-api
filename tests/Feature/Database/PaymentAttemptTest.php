<?php

namespace Tests\Feature\Database;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\PaymentAttemptException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\SellableItem;
use App\Services\PaymentAttemptService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_attempt_relationships_and_casts_work(): void
    {
        $order = Order::factory()->create();
        $attempt = PaymentAttempt::create([
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => 'card',
            'status' => 'created',
            'amount_minor' => 17000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'card'),
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->assertTrue($attempt->order->is($order));
        $this->assertTrue($order->paymentAttempts->contains($attempt));
        $this->assertSame(PaymentAttemptStatus::Created, $attempt->status);
        $this->assertTrue(PaymentAttemptStatus::Created->isActive());
        $this->assertTrue(PaymentAttemptStatus::RequiresReview->isTerminal());
        $this->assertSame(PaymentMethod::Card, $attempt->method);
        $this->assertSame(17000, $attempt->amount_minor);
        $this->assertInstanceOf(Carbon::class, $attempt->expires_at);
    }

    public function test_attempt_identities_are_unique_but_multiple_null_provider_transactions_are_allowed(): void
    {
        $order = Order::factory()->create();
        $base = [
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => 'card',
            'status' => 'failed',
            'amount_minor' => 17000,
            'currency' => 'EGP',
            'request_fingerprint' => hash('sha256', 'card'),
        ];

        PaymentAttempt::create($base + [
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'provider_transaction_id' => 'txn-1',
        ]);
        PaymentAttempt::create($base + [
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->expectException(QueryException::class);
        PaymentAttempt::create($base + [
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'provider_transaction_id' => 'txn-1',
        ]);
    }

    public function test_merchant_reference_is_unique(): void
    {
        $order = Order::factory()->create();
        $reference = 'SS-PAY-'.Str::ulid();
        $idempotencyKey = (string) Str::uuid();
        $attributes = [
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => 'card',
            'status' => 'created',
            'amount_minor' => 17000,
            'currency' => 'EGP',
            'merchant_reference' => $reference,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', 'card'),
        ];

        PaymentAttempt::create($attributes);

        $this->expectException(QueryException::class);
        PaymentAttempt::create(array_merge($attributes, ['idempotency_key' => (string) Str::uuid()]));
    }

    public function test_idempotency_key_is_unique(): void
    {
        $order = Order::factory()->create();
        $attributes = [
            'order_id' => $order->getKey(),
            'provider' => 'paymob',
            'method' => 'card',
            'status' => 'created',
            'amount_minor' => 17000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'card'),
        ];

        PaymentAttempt::create($attributes);

        $this->expectException(QueryException::class);
        PaymentAttempt::create(array_merge($attributes, ['merchant_reference' => 'SS-PAY-'.Str::ulid()]));
    }

    public function test_service_creates_card_and_wallet_attempts_from_the_stored_total(): void
    {
        config(['payments.reservation_minutes' => 30]);
        Carbon::setTestNow('2026-09-26 12:00:00');

        $order = Order::factory()->create([
            'total' => '450.50',
            'payment_method' => PaymentMethod::Card,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        $service = app(PaymentAttemptService::class);

        $card = $service->create($order, PaymentMethod::Card, (string) Str::uuid());
        $this->assertSame('paymob', $card->provider->value);
        $this->assertSame(45050, $card->amount_minor);
        $this->assertSame('EGP', $card->currency);
        $this->assertSame('2026-09-26 12:30:00', $card->expires_at->toDateTimeString());

        $card->update(['status' => PaymentAttemptStatus::Failed]);
        $wallet = $service->create($order->fresh(), PaymentMethod::Wallet, (string) Str::uuid());
        $this->assertSame(PaymentMethod::Wallet, $wallet->method);
        $this->assertSame($card->expires_at->toDateTimeString(), $wallet->expires_at->toDateTimeString());
        $this->assertSame('2026-09-26 12:30:00', $order->fresh()->payment_expires_at->toDateTimeString());
        $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);

        Carbon::setTestNow();
    }

    public function test_service_reuses_idempotent_attempts_and_rejects_conflicts(): void
    {
        $order = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        $key = (string) Str::uuid();
        $service = app(PaymentAttemptService::class);

        $first = $service->create($order, PaymentMethod::Card, $key);
        $retry = $service->create($order->fresh(), PaymentMethod::Card, $key);

        $this->assertTrue($first->is($retry));
        $this->assertDatabaseCount('payment_attempts', 1);

        $this->expectException(IdempotencyConflictException::class);
        $service->create($order->fresh(), PaymentMethod::Wallet, $key);
    }

    public function test_idempotency_fingerprint_includes_order_identity(): void
    {
        $service = app(PaymentAttemptService::class);
        $firstOrder = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        $secondOrder = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        $key = (string) Str::uuid();

        $service->create($firstOrder, PaymentMethod::Card, $key);

        try {
            $service->create($secondOrder, PaymentMethod::Card, $key);
            $this->fail('An idempotency key must not be reused for another order.');
        } catch (IdempotencyConflictException $exception) {
            $this->assertSame('idempotency_conflict', $exception->errorCode);
        }
    }

    public function test_service_enforces_payment_eligibility_and_active_attempt_rules(): void
    {
        $service = app(PaymentAttemptService::class);

        $cod = Order::factory()->create();
        try {
            $service->create($cod, PaymentMethod::CashOnDelivery, (string) Str::uuid());
            $this->fail('COD should not create a payment attempt.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('unsupported_payment_method', $exception->errorCode);
        }

        $cancelled = Order::factory()->cancelled()->create();
        try {
            $service->create($cancelled, PaymentMethod::Card, (string) Str::uuid());
            $this->fail('Cancelled orders should not be payable.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('order_not_payable', $exception->errorCode);
        }

        $paid = Order::factory()->create(['payment_status' => PaymentStatus::Paid]);
        try {
            $service->create($paid, PaymentMethod::Card, (string) Str::uuid());
            $this->fail('Paid orders should not create another attempt.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('order_already_paid', $exception->errorCode);
        }

        $expired = Order::factory()->create(['payment_expires_at' => now()->subMinute()]);
        try {
            $service->create($expired, PaymentMethod::Card, (string) Str::uuid());
            $this->fail('Expired orders should not create an attempt.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('payment_window_expired', $exception->errorCode);
        }

        $active = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        $service->create($active, PaymentMethod::Card, (string) Str::uuid());
        try {
            $service->create($active->fresh(), PaymentMethod::Card, (string) Str::uuid());
            $this->fail('A second active attempt should be rejected.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('active_payment_attempt_exists', $exception->errorCode);
        }
    }

    public function test_null_payment_window_rejects_online_attempts_without_affecting_cod_orders(): void
    {
        $cod = Order::factory()->create();
        $codBefore = $cod->fresh();

        try {
            app(PaymentAttemptService::class)->create($cod, PaymentMethod::Card, (string) Str::uuid());
            $this->fail('An order without a payment window should not create an attempt.');
        } catch (PaymentAttemptException $exception) {
            $this->assertSame('order_not_payable', $exception->errorCode);
        }

        $codAfter = $cod->fresh();
        $this->assertNull($codAfter->payment_expires_at);
        $this->assertSame($codBefore->status, $codAfter->status);
        $this->assertSame($codBefore->payment_status, $codAfter->payment_status);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_failed_and_expired_attempts_can_retry_but_paid_and_review_attempts_cannot(): void
    {
        $service = app(PaymentAttemptService::class);

        foreach ([PaymentAttemptStatus::Failed, PaymentAttemptStatus::Expired] as $terminalStatus) {
            $order = Order::factory()->create([
                'payment_method' => PaymentMethod::Card,
                'payment_expires_at' => now()->addMinutes(30),
            ]);
            $first = $service->create($order, PaymentMethod::Card, (string) Str::uuid());
            $first->update(['status' => $terminalStatus]);

            $retry = $service->create($order->fresh(), PaymentMethod::Card, (string) Str::uuid());
            $this->assertNotSame($first->getKey(), $retry->getKey());
        }

        foreach ([PaymentAttemptStatus::Paid, PaymentAttemptStatus::RequiresReview] as $blockingStatus) {
            $order = Order::factory()->create([
                'payment_method' => PaymentMethod::Card,
                'payment_expires_at' => now()->addMinutes(30),
            ]);
            $attempt = $service->create($order, PaymentMethod::Card, (string) Str::uuid());
            $attempt->update(['status' => $blockingStatus]);

            try {
                $service->create($order->fresh(), PaymentMethod::Card, (string) Str::uuid());
                $this->fail('A paid or review attempt should block automatic retry.');
            } catch (PaymentAttemptException $exception) {
                $this->assertSame('payment_attempt_not_retryable', $exception->errorCode);
            }
        }
    }

    public function test_attempt_creation_does_not_change_order_or_inventory_state(): void
    {
        $product = Product::create([
            'slug' => 'payment-foundation-product-'.uniqid(),
            'name_ar' => 'منتج اختبار',
            'name_en' => 'Payment foundation product',
            'status' => 'active',
            'published_at' => now(),
        ]);
        $sellable = SellableItem::create([
            'product_id' => $product->getKey(),
            'sku' => 'PAYMENT-FOUNDATION-'.strtoupper(uniqid()),
            'price' => '100.00',
            'stock_quantity' => 8,
            'reserved_quantity' => 3,
            'status' => 'active',
            'is_default' => true,
        ]);
        $order = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
        $before = $order->fresh();

        app(PaymentAttemptService::class)->create($order, PaymentMethod::Card, (string) Str::uuid());

        $after = $order->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->payment_status, $after->payment_status);
        $this->assertSame($before->payment_expires_at->toDateTimeString(), $after->payment_expires_at->toDateTimeString());
        $this->assertSame(8, $sellable->fresh()->stock_quantity);
        $this->assertSame(3, $sellable->fresh()->reserved_quantity);
    }

    public function test_order_deletion_is_restricted_when_payment_history_exists(): void
    {
        $order = Order::factory()->create();
        $order->update(['payment_expires_at' => now()->addMinutes(30)]);
        app(PaymentAttemptService::class)->create($order, PaymentMethod::Card, (string) Str::uuid());

        $this->expectException(QueryException::class);
        $order->delete();
    }
}
