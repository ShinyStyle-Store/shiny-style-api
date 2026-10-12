<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\ContactStatus;
use App\Enums\MediaRole;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\AdminMembership;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RefundRecord;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bearer_can_read_list_and_detail_and_session_auth_is_rejected(): void
    {
        $order = Order::factory()->has(OrderItem::factory()->count(2), 'items')->create();
        $token = $this->adminToken();

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $this->withToken('invalid-token')->getJson('/api/v1/admin/orders')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/orders')->assertOk()
            ->assertJsonPath('data.0.public_id', $order->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk();
    }

    public function test_invalid_customer_and_inactive_membership_tokens_follow_existing_forbidden_behavior(): void
    {
        $customer = User::factory()->create();
        $customerToken = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $this->withToken($customerToken)->getJson('/api/v1/admin/orders')->assertForbidden();

        $this->forgetAuthGuards();
        $admin = User::factory()->create();
        $membership = AdminMembership::factory()->create(['user_id' => $admin->id]);
        $adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
        $membership->update(['status' => AdminMembershipStatus::Suspended]);
        $this->forgetAuthGuards();
        $this->withToken($adminToken)->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    public function test_list_rejects_unknown_and_invalid_query_values(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/v1/admin/orders?unknown=x')->assertUnprocessable()->assertJsonValidationErrors('unknown');
        $this->withToken($token)->getJson('/api/v1/admin/orders?status=&sort=recent&per_page=101')->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'sort', 'per_page']);
        $this->withToken($token)->getJson('/api/v1/admin/orders?per_page=0&page=0')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'page']);
        $this->withToken($token)->getJson('/api/v1/admin/orders?contact_status=&payment_method=&payment_status=&date_from=&page=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['contact_status', 'payment_method', 'payment_status', 'date_from', 'page']);
        $this->withToken($token)->getJson('/api/v1/admin/orders?status=made_up&date_from=2026-02-02&date_to=2026-02-01')
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'date_to']);
        $this->withToken($token)->getJson('/api/v1/admin/orders?date_from=2026-02-30&date_to=2026-01-01')->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);
        $this->withToken($token)->getJson('/api/v1/admin/orders?shipping_area_id=99999')->assertUnprocessable()
            ->assertJsonValidationErrors('shipping_area_id');
        $this->withToken($token)->getJson('/api/v1/admin/orders?return_status=partial&refund_status=confirmed')->assertUnprocessable()
            ->assertJsonValidationErrors(['return_status', 'refund_status']);
    }

    public function test_list_paginates_deterministically_applies_filters_and_keeps_query_string(): void
    {
        $area = ShippingArea::factory()->create();
        $older = Order::factory()->create([
            'shipping_area_id' => $area->id,
            'created_at' => '2026-09-01 10:00:00',
            'status' => OrderStatus::Confirmed,
            'contact_status' => ContactStatus::Responded,
            'payment_status' => PaymentStatus::Paid,
            'customer_name' => 'Arabic Customer',
        ]);
        OrderItem::factory()->create(['order_id' => $older->id, 'quantity' => 3]);
        OrderItem::factory()->create(['order_id' => $older->id, 'quantity' => 2]);
        $newer = Order::factory()->create([
            'shipping_area_id' => $area->id,
            'created_at' => '2026-09-02 10:00:00',
            'status' => OrderStatus::Confirmed,
            'customer_name' => 'English Customer',
        ]);
        OrderItem::factory()->create(['order_id' => $newer->id]);
        Order::factory()->create(['status' => OrderStatus::Cancelled]);

        $token = $this->adminToken();
        $response = $this->withToken($token)->getJson('/api/v1/admin/orders?status=confirmed&shipping_area_id='.$area->id.'&sort=oldest&per_page=1&page=1');
        $response->assertOk()->assertJsonPath('data.0.public_id', $older->public_id)
            ->assertJsonPath('data.0.item_count', 2)
            ->assertJsonPath('data.0.total_quantity', 5)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);
        $this->assertStringContainsString('status=confirmed', $response->json('links.next'));
        $this->assertStringContainsString('shipping_area_id='.$area->id, $response->json('links.next'));
        $this->assertArrayNotHasKey('id', $response->json('data.0'));
        $this->assertArrayNotHasKey('items', $response->json('data.0'));
        $this->assertArrayNotHasKey('user_id', $response->json('data.0'));
    }

    public function test_each_supported_enum_and_shipping_area_filter_is_applied(): void
    {
        $area = ShippingArea::factory()->create();
        $otherArea = ShippingArea::factory()->create();
        $target = Order::factory()->create([
            'shipping_area_id' => $area->id,
            'status' => OrderStatus::Shipped,
            'contact_status' => ContactStatus::NoResponse,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $nonMatching = Order::factory()->create([
            'shipping_area_id' => $otherArea->id,
            'status' => OrderStatus::Cancelled,
            'contact_status' => ContactStatus::Responded,
            'payment_status' => PaymentStatus::Unpaid,
        ]);
        $token = $this->adminToken();

        $filters = [
            ['query' => 'status=shipped', 'path' => 'status', 'expected' => 'shipped', 'exclude_alternative' => true],
            ['query' => 'contact_status=no_response', 'path' => 'contact_status', 'expected' => 'no_response', 'exclude_alternative' => true],
            ['query' => 'payment_method=cash_on_delivery', 'path' => 'payment_method', 'expected' => 'cash_on_delivery', 'exclude_alternative' => false],
            ['query' => 'payment_status=paid', 'path' => 'payment_status', 'expected' => 'paid', 'exclude_alternative' => true],
            ['query' => 'shipping_area_id='.$area->id, 'path' => 'shipping_area.id', 'expected' => $area->id, 'exclude_alternative' => true],
        ];

        foreach ($filters as $filter) {
            $response = $this->withToken($token)->getJson('/api/v1/admin/orders?'.$filter['query'].'&per_page=100');
            $response->assertOk();

            $data = $response->json('data');
            $publicIds = array_column($data, 'public_id');
            $this->assertContains($target->public_id, $publicIds);

            foreach ($data as $row) {
                $this->assertSame($filter['expected'], data_get($row, $filter['path']));
            }

            if ($filter['exclude_alternative']) {
                $this->assertNotContains($nonMatching->public_id, $publicIds);
            }
        }
    }

    public function test_newest_default_uses_id_as_a_stable_tie_breaker(): void
    {
        $first = Order::factory()->create(['created_at' => '2026-09-01 10:00:00']);
        $second = Order::factory()->create(['created_at' => '2026-09-01 10:00:00']);
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/v1/admin/orders')
            ->assertOk()->assertJsonPath('data.0.public_id', $second->public_id);
        $this->assertLessThan($second->id, $first->id);
    }

    public function test_list_searches_order_customer_and_normalized_phone_and_composes_with_filters(): void
    {
        $match = Order::factory()->create([
            'order_number' => 'SS-SEARCH-241',
            'customer_name' => 'Mona Search',
            'customer_phone' => '01110007513',
            'status' => OrderStatus::Confirmed,
        ]);
        Order::factory()->create(['customer_name' => 'Mona Search', 'status' => OrderStatus::Cancelled]);
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/v1/admin/orders?search=SEARCH-24&status=confirmed')
            ->assertOk()->assertJsonPath('data.0.public_id', $match->public_id);
        $arabic = Order::factory()->create(['customer_name' => 'منى عميلة']);
        $this->withToken($token)->getJson('/api/v1/admin/orders?search='.urlencode('منى عميلة'))
            ->assertOk()->assertJsonPath('data.0.public_id', $arabic->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/orders?search=%2B20%201110007513')
            ->assertOk()->assertJsonPath('data.0.public_id', $match->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/orders?search=%20%20')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_search_wildcards_are_literal_and_dates_include_the_selected_days(): void
    {
        $literal = Order::factory()->create([
            'customer_name' => '100% Cotton_Item\\Blue',
            'created_at' => '2026-09-10 00:00:00',
        ]);
        Order::factory()->create(['customer_name' => '100X CottonYItemZBlue', 'created_at' => '2026-09-11 23:59:59']);
        Order::factory()->create(['created_at' => '2026-09-12 00:00:00']);
        $token = $this->adminToken();

        $this->withToken($token)->getJson('/api/v1/admin/orders?search=100%25%20Cotton%5FItem%5CBlue')
            ->assertOk()->assertJsonPath('data.0.public_id', $literal->public_id)->assertJsonPath('meta.total', 1);
        $this->withToken($token)->getJson('/api/v1/admin/orders?date_from=2026-09-10&date_to=2026-09-11&sort=oldest')
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_detail_uses_public_id_and_returns_localized_historical_snapshots_and_exact_money(): void
    {
        $order = Order::factory()->create([
            'shipping_area_name_ar' => 'المنطقة القديمة',
            'shipping_area_name_en' => 'Historical Area',
            'customer_note' => 'Keep this text as entered',
            'contact_note' => 'Call after noon',
            'subtotal' => '123.40',
            'shipping_fee' => '8.05',
            'total' => '131.45',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_name_ar' => 'اسم تاريخي',
            'product_name_en' => 'Historical Product',
            'sku' => 'HIST-1',
            'unit_price' => '61.70',
            'quantity' => 2,
            'line_total' => '123.40',
            'options_snapshot' => [[
                'option_name_ar' => 'اللون', 'option_name_en' => 'Color',
                'value_ar' => 'أحمر', 'value_en' => 'Red',
            ]],
        ]);
        $token = $this->adminToken();

        $this->withToken($token)->withHeader('Accept-Language', 'en-US')
            ->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk()
            ->assertJsonPath('data.shipping.area.name', 'Historical Area')
            ->assertJsonPath('data.items.0.product_name', 'Historical Product')
            ->assertJsonPath('data.items.0.selected_options.0.name', 'Color')
            ->assertJsonPath('data.items.0.unit_price', '61.70')
            ->assertJsonPath('data.shipping.shipping_fee', '8.05')
            ->assertJsonPath('data.total', '131.45')
            ->assertJsonPath('data.order_note', 'Keep this text as entered');
        $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/admin/orders/not-a-ulid')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/admin/orders/01J00000000000000000000000')->assertNotFound();
    }

    public function test_detail_item_ids_are_order_item_ids_and_can_create_a_physical_return(): void
    {
        $order = Order::factory()->shipped()->create(['shipped_at' => now()->subHour()]);
        $items = OrderItem::factory()->count(2)->create(['order_id' => $order->id, 'quantity' => 1]);
        $token = $this->adminToken();

        $detail = $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk();
        $detail->assertJsonPath('data.items.0.id', $items[0]->id)
            ->assertJsonPath('data.items.1.id', $items[1]->id);

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', [
                'reason' => 'refused_delivery',
            ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $return = $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [
                'default_reason' => 'changed_mind',
                'items' => [
                    ['order_item_id' => $items[0]->id, 'received_quantity' => 1, 'restock_quantity' => 0],
                    ['order_item_id' => $items[1]->id, 'received_quantity' => 1, 'restock_quantity' => 0],
                ],
            ])->assertCreated();
        $return->assertJsonPath('data.items.0.order_item_id', $items[0]->id)
            ->assertJsonPath('data.items.1.order_item_id', $items[1]->id);
    }

    public function test_foreign_return_item_is_rejected_without_mutation(): void
    {
        $product = Product::create([
            'slug' => 'admin-order-read-foreign-return-product',
            'name_ar' => 'Ù…Ù†ØªØ¬ Ø§Ù„Ø¥Ø±Ø¬Ø§Ø¹ Ø§Ù„Ø£Ø¬Ù†Ø¨ÙŠ',
            'name_en' => 'Foreign Return Product',
        ]);
        $sellable = SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'ADMIN-READ-FOREIGN-RETURN',
            'price' => '10.00',
            'stock_quantity' => 5,
            'reserved_quantity' => 1,
        ]);
        $order = Order::factory()->shipped()->create(['shipped_at' => now()->subHour()]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 1,
            'sellable_item_id' => $sellable->id,
        ]);
        $foreignOrder = Order::factory()->shipped()->create(['shipped_at' => now()->subHour()]);
        $foreignItem = OrderItem::factory()->create([
            'order_id' => $foreignOrder->id,
            'quantity' => 1,
            'sellable_item_id' => $sellable->id,
        ]);
        $token = $this->adminToken();
        $stockBefore = $sellable->stock_quantity;
        $reservedBefore = $sellable->reserved_quantity;

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/refuse-delivery', [
                'reason' => 'refused_delivery',
            ])->assertCreated()->assertJsonPath('data.status', 'waiting_for_return');

        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/admin/orders/'.$order->public_id.'/returns', [
                'default_reason' => 'changed_mind',
                'items' => [['order_item_id' => $foreignItem->id, 'received_quantity' => 1, 'restock_quantity' => 0]],
            ])->assertStatus(409)->assertJsonPath('code', 'return_full_order_items_required');

        $this->assertDatabaseCount('return_receipts', 0);
        $this->assertDatabaseCount('return_receipt_items', 0);
        $this->assertDatabaseHas('order_returns', [
            'order_id' => $order->id,
            'status' => 'waiting_for_return',
        ]);
        $this->assertSame(OrderStatus::DeliveryRefused, $order->fresh()->status);
        $this->assertSame($stockBefore, $sellable->fresh()->stock_quantity);
        $this->assertSame($reservedBefore, $sellable->fresh()->reserved_quantity);
    }

    public function test_detail_exposes_catalog_ids_for_each_item_without_using_catalog_visibility(): void
    {
        $productA = Product::create([
            'slug' => 'admin-order-read-product-a',
            'name_ar' => 'منتج أ',
            'name_en' => 'Product A',
        ]);
        $productB = Product::create([
            'slug' => 'admin-order-read-product-b',
            'name_ar' => 'منتج ب',
            'name_en' => 'Product B',
        ]);
        $sellableA = SellableItem::create([
            'product_id' => $productA->id,
            'sku' => 'ADMIN-READ-A',
            'price' => '10.00',
        ]);
        $sellableB = SellableItem::create([
            'product_id' => $productB->id,
            'sku' => 'ADMIN-READ-B',
            'price' => '20.00',
        ]);
        $order = Order::factory()->create();
        $first = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $productA->id,
            'sellable_item_id' => $sellableA->id,
        ]);
        $second = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $productB->id,
            'sellable_item_id' => $sellableB->id,
        ]);
        $productB->delete();
        $sellableB->delete();
        $token = $this->adminToken();

        $response = $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk();

        $response->assertJsonPath('data.items.0.id', $first->id)
            ->assertJsonPath('data.items.0.product_id', $productA->id)
            ->assertJsonPath('data.items.0.sellable_item_id', $sellableA->id)
            ->assertJsonPath('data.items.1.id', $second->id)
            ->assertJsonPath('data.items.1.product_id', $productB->id)
            ->assertJsonPath('data.items.1.sellable_item_id', $sellableB->id)
            ->assertJsonPath('data.items.1.sku', $second->sku)
            ->assertJsonPath('data.items.1.product_name', $second->product_name_en);
    }

    public function test_detail_returns_null_catalog_ids_after_referenced_rows_are_hard_deleted(): void
    {
        $product = Product::create([
            'slug' => 'admin-order-read-missing-product',
            'name_ar' => 'منتج محذوف',
            'name_en' => 'Deleted Product',
        ]);
        $sellable = SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'ADMIN-READ-MISSING',
            'price' => '10.00',
        ]);
        $order = Order::factory()->create();
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $sellable->id,
            'sku' => 'HISTORICAL-SKU',
            'product_name_en' => 'Historical Product Name',
        ]);
        $sellable->forceDelete();
        $product->forceDelete();
        $token = $this->adminToken();

        $response = $this->withToken($token)->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk();

        $response->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.product_id', null)
            ->assertJsonPath('data.items.0.sellable_item_id', null)
            ->assertJsonPath('data.items.0.sku', 'HISTORICAL-SKU')
            ->assertJsonPath('data.items.0.product_name', 'Historical Product Name');
    }

    public function test_list_return_summaries_and_filters_follow_workflow_and_effective_quantities(): void
    {
        $waiting = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $waiting->id, 'quantity' => 2]);
        $this->createOrderReturn($waiting, 'waiting_for_return');

        $partial = Order::factory()->create();
        $partialItem = OrderItem::factory()->create(['order_id' => $partial->id, 'quantity' => 2]);
        $this->createLegacyReceipt($partial, $partialItem, 1);

        $full = Order::factory()->create();
        $fullItem = OrderItem::factory()->create(['order_id' => $full->id, 'quantity' => 1]);
        $this->createLegacyReceipt($full, $fullItem, 1);

        $correctedToZero = Order::factory()->create();
        $correctedItem = OrderItem::factory()->create(['order_id' => $correctedToZero->id, 'quantity' => 1]);
        $this->createLegacyReceipt($correctedToZero, $correctedItem, 0);

        $reversed = Order::factory()->create();
        $reversedItem = OrderItem::factory()->create(['order_id' => $reversed->id, 'quantity' => 1]);
        $this->createLegacyReceipt($reversed, $reversedItem, 1, now());

        $token = $this->adminToken();
        $rows = collect($this->withToken($token)->getJson('/api/v1/admin/orders?per_page=100')->assertOk()->json('data'))
            ->keyBy('public_id');

        $this->assertSame('none', $rows[$waiting->public_id]['return_summary']);
        $this->assertSame('waiting_for_return', $rows[$waiting->public_id]['return_workflow_status']);
        $this->assertSame('partial', $rows[$partial->public_id]['return_summary']);
        $this->assertSame('full', $rows[$full->public_id]['return_summary']);
        $this->assertSame('none', $rows[$correctedToZero->public_id]['return_summary']);
        $this->assertSame('none', $rows[$reversed->public_id]['return_summary']);
        $this->withToken($token)->getJson('/api/v1/admin/orders/'.$waiting->public_id)->assertOk()
            ->assertJsonPath('data.return_summary', $rows[$waiting->public_id]['return_summary'])
            ->assertJsonPath('data.return_workflow.status', $rows[$waiting->public_id]['return_workflow_status']);

        $this->withToken($token)->getJson('/api/v1/admin/orders?return_status=received&per_page=100')->assertOk()
            ->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson('/api/v1/admin/orders?return_status=awaiting_return&per_page=100')->assertOk()
            ->assertJsonPath('data.0.public_id', $waiting->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/orders?return_status=none&per_page=100')->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_list_refund_summaries_match_detail_for_review_recorded_and_unknown_amounts(): void
    {
        $paidCancellation = Order::factory()->cancelled()->create([
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Paid,
            'shipped_at' => null,
        ]);

        $unpaidRefusal = Order::factory()->create([
            'status' => OrderStatus::DeliveryRefused,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Unpaid,
        ]);
        $this->createOrderReturn($unpaidRefusal, 'waiting_for_return');

        $recorded = Order::factory()->create([
            'status' => OrderStatus::Delivered,
            'payment_method' => PaymentMethod::CashOnDelivery,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $this->createOrderReturn($recorded, 'received');
        RefundRecord::factory()->create(['order_id' => $recorded->id, 'amount' => '25.00']);

        $unknown = Order::factory()->create([
            'status' => OrderStatus::Delivered,
            'payment_method' => PaymentMethod::Card,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $this->createOrderReturn($unknown, 'received');

        $token = $this->adminToken();
        $rows = collect($this->withToken($token)->getJson('/api/v1/admin/orders?per_page=100')->assertOk()->json('data'))
            ->keyBy('public_id');

        $this->assertTrue($rows[$paidCancellation->public_id]['refund_recording']['eligible']);
        $this->assertSame('cod_full_collection_policy', $rows[$paidCancellation->public_id]['refund_recording']['source']);
        $this->assertFalse($rows[$unpaidRefusal->public_id]['refund_recording']['eligible']);
        $this->assertSame('paid_amount_unavailable', $rows[$unpaidRefusal->public_id]['refund_recording']['blocker']);
        $this->assertTrue($rows[$recorded->public_id]['refund_recording']['confirmed']);
        $this->assertSame('25.00', $rows[$recorded->public_id]['refund_recording']['refunded_amount']);
        $this->assertSame('145.00', $rows[$recorded->public_id]['refund_recording']['remaining_balance']);
        $this->assertFalse($rows[$recorded->public_id]['refund_recording']['eligible']);
        $this->assertSame('undetermined', $rows[$unknown->public_id]['refund_recording']['source']);
        $this->assertFalse($rows[$unknown->public_id]['refund_recording']['eligible']);

        $this->withToken($token)->getJson('/api/v1/admin/orders/'.$recorded->public_id)->assertOk()
            ->assertJsonPath('data.refund_recording', $rows[$recorded->public_id]['refund_recording']);
        $this->withToken($token)->getJson('/api/v1/admin/orders?refund_status=review&per_page=100')->assertOk()
            ->assertJsonPath('data.0.public_id', $paidCancellation->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/orders?refund_status=recorded&per_page=100')->assertOk()
            ->assertJsonPath('data.0.public_id', $recorded->public_id);
        $this->withToken($token)->getJson('/api/v1/admin/orders?refund_status=none&per_page=100')->assertJsonCount(2, 'data');
    }

    public function test_list_combined_filters_apply_before_pagination_and_keep_filter_links(): void
    {
        $orders = [];
        foreach (range(1, 3) as $index) {
            $order = Order::factory()->create([
                'status' => OrderStatus::DeliveryRefused,
                'payment_status' => PaymentStatus::Unpaid,
                'created_at' => "2026-09-0{$index} 10:00:00",
            ]);
            $this->createOrderReturn($order, 'waiting_for_return');
            $orders[] = $order;
        }

        $excluded = Order::factory()->create([
            'status' => OrderStatus::DeliveryRefused,
            'payment_status' => PaymentStatus::Unpaid,
            'created_at' => '2026-09-04 10:00:00',
        ]);
        $this->createOrderReturn($excluded, 'waiting_for_return');
        RefundRecord::factory()->create(['order_id' => $excluded->id, 'amount' => '10.00']);

        $response = $this->withToken($this->adminToken())->getJson(
            '/api/v1/admin/orders?status=delivery_refused&payment_status=unpaid&return_status=awaiting_return&refund_status=none&sort=oldest&per_page=1&page=2',
        );

        $response->assertOk()
            ->assertJsonPath('data.0.public_id', $orders[1]->public_id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 1);
        foreach (['status=delivery_refused', 'payment_status=unpaid', 'return_status=awaiting_return', 'refund_status=none'] as $filter) {
            $this->assertStringContainsString($filter, $response->json('links.next'));
        }
    }

    public function test_list_summary_query_count_does_not_grow_with_a_full_page(): void
    {
        foreach (range(1, 20) as $index) {
            $order = Order::factory()->create();
            OrderItem::factory()->create(['order_id' => $order->id]);
        }

        $token = $this->adminToken();
        $queryCounts = [1 => 0, 20 => 0];
        $pageSize = 1;
        DB::listen(function (QueryExecuted $query) use (&$pageSize, &$queryCounts): void {
            if (str_contains($query->sql, 'order_items')
                || str_contains($query->sql, 'return_receipts')
                || str_contains($query->sql, 'order_returns')
                || str_contains($query->sql, 'refund_records')
                || str_contains($query->sql, 'payment_attempts')) {
                $queryCounts[$pageSize]++;
            }
        });

        $this->withToken($token)->getJson('/api/v1/admin/orders?per_page=1')->assertOk();
        $pageSize = 20;
        $this->withToken($token)->getJson('/api/v1/admin/orders?per_page=20')->assertOk();

        $this->assertSame($queryCounts[1], $queryCounts[20]);
    }

    public function test_detail_prefers_variant_image_and_eager_loads_media_for_multiple_items(): void
    {
        $order = Order::factory()->create();
        $items = [];

        foreach (range(1, 3) as $index) {
            $product = Product::query()->create([
                'slug' => 'admin-order-image-'.$index,
                'name_ar' => 'منتج',
                'name_en' => 'Product '.$index,
            ]);
            $sellable = SellableItem::query()->create([
                'product_id' => $product->id,
                'sku' => 'ADMIN-ORDER-IMAGE-'.$index,
                'price' => '10.00',
            ]);
            $item = OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'sellable_item_id' => $sellable->id,
            ]);
            $this->attachOrderImage($product, MediaRole::PRODUCT_IMAGE, 'product-'.$index.'.jpg');
            $this->attachOrderImage($sellable, MediaRole::VARIANT_IMAGE, 'variant-'.$index.'.jpg');
            $items[] = $item;
        }

        $mediaQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$mediaQueries): void {
            if (str_contains($query->sql, 'media_attachments') || str_contains($query->sql, 'media_assets')) {
                $mediaQueries++;
            }
        });

        $response = $this->withToken($this->adminToken())->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk();

        foreach ($items as $index => $item) {
            $response->assertJsonPath(
                "data.items.{$index}.image_url",
                Storage::disk('public')->url('variant-'.($index + 1).'.jpg'),
            );
        }
        $this->assertLessThanOrEqual(4, $mediaQueries);
    }

    public function test_detail_falls_back_to_product_image_and_returns_null_without_images(): void
    {
        $order = Order::factory()->create();
        [$product, $sellable] = $this->orderCatalogItem('ADMIN-ORDER-PRODUCT-FALLBACK');
        $productOnly = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $sellable->id,
        ]);

        $noImage = OrderItem::factory()->create(['order_id' => $order->id]);
        $this->attachOrderImage($product, MediaRole::PRODUCT_IMAGE, 'fallback-product.jpg');

        $response = $this->withToken($this->adminToken())->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk();

        $response->assertJsonPath('data.items.0.id', $productOnly->id)
            ->assertJsonPath('data.items.0.image_url', Storage::disk('public')->url('fallback-product.jpg'))
            ->assertJsonPath('data.items.1.id', $noImage->id)
            ->assertJsonPath('data.items.1.image_url', null);
    }

    public function test_detail_resolves_images_for_soft_deleted_catalog_references(): void
    {
        $order = Order::factory()->create();
        [$product, $sellable] = $this->orderCatalogItem('ADMIN-ORDER-ARCHIVED');
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $sellable->id,
        ]);
        $this->attachOrderImage($sellable, MediaRole::VARIANT_IMAGE, 'archived-variant.jpg');
        $sellable->delete();
        $product->delete();

        $this->withToken($this->adminToken())->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk()
            ->assertJsonPath('data.items.0.image_url', Storage::disk('public')->url('archived-variant.jpg'));
    }

    public function test_detail_returns_null_when_catalog_and_media_references_are_permanently_missing(): void
    {
        $order = Order::factory()->create();
        [$product, $sellable] = $this->orderCatalogItem('ADMIN-ORDER-MISSING');
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sellable_item_id' => $sellable->id,
        ]);
        $productImage = $this->attachOrderImage($product, MediaRole::PRODUCT_IMAGE, 'deleted-product.jpg');
        $variantImage = $this->attachOrderImage($sellable, MediaRole::VARIANT_IMAGE, 'deleted-variant.jpg');
        $variantImage->mediaAsset->forceDelete();
        $productImage->mediaAsset->forceDelete();
        $sellable->forceDelete();
        $product->forceDelete();

        $this->withToken($this->adminToken())->getJson('/api/v1/admin/orders/'.$order->public_id)->assertOk()
            ->assertJsonPath('data.items.0.product_id', null)
            ->assertJsonPath('data.items.0.sellable_item_id', null)
            ->assertJsonPath('data.items.0.image_url', null);
    }

    /** @return array{Product, SellableItem} */
    private function orderCatalogItem(string $sku): array
    {
        $product = Product::query()->create([
            'slug' => strtolower($sku),
            'name_ar' => 'منتج',
            'name_en' => 'Product',
        ]);
        $sellable = SellableItem::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'price' => '10.00',
        ]);

        return [$product, $sellable];
    }

    private function createOrderReturn(Order $order, string $status): void
    {
        DB::table('order_returns')->insert([
            'order_id' => $order->id,
            'kind' => 'return_after_delivery',
            'status' => $status,
            'reason' => 'changed_mind',
            'recorded_at' => now(),
            'version' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLegacyReceipt(Order $order, OrderItem $item, int $effectiveQuantity, ?Carbon $reversedAt = null): void
    {
        $initialQuantity = max(1, $effectiveQuantity);
        $receiptId = DB::table('return_receipts')->insertGetId([
            'order_id' => $order->id,
            'order_return_id' => null,
            'received_at' => now(),
            'revision' => 0,
            'reversed_at' => $reversedAt,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('return_receipt_items')->insert([
            'return_receipt_id' => $receiptId,
            'order_item_id' => $item->id,
            'original_quantity' => $item->quantity,
            'initial_received_quantity' => $initialQuantity,
            'initial_restockable_quantity' => 0,
            'initial_reason' => 'changed_mind',
            'effective_received_quantity' => $effectiveQuantity,
            'effective_restockable_quantity' => 0,
            'effective_reason' => 'changed_mind',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachOrderImage(Product|SellableItem $owner, string $role, string $path): MediaAttachment
    {
        $asset = MediaAsset::query()->create([
            'disk' => 'public',
            'path' => $path,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 10,
            'width' => 10,
            'height' => 10,
        ]);

        return app(MediaService::class)->attach($asset, $owner, $role, ['is_primary' => true]);
    }

    private function adminToken(): string
    {
        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id]);

        return $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    private function forgetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
