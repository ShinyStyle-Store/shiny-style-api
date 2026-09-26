<?php

namespace Tests\Feature\Database;

use App\Enums\CancellationReason;
use App\Enums\ContactStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderLifecycleException;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellableItem;
use App\Services\OrderLifecycleService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_allowed_transitions_set_timestamps_and_preserve_inventory_when_expected(): void
    {
        Carbon::setTestNow('2026-09-18 10:00:00');
        [$order, $item] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3);
        $service = app(OrderLifecycleService::class);

        $confirmed = $service->transition($order, OrderStatus::Confirmed);
        $this->assertSame(OrderStatus::Confirmed, $confirmed->status);
        $this->assertSame(ContactStatus::Responded, $confirmed->contact_status);
        $this->assertSame(PaymentStatus::Unpaid, $confirmed->payment_status);
        $this->assertSame('2026-09-18 10:00:00', $confirmed->confirmed_at->toDateTimeString());
        $this->assertSame('2026-09-18 10:00:00', $confirmed->last_contacted_at->toDateTimeString());
        $this->assertInventory($item, stock: 10, reserved: 3);

        Carbon::setTestNow('2026-09-18 10:01:00');
        $preparing = $service->transition($confirmed, OrderStatus::Preparing);
        $this->assertSame(OrderStatus::Preparing, $preparing->status);
        $this->assertSame(PaymentStatus::Unpaid, $preparing->payment_status);
        $this->assertSame('2026-09-18 10:01:00', $preparing->preparing_at->toDateTimeString());
        $this->assertInventory($item, stock: 10, reserved: 3);

        Carbon::setTestNow('2026-09-18 10:02:00');
        $shipped = $service->transition($preparing, OrderStatus::Shipped);
        $this->assertSame(OrderStatus::Shipped, $shipped->status);
        $this->assertSame(PaymentStatus::Unpaid, $shipped->payment_status);
        $this->assertSame('2026-09-18 10:02:00', $shipped->shipped_at->toDateTimeString());
        $this->assertInventory($item, stock: 7, reserved: 0);

        Carbon::setTestNow('2026-09-18 10:03:00');
        $delivered = $service->transition($shipped, OrderStatus::Delivered);
        $this->assertSame(OrderStatus::Delivered, $delivered->status);
        $this->assertSame(PaymentStatus::Paid, $delivered->payment_status);
        $this->assertSame('2026-09-18 10:03:00', $delivered->delivered_at->toDateTimeString());
        $this->assertInventory($item, stock: 7, reserved: 0);
    }

    public function test_confirmed_can_ship_without_preparing(): void
    {
        [$order, $item] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3);
        $service = app(OrderLifecycleService::class);

        $confirmed = $service->transition($order, OrderStatus::Confirmed);
        $shipped = $service->transition($confirmed, OrderStatus::Shipped);

        $this->assertSame(OrderStatus::Shipped, $shipped->status);
        $this->assertInventory($item, stock: 7, reserved: 0);
    }

    public function test_unpaid_online_order_cannot_be_confirmed_but_paid_online_order_can(): void
    {
        $service = app(OrderLifecycleService::class);
        $unpaid = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Pending,
            'payment_expires_at' => now()->addMinutes(30),
        ]);

        try {
            $service->transition($unpaid, OrderStatus::Confirmed);
            $this->fail('An unpaid online order must not be confirmed.');
        } catch (InvalidOrderLifecycleException $exception) {
            $this->assertSame('Online payment is required before confirmation.', $exception->getMessage());
        }

        $paid = Order::factory()->create([
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Paid,
            'payment_expires_at' => now()->addMinutes(30),
        ]);

        $this->assertSame(OrderStatus::Confirmed, $service->transition($paid, OrderStatus::Confirmed)->status);
    }

    public function test_already_paid_cod_order_can_be_delivered_and_repeated_delivery_is_idempotent(): void
    {
        Carbon::setTestNow('2026-09-19 15:00:00');
        [$order, $item] = $this->orderWithReservation(
            stock: 7,
            reserved: 0,
            quantity: 3,
            status: OrderStatus::Shipped,
        );
        $order->update([
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Paid,
        ]);

        $service = app(OrderLifecycleService::class);
        $delivered = $service->transition($order, OrderStatus::Delivered);
        $deliveredAt = $delivered->delivered_at?->toISOString();
        $this->assertSame(PaymentStatus::Paid, $delivered->payment_status);
        $this->assertInventory($item, stock: 7, reserved: 0);

        Carbon::setTestNow('2026-09-19 15:10:00');
        $repeated = $service->transition($delivered, OrderStatus::Delivered);
        $this->assertSame(PaymentStatus::Paid, $repeated->payment_status);
        $this->assertSame($deliveredAt, $repeated->delivered_at?->toISOString());
        $this->assertInventory($item, stock: 7, reserved: 0);
    }

    public function test_cod_delivery_rejects_incompatible_payment_states_without_mutations(): void
    {
        foreach ([PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Refunded] as $paymentStatus) {
            [$order, $item] = $this->orderWithReservation(
                stock: 7,
                reserved: 0,
                quantity: 3,
                status: OrderStatus::Shipped,
            );
            $order->update([
                'payment_method' => PaymentMethod::CashOnDelivery,
                'payment_status' => $paymentStatus,
                'shipped_at' => '2026-09-19 14:00:00',
            ]);

            try {
                app(OrderLifecycleService::class)->transition($order, OrderStatus::Delivered);
                $this->fail('An inconsistent COD payment state must block delivery.');
            } catch (InvalidOrderLifecycleException) {
                $unchanged = $order->fresh();
                $this->assertSame(OrderStatus::Shipped, $unchanged->status);
                $this->assertSame($paymentStatus, $unchanged->payment_status);
                $this->assertNull($unchanged->delivered_at);
                $this->assertInventory($item, stock: 7, reserved: 0);
            }
        }
    }

    public function test_repeating_delivery_does_not_repair_a_delivered_unpaid_cod_order(): void
    {
        [$order, $item] = $this->orderWithReservation(
            stock: 7,
            reserved: 0,
            quantity: 3,
            status: OrderStatus::Delivered,
        );
        $order->update([
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Unpaid,
            'delivered_at' => '2026-09-19 14:00:00',
        ]);
        $deliveredAt = $order->fresh()->delivered_at?->toISOString();

        try {
            app(OrderLifecycleService::class)->transition($order, OrderStatus::Delivered);
            $this->fail('A delivered but unpaid COD order must not be silently repaired.');
        } catch (InvalidOrderLifecycleException) {
            $unchanged = $order->fresh();
            $this->assertSame(OrderStatus::Delivered, $unchanged->status);
            $this->assertSame(PaymentStatus::Unpaid, $unchanged->payment_status);
            $this->assertSame($deliveredAt, $unchanged->delivered_at?->toISOString());
            $this->assertInventory($item, stock: 7, reserved: 0);
        }
    }

    public function test_confirmation_preserves_an_existing_contact_time_and_note(): void
    {
        Carbon::setTestNow('2026-09-18 12:00:00');
        [$order] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3);
        $order->update([
            'last_contacted_at' => '2026-09-18 11:45:00',
            'contact_note' => 'Customer confirmed by phone',
        ]);

        $confirmed = app(OrderLifecycleService::class)->transition($order, OrderStatus::Confirmed);

        $this->assertSame('2026-09-18 11:45:00', $confirmed->last_contacted_at->toDateTimeString());
        $this->assertSame('Customer confirmed by phone', $confirmed->contact_note);
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transitions_leave_order_and_inventory_unchanged(OrderStatus $initial, OrderStatus $target): void
    {
        [$order, $item] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3, status: $initial);
        $before = $order->fresh();

        $this->expectException(InvalidOrderLifecycleException::class);
        try {
            app(OrderLifecycleService::class)->transition($order, $target);
        } finally {
            $after = $order->fresh();
            $this->assertSame($before->status, $after->status);
            $this->assertSame($before->confirmed_at?->toISOString(), $after->confirmed_at?->toISOString());
            $this->assertSame($before->preparing_at?->toISOString(), $after->preparing_at?->toISOString());
            $this->assertSame($before->shipped_at?->toISOString(), $after->shipped_at?->toISOString());
            $this->assertSame($before->delivered_at?->toISOString(), $after->delivered_at?->toISOString());
            $this->assertInventory($item, stock: 10, reserved: 3);
        }
    }

    public static function forbiddenTransitions(): array
    {
        return [
            'pending to preparing' => [OrderStatus::PendingConfirmation, OrderStatus::Preparing],
            'pending to shipped' => [OrderStatus::PendingConfirmation, OrderStatus::Shipped],
            'pending to delivered' => [OrderStatus::PendingConfirmation, OrderStatus::Delivered],
            'preparing to confirmed' => [OrderStatus::Preparing, OrderStatus::Confirmed],
            'shipped to cancelled' => [OrderStatus::Shipped, OrderStatus::Cancelled],
            'delivered to confirmed' => [OrderStatus::Delivered, OrderStatus::Confirmed],
            'cancelled to confirmed' => [OrderStatus::Cancelled, OrderStatus::Confirmed],
        ];
    }

    public function test_repeated_transitions_do_not_rewrite_timestamps_or_inventory(): void
    {
        $service = app(OrderLifecycleService::class);

        Carbon::setTestNow('2026-09-18 11:00:00');
        [$pending, $pendingItem] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3);
        $confirmed = $service->transition($pending, OrderStatus::Confirmed);
        $confirmedAt = $confirmed->confirmed_at?->toISOString();
        $this->assertSame($confirmedAt, $service->transition($confirmed, OrderStatus::Confirmed)->confirmed_at?->toISOString());
        $this->assertInventory($pendingItem, stock: 10, reserved: 3);

        Carbon::setTestNow('2026-09-18 11:01:00');
        [$confirmedOrder, $preparingItem] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3, status: OrderStatus::Confirmed);
        $preparing = $service->transition($confirmedOrder, OrderStatus::Preparing);
        $preparingAt = $preparing->preparing_at?->toISOString();
        $this->assertSame($preparingAt, $service->transition($preparing, OrderStatus::Preparing)->preparing_at?->toISOString());
        $this->assertInventory($preparingItem, stock: 10, reserved: 3);

        Carbon::setTestNow('2026-09-18 11:02:00');
        [$confirmedForShipping, $shippingItem] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3, status: OrderStatus::Confirmed);
        $shipped = $service->transition($confirmedForShipping, OrderStatus::Shipped);
        $shippedAt = $shipped->shipped_at?->toISOString();
        $this->assertSame($shippedAt, $service->transition($shipped, OrderStatus::Shipped)->shipped_at?->toISOString());
        $this->assertInventory($shippingItem, stock: 7, reserved: 0);

        Carbon::setTestNow('2026-09-18 11:03:00');
        [$confirmedForDelivery, $deliveryItem] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3, status: OrderStatus::Confirmed);
        $shippedForDelivery = $service->transition($confirmedForDelivery, OrderStatus::Shipped);
        $delivered = $service->transition($shippedForDelivery, OrderStatus::Delivered);
        $deliveredAt = $delivered->delivered_at?->toISOString();
        $repeatedDelivery = $service->transition($delivered, OrderStatus::Delivered);
        $this->assertSame($deliveredAt, $repeatedDelivery->delivered_at?->toISOString());
        $this->assertSame(PaymentStatus::Paid, $repeatedDelivery->payment_status);
        $this->assertInventory($deliveryItem, stock: 7, reserved: 0);
    }

    public function test_repeated_cancellation_requires_matching_reason_and_note(): void
    {
        $order = Order::factory()->cancelled()->create(['cancellation_note' => 'Duplicate request']);
        $service = app(OrderLifecycleService::class);
        $before = $order->cancelled_at;

        $after = $service->transition($order, OrderStatus::Cancelled, CancellationReason::CustomerCancelled, 'Duplicate request');
        $this->assertSame($before?->toISOString(), $after->cancelled_at?->toISOString());

        $this->expectException(InvalidOrderLifecycleException::class);
        $service->transition($order, OrderStatus::Cancelled, CancellationReason::Other, 'Duplicate request');
    }

    public function test_cancellation_requires_reason_and_other_requires_non_empty_trimmed_note(): void
    {
        $service = app(OrderLifecycleService::class);
        $order = Order::factory()->create();

        try {
            $service->transition($order, OrderStatus::Cancelled);
            $this->fail('Cancellation reason must be required.');
        } catch (InvalidOrderLifecycleException) {
            $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);
        }

        try {
            $service->transition($order, OrderStatus::Cancelled, CancellationReason::Other, '   ');
            $this->fail('Whitespace-only cancellation notes must be rejected.');
        } catch (InvalidOrderLifecycleException) {
            $this->assertSame(OrderStatus::PendingConfirmation, $order->fresh()->status);
        }

        $cancelled = $service->transition($order, OrderStatus::Cancelled, CancellationReason::Other, '  العميل طلب الإلغاء  ');
        $this->assertSame('العميل طلب الإلغاء', $cancelled->cancellation_note);
    }

    public function test_cancellation_metadata_is_rejected_for_non_cancel_targets(): void
    {
        $order = Order::factory()->create();

        $this->expectException(InvalidOrderLifecycleException::class);
        app(OrderLifecycleService::class)->transition($order, OrderStatus::Confirmed, CancellationReason::Other, 'Not a cancellation');
    }

    public function test_no_response_does_not_cancel_or_release_reservation(): void
    {
        [$order, $item] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3);
        $updated = app(OrderLifecycleService::class)->markNoResponse($order);

        $this->assertSame(ContactStatus::NoResponse, $updated->contact_status);
        $this->assertSame(OrderStatus::PendingConfirmation, $updated->status);
        $this->assertInventory($item, stock: 10, reserved: 3);
    }

    public function test_exact_stock_and_reservation_boundary_succeeds(): void
    {
        [$order, $item] = $this->orderWithReservation(stock: 3, reserved: 3, quantity: 3, status: OrderStatus::Confirmed);
        $service = app(OrderLifecycleService::class);
        $shipped = $service->transition($order, OrderStatus::Shipped);

        $this->assertSame(OrderStatus::Shipped, $shipped->status);
        $this->assertInventory($item, stock: 0, reserved: 0);
    }

    public function test_insufficient_stock_rolls_back_order_and_inventory(): void
    {
        [$order, $item] = $this->orderWithReservation(stock: 2, reserved: 3, quantity: 3, status: OrderStatus::Confirmed);

        $this->expectException(InvalidOrderLifecycleException::class);
        try {
            app(OrderLifecycleService::class)->transition($order, OrderStatus::Shipped);
        } finally {
            $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
            $this->assertInventory($item, stock: 2, reserved: 3);
        }
    }

    public function test_insufficient_reservation_rolls_back_shipping_and_cancellation(): void
    {
        [$order, $item] = $this->orderWithReservation(stock: 10, reserved: 2, quantity: 3, status: OrderStatus::Confirmed);
        $service = app(OrderLifecycleService::class);

        try {
            $service->transition($order, OrderStatus::Shipped);
            $this->fail('Shipping must reject insufficient reservation.');
        } catch (InvalidOrderLifecycleException) {
            $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
            $this->assertInventory($item, stock: 10, reserved: 2);
        }

        [$pending, $pendingItem] = $this->orderWithReservation(stock: 10, reserved: 2, quantity: 3);
        try {
            $service->transition($pending, OrderStatus::Cancelled, CancellationReason::Other, 'Inventory mismatch');
            $this->fail('Cancellation must reject insufficient reservation.');
        } catch (InvalidOrderLifecycleException) {
            $this->assertSame(OrderStatus::PendingConfirmation, $pending->fresh()->status);
            $this->assertInventory($pendingItem, stock: 10, reserved: 2);
        }
    }

    public function test_failure_on_second_item_rolls_back_first_item_mutation(): void
    {
        [$order, $first, $second] = $this->orderWithMultipleItems([
            ['stock' => 10, 'reserved' => 2, 'quantity' => 2],
            ['stock' => 1, 'reserved' => 1, 'quantity' => 2],
        ], OrderStatus::Confirmed);

        $this->expectException(InvalidOrderLifecycleException::class);
        try {
            app(OrderLifecycleService::class)->transition($order, OrderStatus::Shipped);
        } finally {
            $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
            $this->assertInventory($first, stock: 10, reserved: 2);
            $this->assertInventory($second, stock: 1, reserved: 1);
        }
    }

    public function test_duplicate_order_items_are_aggregated_by_sellable_item(): void
    {
        [$order, $item] = $this->orderWithReservation(stock: 10, reserved: 5, quantity: 2);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $item->product_id,
            'sellable_item_id' => $item->id,
            'quantity' => 3,
        ]);

        $service = app(OrderLifecycleService::class);
        $service->transition($order, OrderStatus::Confirmed);
        $service->transition($order->fresh(), OrderStatus::Shipped);

        $this->assertInventory($item, stock: 5, reserved: 0);
    }

    public function test_invalid_historical_quantity_fails_safely_or_is_rejected_by_database(): void
    {
        $order = Order::factory()->create();

        try {
            $item = OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 0]);
        } catch (QueryException) {
            $this->assertDatabaseMissing('order_items', ['order_id' => $order->id, 'quantity' => 0]);

            return;
        }

        $this->expectException(InvalidOrderLifecycleException::class);
        $order->update(['status' => OrderStatus::Confirmed]);
        app(OrderLifecycleService::class)->transition($order->fresh(), OrderStatus::Shipped);
        $this->assertDatabaseHas('order_items', ['id' => $item->id, 'quantity' => 0]);
    }

    public function test_null_sellable_reference_fails_safely_for_shipping_and_cancellation(): void
    {
        $service = app(OrderLifecycleService::class);

        $cancellationOrder = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $cancellationOrder->id, 'sellable_item_id' => null]);
        try {
            $service->transition($cancellationOrder, OrderStatus::Cancelled, CancellationReason::Other, 'Missing reference');
            $this->fail('A null sellable reference must be rejected for cancellation.');
        } catch (InvalidOrderLifecycleException) {
            $afterCancellation = $cancellationOrder->fresh();
            $this->assertSame(OrderStatus::PendingConfirmation, $afterCancellation->status);
            $this->assertNull($afterCancellation->cancellation_reason);
            $this->assertNull($afterCancellation->cancellation_note);
            $this->assertNull($afterCancellation->cancelled_at);
        }

        [$shippingOrder, $shippingItem] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3, status: OrderStatus::Confirmed);
        $shippingOrderItem = $shippingOrder->items()->first();
        $shippingOrderItem->update(['sellable_item_id' => null]);
        try {
            $service->transition($shippingOrder, OrderStatus::Shipped);
            $this->fail('A null sellable reference must be rejected for shipping.');
        } catch (InvalidOrderLifecycleException) {
            $afterShipping = $shippingOrder->fresh();
            $this->assertSame(OrderStatus::Confirmed, $afterShipping->status);
            $this->assertNull($afterShipping->shipped_at);
            $this->assertInventory($shippingItem, stock: 10, reserved: 3);
        }
    }

    public function test_soft_deleted_sellable_can_release_reservation_and_ship(): void
    {
        [$cancelledOrder, $cancelledItem] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3);
        $cancelledItem->delete();
        app(OrderLifecycleService::class)->transition($cancelledOrder, OrderStatus::Cancelled, CancellationReason::Other, 'Removed item');
        $this->assertInventory($cancelledItem, stock: 10, reserved: 0);

        [$shippedOrder, $shippedItem] = $this->orderWithReservation(stock: 10, reserved: 3, quantity: 3, status: OrderStatus::Confirmed);
        $shippedItem->delete();
        app(OrderLifecycleService::class)->transition($shippedOrder, OrderStatus::Shipped);
        $this->assertInventory($shippedItem, stock: 7, reserved: 0);
    }

    public function test_item_snapshots_remain_unchanged_after_transitions(): void
    {
        [$order, $item] = $this->orderWithReservation(stock: 10, reserved: 2, quantity: 2);
        $orderItem = $order->items()->first();
        $snapshot = $orderItem->only(['sku', 'product_name_ar', 'product_name_en', 'options_snapshot', 'unit_price', 'quantity', 'line_total']);

        $service = app(OrderLifecycleService::class);
        $service->transition($order, OrderStatus::Confirmed);
        $service->transition($order->fresh(), OrderStatus::Shipped);

        $this->assertSame($snapshot, $orderItem->fresh()->only(array_keys($snapshot)));
        $this->assertInventory($item, stock: 8, reserved: 0);
    }

    /** @return array{Order, SellableItem} */
    private function orderWithReservation(int $stock, int $reserved, int $quantity, OrderStatus $status = OrderStatus::PendingConfirmation): array
    {
        [$product, $item] = $this->createSellable($stock, $reserved);
        $order = Order::factory()->create(['status' => $status]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $item->id,
            'quantity' => $quantity,
            'unit_price' => '100.00',
            'line_total' => (string) ($quantity * 100).'.00',
        ]);

        return [$order, $item];
    }

    /** @param list<array{stock: int, reserved: int, quantity: int}> $definitions @return array{Order, SellableItem, SellableItem} */
    private function orderWithMultipleItems(array $definitions, OrderStatus $status): array
    {
        $order = Order::factory()->create(['status' => $status]);
        $items = [];
        foreach ($definitions as $definition) {
            [$product, $item] = $this->createSellable($definition['stock'], $definition['reserved']);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'sellable_item_id' => $item->id,
                'quantity' => $definition['quantity'],
            ]);
            $items[] = $item;
        }

        return [$order, $items[0], $items[1]];
    }

    /** @return array{Product, SellableItem} */
    private function createSellable(int $stock, int $reserved): array
    {
        $product = Product::create([
            'slug' => 'lifecycle-product-'.uniqid(),
            'name_ar' => 'منتج دورة حياة',
            'name_en' => 'Lifecycle Product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $category = Category::create([
            'slug' => 'lifecycle-category-'.uniqid(),
            'name_ar' => 'تصنيف دورة حياة',
            'name_en' => 'Lifecycle Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);

        $item = SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'LIFECYCLE-'.uniqid(),
            'price' => '100.00',
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$product, $item];
    }

    private function assertInventory(SellableItem $item, int $stock, int $reserved): void
    {
        $fresh = $item->newQueryWithoutScopes()->findOrFail($item->id);
        $this->assertSame($stock, $fresh->stock_quantity);
        $this->assertSame($reserved, $fresh->reserved_quantity);
        $this->assertGreaterThanOrEqual(0, $fresh->stock_quantity);
        $this->assertGreaterThanOrEqual(0, $fresh->reserved_quantity);
    }
}
