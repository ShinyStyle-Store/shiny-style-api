<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\ContactStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AdminMembership;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShippingArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
