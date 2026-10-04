<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Models\AdminMembership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Models\SellableItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_requires_admin_authentication(): void
    {
        $this->getJson('/api/v1/admin/dashboard/overview')->assertUnauthorized();

        $customerToken = User::factory()->create()->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($customerToken)->getJson('/api/v1/admin/dashboard/overview')->assertForbidden();
    }

    public function test_empty_dashboard_uses_expected_zero_values(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'Africa/Cairo'));

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.period.timezone', 'Africa/Cairo')
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.metrics.orders_created_this_month', 0)
            ->assertJsonPath('data.metrics.delivered_orders', 0)
            ->assertJsonPath('data.metrics.delivered_order_value', '0.00')
            ->assertJsonPath('data.metrics.average_delivered_order_value', '0.00')
            ->assertJsonPath('data.metrics.active_products', 0)
            ->assertJsonPath('data.metrics.unavailable_active_products', 0)
            ->assertJsonPath('data.currency_totals', [])
            ->assertJsonPath('data.top_selling_products', [])
            ->assertJsonPath('data.orders_chart.days', 7)
            ->assertJsonPath('data.orders_chart.timezone', 'Africa/Cairo')
            ->assertJsonCount(7, 'data.orders_chart.points');
    }

    public function test_month_boundaries_delivered_cohort_and_average_are_exact(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'Africa/Cairo'));
        $periodStartUtc = Carbon::parse('2026-10-01 00:00:00', 'Africa/Cairo')->utc();

        Order::factory()->create([
            'created_at' => $periodStartUtc->copy()->subSecond(),
            'status' => OrderStatus::PendingConfirmation,
        ]);
        $first = $this->order([
            'created_at' => $periodStartUtc,
            'status' => OrderStatus::Delivered,
            'delivered_at' => $periodStartUtc,
            'total' => '100.01',
        ]);
        $second = $this->order([
            'created_at' => Carbon::parse('2026-10-02 00:00:00', 'Africa/Cairo')->utc(),
            'status' => OrderStatus::Delivered,
            'delivered_at' => Carbon::parse('2026-10-03 10:00:00', 'Africa/Cairo')->utc(),
            'total' => '101.00',
        ]);
        $this->order([
            'created_at' => Carbon::parse('2026-10-02 00:00:00', 'Africa/Cairo')->utc(),
            'status' => OrderStatus::Cancelled,
            'total' => '999.00',
        ]);
        $this->order([
            'created_at' => Carbon::parse('2026-10-02 00:00:00', 'Africa/Cairo')->utc(),
            'status' => OrderStatus::Delivered,
            'delivered_at' => Carbon::parse('2026-09-30 23:59:59', 'Africa/Cairo')->utc(),
            'total' => '500.00',
        ]);

        OrderItem::factory()->create(['order_id' => $first->id, 'quantity' => 1]);
        OrderItem::factory()->create(['order_id' => $second->id, 'quantity' => 1]);

        foreach ([7, 30] as $chartDays) {
            $this->withToken($this->adminToken)->getJson('/api/v1/admin/dashboard/overview?chart_days='.$chartDays)
                ->assertOk()
                ->assertJsonPath('data.metrics.orders_created_this_month', 4)
                ->assertJsonPath('data.metrics.delivered_orders', 2)
                ->assertJsonPath('data.metrics.delivered_order_value', '201.01')
                ->assertJsonPath('data.metrics.average_delivered_order_value', '100.51')
                ->assertJsonPath('data.currency', 'EGP')
                ->assertJsonPath('data.orders_chart.days', $chartDays);
        }
    }

    public function test_orders_chart_defaults_to_seven_days_and_zero_fills_all_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'Africa/Cairo'));
        $cairo = 'Africa/Cairo';
        $this->order(['created_at' => Carbon::parse('2026-10-04 10:00:00', $cairo)->utc(), 'status' => OrderStatus::PendingConfirmation]);
        $this->order(['created_at' => Carbon::parse('2026-10-03 23:00:00', $cairo)->utc(), 'status' => OrderStatus::Cancelled]);
        $this->order(['created_at' => Carbon::parse('2026-10-02 08:00:00', $cairo)->utc(), 'status' => OrderStatus::Delivered]);
        $this->order(['created_at' => Carbon::parse('2026-10-04 12:00:01', $cairo)->utc(), 'status' => OrderStatus::Shipped]);
        $this->order(['created_at' => Carbon::parse('2026-09-04 12:00:00', $cairo)->utc(), 'status' => OrderStatus::Delivered]);

        $points = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.orders_chart.days', 7)
            ->assertJsonPath('data.orders_chart.start_date', '2026-09-28')
            ->assertJsonPath('data.orders_chart.end_date', '2026-10-04')
            ->json('data.orders_chart.points');

        $this->assertSame([
            ['date' => '2026-09-28', 'orders' => 0],
            ['date' => '2026-09-29', 'orders' => 0],
            ['date' => '2026-09-30', 'orders' => 0],
            ['date' => '2026-10-01', 'orders' => 0],
            ['date' => '2026-10-02', 'orders' => 1],
            ['date' => '2026-10-03', 'orders' => 1],
            ['date' => '2026-10-04', 'orders' => 1],
        ], $points);
    }

    public function test_orders_chart_supports_thirty_days_and_rejects_other_ranges(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'Africa/Cairo'));

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/dashboard/overview?chart_days=30')
            ->assertOk()
            ->assertJsonPath('data.orders_chart.days', 30)
            ->assertJsonPath('data.orders_chart.start_date', '2026-09-05')
            ->assertJsonPath('data.orders_chart.end_date', '2026-10-04');

        $points = $response->json('data.orders_chart.points');
        $this->assertCount(30, $points);
        $this->assertSame('2026-09-05', $points[0]['date']);
        $this->assertSame('2026-10-04', $points[29]['date']);

        foreach (['6', '14', '7.0', '7,30'] as $chartDays) {
            $this->withToken($this->adminToken)
                ->getJson('/api/v1/admin/dashboard/overview?chart_days='.$chartDays)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('chart_days');
        }
    }

    public function test_orders_chart_uses_cairo_midnight_boundaries_across_daylight_saving_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'Africa/Cairo'));
        $cairo = 'Africa/Cairo';
        $this->order(['created_at' => Carbon::parse('2026-10-29 23:59:59', $cairo)->utc(), 'status' => OrderStatus::PendingConfirmation]);
        $this->order(['created_at' => Carbon::parse('2026-10-30 00:00:00', $cairo)->utc(), 'status' => OrderStatus::Cancelled]);
        $this->order(['created_at' => Carbon::parse('2026-10-31 00:00:00', $cairo)->utc(), 'status' => OrderStatus::Delivered]);

        $points = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/dashboard/overview')
            ->assertOk()
            ->json('data.orders_chart.points');

        $this->assertSame(1, collect($points)->firstWhere('date', '2026-10-29')['orders']);
        $this->assertSame(1, collect($points)->firstWhere('date', '2026-10-30')['orders']);
        $this->assertSame(1, collect($points)->firstWhere('date', '2026-10-31')['orders']);
    }

    public function test_multiple_delivered_currencies_are_not_combined(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'Africa/Cairo'));
        foreach (['EGP' => '100.00', 'USD' => '20.00'] as $currency => $total) {
            $order = $this->order([
                'currency' => $currency,
                'status' => OrderStatus::Delivered,
                'delivered_at' => now()->utc(),
                'total' => $total,
            ]);
            OrderItem::factory()->create(['order_id' => $order->id]);
        }

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.currency', null)
            ->assertJsonPath('data.metrics.delivered_orders', 2)
            ->assertJsonPath('data.metrics.delivered_order_value', null)
            ->assertJsonPath('data.metrics.average_delivered_order_value', null)
            ->assertJsonCount(2, 'data.currency_totals')
            ->assertJsonPath('data.currency_totals.0.currency', 'EGP')
            ->assertJsonPath('data.currency_totals.1.currency', 'USD');
    }

    public function test_catalog_counts_use_current_active_product_and_available_variant_state(): void
    {
        $available = $this->product('Available');
        $this->variant($available, ['stock_quantity' => 10, 'reserved_quantity' => 2]);

        $reserved = $this->product('Reserved');
        $this->variant($reserved, ['stock_quantity' => 2, 'reserved_quantity' => 2]);

        $inactiveVariant = $this->product('Inactive variant');
        $this->variant($inactiveVariant, ['status' => 'inactive', 'stock_quantity' => 10, 'reserved_quantity' => 0]);

        $deletedVariant = $this->product('Deleted variant');
        $this->variant($deletedVariant, ['stock_quantity' => 10, 'reserved_quantity' => 0])->delete();

        $deletedProduct = $this->product('Deleted product');
        $this->variant($deletedProduct, ['stock_quantity' => 10, 'reserved_quantity' => 0]);
        $deletedProduct->delete();

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.active_products', 4)
            ->assertJsonPath('data.metrics.unavailable_active_products', 3);
    }

    public function test_top_products_aggregate_variants_apply_effective_and_reversed_returns_and_localize_names(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 12:00:00', 'Africa/Cairo'));
        $products = [];
        for ($index = 1; $index <= 6; $index++) {
            $products[$index] = $this->product("Product {$index}", "منتج {$index}");
        }

        $first = $this->order(['status' => OrderStatus::Delivered, 'delivered_at' => now()->utc()]);
        $second = $this->order(['status' => OrderStatus::Delivered, 'delivered_at' => now()->utc()]);
        $items = [
            $this->item($first, $products[1], 3),
            $this->item($second, $products[1], 2),
            $this->item($first, $products[2], 4),
            $this->item($first, $products[3], 3),
            $this->item($first, $products[4], 2),
            $this->item($first, $products[5], 1),
            $this->item($first, $products[6], 6),
        ];
        $this->receipt($first, $items[0], 2);
        $this->receipt($first, $items[2], 4, now()->utc());

        $deleted = $products[6];
        $deleted->delete();
        $historicalOrder = $this->order(['status' => OrderStatus::Delivered, 'delivered_at' => now()->utc()]);
        $historicalItem = $this->item($historicalOrder, null, 7, 'Historical product', 'منتج تاريخي');

        $response = $this->withToken($this->adminToken)->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/dashboard/overview')
            ->assertOk()
            ->assertJsonPath('data.top_selling_products.0.historical_order_item_id', $historicalItem->id)
            ->assertJsonPath('data.top_selling_products.0.product_id', null)
            ->assertJsonPath('data.top_selling_products.0.net_units_sold', 7)
            ->assertJsonPath('data.top_selling_products.0.name', 'منتج تاريخي')
            ->assertJsonPath('data.top_selling_products.1.product_id', $products[6]->id)
            ->assertJsonPath('data.top_selling_products.1.net_units_sold', 6)
            ->assertJsonPath('data.top_selling_products.2.product_id', $products[2]->id)
            ->assertJsonPath('data.top_selling_products.2.net_units_sold', 4)
            ->assertJsonPath('data.top_selling_products.3.product_id', $products[1]->id)
            ->assertJsonPath('data.top_selling_products.3.net_units_sold', 3)
            ->assertJsonPath('data.top_selling_products.4.product_id', $products[3]->id)
            ->assertJsonPath('data.top_selling_products.4.net_units_sold', 3);

        $this->assertCount(5, $response->json('data.top_selling_products'));
        $thirtyDayResponse = $this->withToken($this->adminToken)->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/dashboard/overview?chart_days=30')
            ->assertOk();
        $this->assertSame(
            $response->json('data.top_selling_products'),
            $thirtyDayResponse->json('data.top_selling_products'),
        );
        $this->assertNotNull($deleted->id);
    }

    /** @param array<string, mixed> $attributes */
    private function order(array $attributes): Order
    {
        return Order::factory()->create(array_merge([
            'created_at' => now()->utc(),
            'currency' => 'EGP',
            'total' => '170.00',
        ], $attributes));
    }

    private function product(string $nameEn, ?string $nameAr = null): Product
    {
        return Product::query()->create([
            'slug' => Str::slug($nameEn).'-'.Str::random(6),
            'name_ar' => $nameAr ?? $nameEn,
            'name_en' => $nameEn,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function variant(Product $product, array $attributes = []): SellableItem
    {
        return SellableItem::query()->create(array_merge([
            'product_id' => $product->id,
            'sku' => 'SKU-'.Str::random(8),
            'price' => '10.00',
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
            'sort_order' => 1,
        ], $attributes));
    }

    private function item(Order $order, ?Product $product, int $quantity, ?string $nameEn = null, ?string $nameAr = null): OrderItem
    {
        return OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product?->id,
            'sellable_item_id' => $product?->sellableItems()->first()?->id,
            'sku' => 'ORDER-'.Str::random(8),
            'product_name_ar' => $nameAr ?? $product?->name_ar ?? 'Historical',
            'product_name_en' => $nameEn ?? $product?->name_en ?? 'Historical',
            'options_snapshot' => [],
            'unit_price' => '10.00',
            'quantity' => $quantity,
            'line_total' => (string) ($quantity * 10).'.00',
        ]);
    }

    private function receipt(Order $order, OrderItem $item, int $received, ?Carbon $reversedAt = null): void
    {
        $receipt = ReturnReceipt::query()->create([
            'order_id' => $order->id,
            'received_at' => now()->subDay(),
            'idempotency_key' => Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::uuid()),
            'reversed_at' => $reversedAt,
        ]);
        ReturnReceiptItem::query()->create([
            'return_receipt_id' => $receipt->id,
            'order_item_id' => $item->id,
            'sellable_item_id' => $item->sellable_item_id,
            'original_quantity' => $item->quantity,
            'initial_received_quantity' => $received,
            'initial_restockable_quantity' => 0,
            'initial_reason' => 'other',
            'effective_received_quantity' => $received,
            'effective_restockable_quantity' => 0,
            'effective_reason' => 'other',
        ]);
    }
}
