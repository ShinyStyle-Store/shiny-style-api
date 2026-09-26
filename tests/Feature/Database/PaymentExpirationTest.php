<?php

namespace Tests\Feature\Database;

use App\Enums\CancellationReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderLifecycleException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\SellableItem;
use App\Services\PaymentExpirationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class PaymentExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_online_order_releases_reservation_and_expires_active_attempt(): void
    {
        [$order, $item] = $this->orderWithReservation(PaymentAttemptStatus::Created);

        $result = app(PaymentExpirationService::class)->expire($order);

        $this->assertSame('expired', $result->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(CancellationReason::PaymentExpired, $order->fresh()->cancellation_reason);
        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->assertSame(0, $item->fresh()->reserved_quantity);
        $this->assertSame(PaymentAttemptStatus::Expired, $order->paymentAttempts()->first()->status);
    }

    public function test_expiration_is_idempotent_and_does_not_rewrite_cancelled_at(): void
    {
        [$order, $item] = $this->orderWithReservation(PaymentAttemptStatus::Pending);
        $service = app(PaymentExpirationService::class);
        $service->expire($order);
        $cancelledAt = $order->fresh()->cancelled_at->toISOString();

        $result = $service->expire($order->fresh());

        $this->assertSame('skipped', $result->status);
        $this->assertSame($cancelledAt, $order->fresh()->cancelled_at->toISOString());
        $this->assertSame(0, $item->fresh()->reserved_quantity);
    }

    public function test_cod_paid_and_future_orders_are_skipped(): void
    {
        $cod = Order::factory()->create([
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_expires_at' => now()->subMinute(),
        ]);
        $paid = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Paid,
            'payment_expires_at' => now()->subMinute(),
        ]);
        $future = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_expires_at' => now()->addMinute(),
        ]);
        $service = app(PaymentExpirationService::class);

        $this->assertSame('skipped', $service->expire($cod)->status);
        $this->assertSame('skipped', $service->expire($paid)->status);
        $this->assertSame('skipped', $service->expire($future)->status);
        $this->assertSame(OrderStatus::PendingConfirmation, $cod->fresh()->status);
        $this->assertSame(OrderStatus::PendingConfirmation, $paid->fresh()->status);
        $this->assertSame(OrderStatus::PendingConfirmation, $future->fresh()->status);
    }

    public function test_requires_review_and_submitting_attempts_preserve_order_and_stock(): void
    {
        foreach ([PaymentAttemptStatus::RequiresReview, PaymentAttemptStatus::Submitting] as $status) {
            [$order, $item] = $this->orderWithReservation($status);

            $result = app(PaymentExpirationService::class)->expire($order);

            $this->assertSame('review-required', $result->status);
            $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);
            $this->assertSame(3, $item->fresh()->reserved_quantity);
        }
    }

    public function test_corrupt_reservation_rolls_back_order_and_attempt_changes(): void
    {
        [$order, $item] = $this->orderWithReservation(PaymentAttemptStatus::Created, reserved: 1, quantity: 3);
        $beforeOrder = $order->fresh();
        $beforeAttempt = $order->paymentAttempts()->firstOrFail()->fresh();
        $beforeItem = $item->fresh();

        try {
            app(PaymentExpirationService::class)->expire($order);
            $this->fail('A corrupt reservation must abort expiration.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(InvalidOrderLifecycleException::class, $exception);
            $this->assertSame('Sellable item reservation is insufficient.', $exception->getMessage());
        }

        $afterOrder = $order->fresh();
        $afterAttempt = $beforeAttempt->fresh();
        $afterItem = $item->fresh();

        $this->assertSame($beforeOrder->status, $afterOrder->status);
        $this->assertNull($afterOrder->cancelled_at);
        $this->assertSame($beforeOrder->payment_status, $afterOrder->payment_status);
        $this->assertSame($beforeAttempt->status, $afterAttempt->status);
        $this->assertSame(
            $beforeAttempt->expires_at?->toISOString(),
            $afterAttempt->expires_at?->toISOString(),
        );
        $this->assertSame($beforeItem->reserved_quantity, $afterItem->reserved_quantity);
        $this->assertSame($beforeItem->stock_quantity, $afterItem->stock_quantity);
    }

    private function orderWithReservation(PaymentAttemptStatus $status, int $reserved = 3, int $quantity = 3): array
    {
        $product = Product::create([
            'slug' => 'expiration-product-'.uniqid(),
            'name_ar' => 'منتج انتهاء',
            'name_en' => 'Expiration Product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $item = SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'EXPIRATION-'.strtoupper(uniqid()),
            'price' => '100.00',
            'stock_quantity' => 10,
            'reserved_quantity' => $reserved,
            'status' => 'active',
            'is_default' => true,
        ]);
        $order = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Unpaid,
            'payment_expires_at' => now()->subMinute(),
            'subtotal' => '300.00',
            'shipping_fee' => '70.00',
            'total' => '370.00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'line_total' => (string) ($quantity * 100).'.00',
        ]);
        PaymentAttempt::create([
            'order_id' => $order->id,
            'provider' => 'paymob',
            'method' => 'card',
            'status' => $status,
            'amount_minor' => 37000,
            'currency' => 'EGP',
            'merchant_reference' => 'SS-PAY-'.Str::ulid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'expiration'),
            'expires_at' => now()->subMinute(),
        ]);

        return [$order, $item];
    }
}
