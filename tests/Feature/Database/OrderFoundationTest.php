<?php

namespace Tests\Feature\Database;

use App\Enums\CancellationReason;
use App\Enums\ContactStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_identifiers_are_generated_and_explicit_values_are_preserved(): void
    {
        $first = Order::factory()->create();
        $second = Order::factory()->create();
        $explicit = Order::factory()->create([
            'public_id' => '01JORDERPUBLIC000000000000',
            'order_number' => 'SS-CONTROLLED',
        ]);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $first->public_id);
        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertSame('SS-'.str_pad((string) $first->id, 6, '0', STR_PAD_LEFT), $first->order_number);
        $this->assertNotSame($first->order_number, $second->order_number);
        $this->assertSame('SS-CONTROLLED', $explicit->order_number);
        $this->assertSame('SS-1000000', Order::formatOrderNumber(1000000));
    }

    public function test_guest_and_user_orders_have_safe_user_delete_behavior(): void
    {
        $guest = Order::factory()->guest()->create();
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertNull($guest->user_id);
        $this->assertTrue($order->user->is($user));

        $user->delete();

        $this->assertNull($order->refresh()->user_id);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_shipping_relationship_is_restrictive_and_snapshots_are_independent(): void
    {
        $area = ShippingArea::factory()->create([
            'code' => 'cairo',
            'name_ar' => 'القاهرة',
            'name_en' => 'Cairo',
        ]);
        $order = Order::factory()->create([
            'shipping_area_id' => $area->id,
            'shipping_area_code' => $area->code,
            'shipping_area_name_ar' => $area->name_ar,
            'shipping_area_name_en' => $area->name_en,
        ]);

        $this->assertTrue($order->shippingArea->is($area));
        $area->update(['name_ar' => 'اسم جديد', 'name_en' => 'New Name']);
        $this->assertSame('القاهرة', $order->refresh()->shipping_area_name_ar);
        $this->assertSame('Cairo', $order->shipping_area_name_en);

        $this->expectException(QueryException::class);
        $area->delete();
    }

    public function test_order_items_cascade_and_source_deletions_preserve_history(): void
    {
        $order = Order::factory()->create();
        $product = $this->createProduct();
        $item = SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'ORDER-SKU',
            'price' => '125.50',
            'stock_quantity' => 4,
            'status' => 'active',
            'is_default' => true,
        ]);
        $orderItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $item->id,
            'options_snapshot' => [[
                'option_name_ar' => 'اللون',
                'option_name_en' => 'Color',
                'value_ar' => 'بيج',
                'value_en' => 'Beige',
            ]],
        ]);

        $this->assertTrue($order->items->contains(fn (OrderItem $value): bool => $value->is($orderItem)));
        $product->forceDelete();

        $this->assertNull($orderItem->refresh()->product_id);
        $this->assertNull($orderItem->sellable_item_id);
        $this->assertSame('Beige', $orderItem->options_snapshot[0]['value_en']);

        $remainingOrderItem = OrderItem::factory()->create(['order_id' => $order->id]);
        $order->delete();
        $this->assertDatabaseMissing('order_items', ['id' => $orderItem->id]);
        $this->assertDatabaseMissing('order_items', ['id' => $remainingOrderItem->id]);
    }

    public function test_enum_money_timestamp_and_snapshot_casts_are_applied(): void
    {
        $order = Order::factory()->cancelled()->create([
            'status' => OrderStatus::Confirmed,
            'contact_status' => ContactStatus::Responded,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Paid,
            'cancellation_reason' => CancellationReason::CustomerCancelled,
            'subtotal' => '125.50',
            'shipping_fee' => '70.00',
            'total' => '195.50',
            'confirmed_at' => now(),
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'unit_price' => '125.50',
            'line_total' => '251.00',
            'quantity' => '2',
            'options_snapshot' => [],
        ]);

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(ContactStatus::Responded, $order->contact_status);
        $this->assertSame(PaymentMethod::CashOnDelivery, $order->payment_method);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(CancellationReason::CustomerCancelled, $order->cancellation_reason);
        $this->assertSame('125.50', $order->subtotal);
        $this->assertSame('70.00', $order->shipping_fee);
        $this->assertSame('195.50', $order->total);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $order->confirmed_at);
        $this->assertSame([], $item->options_snapshot);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('125.50', $item->unit_price);
    }

    public function test_reserved_quantity_defaults_and_available_quantity_do_not_change_stock(): void
    {
        $product = $this->createProduct();
        $item = SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'RESERVED-SKU',
            'price' => '100.00',
            'stock_quantity' => 10,
            'status' => 'active',
            'is_default' => true,
        ]);
        $item->refresh();

        $this->assertSame(0, $item->reserved_quantity);
        $item->reserved_quantity = 3;
        $item->refresh();
        $this->assertSame(10, $item->stock_quantity);
        $this->assertSame(10, $item->availableQuantity());

        $item->update(['reserved_quantity' => 3]);
        $item->refresh();
        $this->assertSame(7, $item->availableQuantity());
        $this->assertSame(10, $item->stock_quantity);
    }

    private function createProduct(): Product
    {
        $product = Product::create([
            'slug' => 'order-product-'.uniqid(),
            'name_ar' => 'منتج طلب',
            'name_en' => 'Order Product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $category = Category::create([
            'slug' => 'order-category-'.uniqid(),
            'name_ar' => 'تصنيف طلب',
            'name_en' => 'Order Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);

        return $product;
    }
}
