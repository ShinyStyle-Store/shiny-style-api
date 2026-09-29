<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferCheckoutPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-10T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_quote_uses_effective_prices_for_offered_and_non_offered_variants(): void
    {
        [$product, $offeredItem, $secondOfferedItem] = $this->productWithVariants();
        $nonOfferedItem = $this->variant($this->product('No offer'), '5.00', true);
        $this->offer($product, '12.34');
        $area = $this->shippingArea(1);

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [
                ['sellable_item_id' => $offeredItem->id, 'quantity' => 2],
                ['sellable_item_id' => $secondOfferedItem->id, 'quantity' => 1],
                ['sellable_item_id' => $nonOfferedItem->id, 'quantity' => 1],
            ],
        ])->assertOk()
            ->assertJsonPath('data.items.0.base_unit_price', '10.01')
            ->assertJsonPath('data.items.0.unit_price', '8.77')
            ->assertJsonPath('data.items.0.discount_amount', '1.24')
            ->assertJsonPath('data.items.0.offer_discount_percentage', '12.34')
            ->assertJsonPath('data.items.0.line_total', '17.54')
            ->assertJsonPath('data.items.1.base_unit_price', '20.00')
            ->assertJsonPath('data.items.1.unit_price', '17.53')
            ->assertJsonPath('data.items.1.discount_amount', '2.47')
            ->assertJsonPath('data.items.2.base_unit_price', '5.00')
            ->assertJsonPath('data.items.2.unit_price', '5.00')
            ->assertJsonPath('data.items.2.discount_amount', '0.00')
            ->assertJsonPath('data.items.2.offer_id', null)
            ->assertJsonPath('data.subtotal', '40.07')
            ->assertJsonPath('data.total', '41.07');
    }

    public function test_quote_uses_inclusive_start_and_exclusive_end(): void
    {
        [$product, $item] = $this->productWithVariants();
        $this->offer($product, '10.00', '2026-01-10T12:00:00Z', '2026-01-10T13:00:00Z');
        $area = $this->shippingArea(0);
        $payload = [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
        ];

        $this->postJson('/api/v1/checkout/quote', $payload)
            ->assertJsonPath('data.items.0.unit_price', '9.01');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-10T13:00:00Z'));

        $this->postJson('/api/v1/checkout/quote', $payload)
            ->assertJsonPath('data.items.0.unit_price', '10.01')
            ->assertJsonPath('data.items.0.offer_id', null);
    }

    public function test_new_order_recalculates_after_offer_expiration_and_persists_snapshots(): void
    {
        [$product, $item] = $this->productWithVariants();
        $offer = $this->offer($product, '10.00');
        $area = $this->shippingArea(70);
        $payload = $this->payload($area, $item, 2);

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 2]],
        ])->assertJsonPath('data.items.0.unit_price', '9.01');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-01T00:00:00Z'));
        $response = $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '20.02')
            ->assertJsonPath('data.total', '90.02');

        $orderItem = $this->orderItem($response->json('data.public_id'));
        $this->assertSame('10.01', (string) $orderItem->base_unit_price);
        $this->assertSame('10.01', (string) $orderItem->unit_price);
        $this->assertSame('0.00', (string) $orderItem->discount_amount);
        $this->assertNull($orderItem->offer_id);
        $this->assertNull($orderItem->offer_discount_percentage);
        $this->assertSame('10.00', (string) $offer->fresh()->discount_percentage);
    }

    public function test_idempotent_replay_keeps_original_discounted_snapshot_after_expiration(): void
    {
        [$product, $item] = $this->productWithVariants(['stock_quantity' => 20]);
        $offer = $this->offer($product, '10.00');
        $area = $this->shippingArea(0);
        $payload = $this->payload($area, $item, 1);
        $key = $this->key();

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '9.01');

        $offer->update(['is_enabled' => false]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-01T00:00:00Z'));

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $payload)
            ->assertOk()
            ->assertJsonPath('data.public_id', $first->json('data.public_id'))
            ->assertJsonPath('data.items.0.unit_price', '9.01');

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '10.01');
    }

    public function test_card_payment_amount_and_paymob_lines_reconcile_with_discounted_order(): void
    {
        config([
            'services.paymob.secret_key' => 'test-secret',
            'services.paymob.public_key' => 'test-public',
            'services.paymob.card_integration_id' => '456',
            'services.paymob.api_base_url' => 'https://paymob.test',
            'services.paymob.intention_endpoint' => '/v1/intention/',
            'services.paymob.redirect_url' => 'https://shop.test/return',
            'services.paymob.webhook_url' => 'https://shop.test/api/v1/payments/paymob/webhook',
            'services.paymob.unified_checkout_base_url' => 'https://paymob.test/unifiedcheckout/',
        ]);
        Http::fake(['https://paymob.test/*' => Http::response([
            'id' => 'int-offer',
            'client_secret' => 'client-secret-offer',
        ], 201)]);

        [$product, $item] = $this->productWithVariants();
        $offer = $this->offer($product, '10.00');
        $area = $this->shippingArea(70);
        $payload = $this->payload($area, $item, 2);
        $payload['payment_method'] = 'card';
        $payload['customer']['email'] = 'customer@example.com';

        $orderResponse = $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '18.02')
            ->assertJsonPath('data.total', '88.02');

        $initiateUrl = $orderResponse->json('data.payment.initiateUrl');
        $paymentKey = $this->key();
        $this->withHeader('Idempotency-Key', $paymentKey)
            ->post($initiateUrl)
            ->assertCreated();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['amount'] === 8802
                && $body['items'][0]['amount'] === 901
                && $body['items'][0]['quantity'] === 2
                && $body['items'][1]['amount'] === 7000
                && $body['items'][1]['name'] === 'Shipping';
        });

        $offer->update(['is_enabled' => false]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-10T12:10:00Z'));
        $this->withHeader('Idempotency-Key', $paymentKey)
            ->post($initiateUrl)
            ->assertOk();
        Http::assertSentCount(1);
    }

    public function test_editing_or_disabling_an_offer_does_not_change_historical_snapshot(): void
    {
        [$product, $item] = $this->productWithVariants();
        $offer = $this->offer($product, '10.00');
        $area = $this->shippingArea(0);
        $payload = $this->payload($area, $item, 1);

        $response = $this->withHeader('Idempotency-Key', $this->key())
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated();
        $orderItem = $this->orderItem($response->json('data.public_id'));

        $offer->update(['discount_percentage' => '50.00', 'is_enabled' => false]);
        $orderItem->refresh();

        $this->assertSame('10.01', (string) $orderItem->base_unit_price);
        $this->assertSame('9.01', (string) $orderItem->unit_price);
        $this->assertSame('1.00', (string) $orderItem->discount_amount);
        $this->assertSame('9.01', (string) $orderItem->line_total);
        $this->assertSame($offer->id, $orderItem->offer_id);
        $this->assertSame('10.00', (string) $orderItem->offer_discount_percentage);
    }

    private function productWithVariants(array $attributes = []): array
    {
        $product = $this->product('Offered product', $attributes);
        $first = $this->variant($product, '10.01', true, $attributes['stock_quantity'] ?? 10);
        $second = $this->variant($product, '20.00', false, $attributes['stock_quantity'] ?? 10);

        return [$product, $first, $second];
    }

    private function product(string $name, array $attributes = []): Product
    {
        $product = Product::create(array_merge([
            'slug' => Str::slug($name).'-'.Str::random(6),
            'name_ar' => $name,
            'name_en' => $name,
            'status' => 'active',
            'published_at' => CarbonImmutable::now()->subDay(),
        ], $attributes));
        $category = Category::create([
            'slug' => 'category-'.Str::random(8),
            'name_ar' => 'ØªØµÙ†ÙŠÙ',
            'name_en' => 'Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);

        return $product;
    }

    private function variant(Product $product, string $price, bool $default, int $stock = 10): SellableItem
    {
        return SellableItem::create([
            'product_id' => $product->id,
            'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'price' => $price,
            'stock_quantity' => $stock,
            'status' => 'active',
            'is_default' => $default,
        ]);
    }

    private function offer(Product $product, string $percentage, string $startsAt = '2026-01-01T00:00:00Z', string $endsAt = '2026-02-01T00:00:00Z', bool $enabled = true): Offer
    {
        $offer = Offer::create([
            'name' => 'Checkout offer',
            'discount_percentage' => $percentage,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_enabled' => $enabled,
        ]);
        $offer->products()->attach($product->id);

        return $offer;
    }

    private function shippingArea(int $fee): ShippingArea
    {
        return ShippingArea::factory()->create([
            'code' => 'area-'.Str::random(8),
            'name_ar' => 'Ø§Ù„Ù‚Ø§Ù‡Ø±Ø©',
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

    private function orderItem(string $publicId)
    {
        return \App\Models\Order::query()->where('public_id', $publicId)->firstOrFail()->items()->firstOrFail();
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }
}
