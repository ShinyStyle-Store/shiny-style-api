<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_guest_order_is_created_with_authoritative_totals_and_reservation(): void
    {
        $area = $this->shippingArea(70);
        $item = $this->sellableItem('700.00', 5);

        $response = $this->withHeader('Accept-Language', 'ar')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $this->payload($area, $item, 2));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_confirmation')
            ->assertJsonPath('data.payment_method', 'cash_on_delivery')
            ->assertJsonPath('data.customer.phone', '01110007513')
            ->assertJsonPath('data.shipping.shipping_fee', '70.00')
            ->assertJsonPath('data.subtotal', '1400.00')
            ->assertJsonPath('data.total', '1470.00');

        $this->assertDatabaseHas('orders', [
            'customer_phone' => '01110007513',
            'subtotal' => '1400.00',
            'shipping_fee' => '70.00',
            'total' => '1470.00',
        ]);
        $this->assertSame(2, $item->refresh()->reserved_quantity);
        $this->assertSame(5, $item->stock_quantity);
        $this->assertGuest();
    }

    public function test_english_response_and_bilingual_option_snapshot_are_supported(): void
    {
        $area = $this->shippingArea(10);
        [$item, $option, $value] = $this->sellableItemWithOption();

        $this->withHeader('Accept-Language', 'en-US')
            ->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $this->payload($area, $item, 1))
            ->assertCreated()
            ->assertJsonPath('data.shipping.area.name', 'Cairo')
            ->assertJsonPath('data.items.0.selected_options.0.name', 'Color')
            ->assertJsonPath('data.items.0.selected_options.0.value', 'Beige');

        $orderItem = OrderItem::query()
            ->where('sellable_item_id', $item->id)
            ->firstOrFail();

        $this->assertSame($item->id, $orderItem->sellable_item_id);
        $this->assertSame([[
            'option_name_ar' => 'اللون',
            'option_name_en' => 'Color',
            'value_ar' => 'بيج',
            'value_en' => 'Beige',
        ]], $orderItem->options_snapshot);
        $this->assertNotNull($option->id);
        $this->assertNotNull($value->id);
    }

    public function test_same_key_replays_without_new_order_or_reservation_and_conflicts_on_changed_payload(): void
    {
        $area = $this->shippingArea(20);
        $item = $this->sellableItem('100.00', 5);
        $key = $this->key();
        $payload = $this->payload($area, $item, 2);

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', $payload);

        $second->assertOk()->assertJsonPath('data.public_id', $first->json('data.public_id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(2, $item->refresh()->reserved_quantity);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $this->payload($area, $item, 1))
            ->assertConflict();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_fingerprint_ignores_payload_key_order_and_phone_formatting(): void
    {
        $area = $this->shippingArea(20);
        $item = $this->sellableItem('100.00', 5);
        $key = $this->key();
        $first = $this->payload($area, $item, 1);
        $second = [
            'order_note' => null,
            'payment_method' => 'cash_on_delivery',
            'items' => [['quantity' => 1, 'sellable_item_id' => $item->id]],
            'shipping' => ['address' => 'Full address', 'landmark' => null, 'shipping_area_id' => $area->id],
            'customer' => ['alternate_phone' => null, 'phone' => '+20 1110007513', 'name' => 'Customer Name'],
        ];

        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', $first)->assertCreated();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', $second)->assertOk();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, $item->refresh()->reserved_quantity);
    }

    public function test_exact_available_stock_succeeds_and_excess_stock_rolls_back(): void
    {
        $area = $this->shippingArea(0);
        $exact = $this->sellableItem('125.50', 5, 2);

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $this->payload($area, $exact, 3))
            ->assertCreated();
        $this->assertSame(5, $exact->refresh()->reserved_quantity);

        $unavailable = $this->sellableItem('100.00', 5, 4);
        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $this->payload($area, $unavailable, 2))
            ->assertUnprocessable();
        $this->assertSame(4, $unavailable->refresh()->reserved_quantity);
    }

    public function test_one_invalid_item_rolls_back_the_order_and_all_reservations(): void
    {
        $area = $this->shippingArea(10);
        $valid = $this->sellableItem('100.00', 5);
        $payload = $this->payload($area, $valid, 1);
        $payload['items'][] = ['sellable_item_id' => 999999, 'quantity' => 1];

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(0, $valid->refresh()->reserved_quantity);
    }

    public function test_invalid_headers_contract_and_server_owned_fields_are_rejected(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();
        $payload = $this->payload($area, $item, 1);

        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->withHeader('Idempotency-Key', 'not-a-uuid')
            ->postJson('/api/v1/orders', $payload)
            ->assertUnprocessable();

        $payload['total'] = '0.00';
        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_shipping_catalog_payment_and_duplicate_item_failures_are_rejected(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();
        $payload = $this->payload($area, $item, 1);
        $payload['payment_method'] = 'wallet';

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $payload = $this->payload($area, $item, 1);
        $payload['items'][] = $payload['items'][0];
        $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/v1/orders', $payload)->assertUnprocessable();

        $inactiveArea = ShippingArea::factory()->create(['is_active' => false]);
        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $this->payload($inactiveArea, $item, 1))
            ->assertUnprocessable();
    }

    public function test_card_order_is_pending_and_returns_signed_payment_urls(): void
    {
        config([
            'services.paymob.secret_key' => 'test-secret',
            'services.paymob.public_key' => 'test-public',
            'services.paymob.card_integration_id' => '456',
            'services.paymob.api_base_url' => 'https://paymob.test',
            'services.paymob.intention_endpoint' => '/v1/intention/',
            'services.paymob.redirect_url' => 'https://shop.test/return',
            'services.paymob.unified_checkout_base_url' => 'https://paymob.test/unifiedcheckout/',
        ]);
        $area = $this->shippingArea(20);
        $item = $this->sellableItem('100.00', 5);
        $payload = $this->payload($area, $item, 2);
        $payload['payment_method'] = 'card';
        $payload['customer']['email'] = 'customer@example.com';

        $response = $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.payment_method', 'card')
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.payment.required', true)
            ->assertJsonPath('data.payment.method', 'card')
            ->assertJsonPath('data.payment.status', 'pending');
        $this->assertSame(2, $item->refresh()->reserved_quantity);
        $this->assertNotNull($response->json('data.payment.expiresAt'));
        $this->assertNotEmpty($response->json('data.payment.initiateUrl'));
        $this->assertNotEmpty($response->json('data.payment.statusUrl'));
    }

    public function test_card_payment_urls_use_forwarded_https_and_validate_with_the_same_scheme(): void
    {
        config([
            'trustedproxy.proxies' => 'REMOTE_ADDR',
            'services.paymob.secret_key' => 'test-secret',
            'services.paymob.public_key' => 'test-public',
            'services.paymob.card_integration_id' => '456',
            'services.paymob.api_base_url' => 'https://paymob.test',
            'services.paymob.intention_endpoint' => '/v1/intention/',
            'services.paymob.redirect_url' => 'https://shop.test/return',
            'services.paymob.unified_checkout_base_url' => 'https://paymob.test/unifiedcheckout/',
        ]);
        TrustProxies::at('REMOTE_ADDR');

        try {
            $area = $this->shippingArea(20);
            $item = $this->sellableItem('100.00', 5);
            $payload = $this->payload($area, $item, 1);
            $payload['payment_method'] = 'card';
            $payload['customer']['email'] = 'customer@example.com';
            $server = [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_HOST' => 'shiny-style-api-d0866abd8743.herokuapp.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PORT' => '443',
            ];

            $response = $this->withServerVariables($server)
                ->withHeader('Idempotency-Key', $this->key())
                ->postJson('/api/v1/orders', $payload);

            $response->assertCreated();
            $initiateUrl = $response->json('data.payment.initiateUrl');
            $statusUrl = $response->json('data.payment.statusUrl');
            $this->assertStringStartsWith('https://', $initiateUrl);
            $this->assertStringStartsWith('https://', $statusUrl);

            Http::fake(['https://paymob.test/*' => Http::response([
                'id' => 'int-forwarded-https',
                'client_secret' => 'client-secret-forwarded-https',
            ], 201)]);

            $this->withServerVariables($server)
                ->withHeader('Idempotency-Key', $this->key())
                ->post($initiateUrl)
                ->assertCreated();
            $this->withServerVariables($server)
                ->get($statusUrl)
                ->assertOk();
        } finally {
            TrustProxies::flushState();
        }
    }

    public function test_missing_card_configuration_rejects_before_order_or_reservation_creation(): void
    {
        config([
            'services.paymob.secret_key' => '',
            'services.paymob.card_integration_id' => '',
        ]);
        $area = $this->shippingArea(20);
        $item = $this->sellableItem('100.00', 5);
        $payload = $this->payload($area, $item, 1);
        $payload['payment_method'] = 'card';
        $payload['customer']['email'] = 'customer@example.com';

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload)
            ->assertStatus(503)
            ->assertJsonPath('code', 'payment_configuration_unavailable');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $item->refresh()->reserved_quantity);
    }

    public function test_products_behind_an_inactive_category_ancestor_cannot_be_ordered(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();
        $category = $item->product->categories()->firstOrFail();
        $inactiveAncestor = Category::create([
            'slug' => 'order-inactive-ancestor-'.uniqid(),
            'name_ar' => 'تصنيف',
            'name_en' => 'Inactive ancestor',
            'status' => 'inactive',
        ]);
        $category->update(['parent_id' => $inactiveAncestor->getKey()]);

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $this->payload($area, $item, 1))
            ->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_quote_respects_reserved_quantity_without_changing_response_shape(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem('100.00', 5, 5);

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_only_the_order_creation_route_uses_the_guest_order_rate_limiter(): void
    {
        $orderRoute = app('router')->getRoutes()->match(Request::create('/api/v1/orders', 'POST'));
        $quoteRoute = app('router')->getRoutes()->match(Request::create('/api/v1/checkout/quote', 'POST'));

        $this->assertContains('throttle:guest-orders', $orderRoute->middleware());
        $this->assertNotContains('throttle:guest-orders', $quoteRoute->middleware());
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function shippingArea(int $fee): ShippingArea
    {
        return ShippingArea::factory()->create([
            'code' => 'cairo-'.uniqid(),
            'name_ar' => 'القاهرة',
            'name_en' => 'Cairo',
            'shipping_fee' => (string) $fee,
        ]);
    }

    private function payload(ShippingArea $area, SellableItem $item, int $quantity): array
    {
        return [
            'customer' => [
                'name' => 'Customer Name',
                'phone' => '01110007513',
                'alternate_phone' => null,
            ],
            'shipping' => [
                'shipping_area_id' => $area->id,
                'address' => 'Full address',
                'landmark' => null,
            ],
            'items' => [['sellable_item_id' => $item->id, 'quantity' => $quantity]],
            'payment_method' => 'cash_on_delivery',
            'order_note' => null,
        ];
    }

    private function sellableItem(string $price = '100.00', int $stock = 5, int $reserved = 0): SellableItem
    {
        $product = Product::create([
            'slug' => 'order-product-'.uniqid(),
            'name_ar' => 'منتج عربي',
            'name_en' => 'English Product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $category = Category::create([
            'slug' => 'order-category-'.uniqid(),
            'name_ar' => 'تصنيف',
            'name_en' => 'Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);

        return SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'ORDER-SKU-'.strtoupper(uniqid()),
            'price' => $price,
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'status' => 'active',
            'is_default' => true,
        ]);
    }

    /** @return array{0: SellableItem, 1: ProductOption, 2: ProductOptionValue} */
    private function sellableItemWithOption(): array
    {
        $item = $this->sellableItem();
        $option = ProductOption::create([
            'product_id' => $item->product_id,
            'code' => 'color',
            'name_ar' => 'اللون',
            'name_en' => 'Color',
            'sort_order' => 1,
        ]);
        $value = ProductOptionValue::create([
            'product_option_id' => $option->id,
            'code' => 'beige',
            'value_ar' => 'بيج',
            'value_en' => 'Beige',
            'sort_order' => 1,
        ]);
        $item->optionValues()->sync([$value->id]);

        return [$item, $option, $value];
    }
}
