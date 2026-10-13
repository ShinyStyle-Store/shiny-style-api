<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use App\Models\User;
use App\Services\AdminProductSummaryQuery;
use App\Services\OfferPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProductConversionApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->id, 'status' => AdminMembershipStatus::Active]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_conversion_transfers_inventory_creates_variants_and_replays_exactly(): void
    {
        [$product, $source] = $this->simpleProduct();
        $historicalOrder = Order::factory()->shipped()->create();
        $historicalOrderItem = OrderItem::factory()->create([
            'order_id' => $historicalOrder->id,
            'product_id' => $product->id,
            'sellable_item_id' => $source->id,
            'sku' => $source->sku,
            'product_name_en' => 'Snapshot before conversion',
            'options_snapshot' => [],
            'quantity' => 1,
        ]);
        $historicalSnapshot = $historicalOrderItem->only([
            'product_id', 'sellable_item_id', 'sku', 'product_name_en', 'options_snapshot', 'unit_price', 'line_total',
        ]);
        $context = $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/products/{$product->id}/conversion-context/{$source->id}")
            ->assertOk()->json('data');
        $this->assertSame((int) $source->getKey(), $context['sourceSellableItemId']);
        $payload = $this->payload($context, 7);
        $key = (string) Str::uuid();

        $first = $this->withToken($this->adminToken)->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertCreated()->assertJsonPath('data.source.stockAfter', 0)
            ->assertJsonPath('data.inventory.allocatedStock', 10)
            ->assertJsonPath('data.variants.0.isDefault', true);
        $resultingIds = collect($first->json('data.variants'))->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $persistedIds = SellableItem::query()->where('product_id', $product->id)->whereNull('superseded_at')
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame($persistedIds, $resultingIds);
        $this->assertNotContains((int) $source->getKey(), $resultingIds);
        $optionValueIds = collect($first->json('data.options'))->flatMap(fn (array $option): array => array_column($option['values'], 'id'))
            ->map(fn ($id): int => (int) $id)->all();
        foreach ($first->json('data.variants') as $variant) {
            $variantValueIds = collect($variant['optionValueIds'])->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $this->assertSame([], array_diff($variantValueIds, $optionValueIds));
            $persistedVariant = SellableItem::query()->with('optionValues')->findOrFail($variant['id']);
            $this->assertSame(
                $variantValueIds,
                collect($persistedVariant->optionValues->modelKeys())->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            );
        }

        $this->assertDatabaseHas('sellable_items', ['id' => $source->id, 'status' => 'inactive', 'is_default' => false, 'stock_quantity' => 0]);
        $this->assertNotNull(SellableItem::findOrFail($source->id)->superseded_at);
        $this->assertDatabaseCount('product_conversions', 1);
        $this->assertSame($historicalSnapshot, $historicalOrderItem->fresh()->only(array_keys($historicalSnapshot)));
        $this->assertSame($source->id, (int) $historicalOrderItem->fresh()->sellableItem->getKey());

        $product->status = 'draft';
        $product->save();
        $this->app['auth']->forgetGuards();
        $replay = $this->withToken($this->adminToken)->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertOk()->assertJsonPath('meta.replayed', true)
            ->assertJsonPath('data.conversionId', $first->json('data.conversionId'));
        $this->assertSame($first->json('data'), $replay->json('data'));

        $changedPayload = $payload;
        $changedPayload['variants'][0]['price'] = '26.00';
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $changedPayload)
            ->assertConflict()->assertJsonPath('code', 'idempotency_key_conflict');
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertConflict()->assertJsonPath('code', 'already_converted');
    }

    public function test_product_list_and_detail_expose_only_an_eligible_simple_source_id(): void
    {
        [$product, $source] = $this->simpleProduct();

        $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/products')
            ->assertOk()
            ->assertJsonPath('data.0.sourceSellableItemId', (int) $source->getKey());

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.sourceSellableItemId', (int) $source->getKey());

        SellableItem::create([
            'product_id' => $product->id, 'sku' => 'SECOND-'.Str::upper(Str::random(8)), 'price' => '26.00',
            'stock_quantity' => 1, 'reserved_quantity' => 0, 'status' => 'active', 'is_default' => false,
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.sourceSellableItemId', null);
    }

    public function test_conversion_context_rejects_a_source_owned_by_another_product(): void
    {
        [$product] = $this->simpleProduct();
        [, $otherSource] = $this->simpleProduct();

        $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/products/{$product->id}/conversion-context/{$otherSource->id}")
            ->assertNotFound();

        $otherContext = $this->context($otherSource->product, $otherSource);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$otherSource->id}", $this->payload($otherContext, 10))
            ->assertNotFound();
    }

    public function test_stock_difference_requires_exact_confirmation_and_reason_and_rolls_back(): void
    {
        [$product, $source] = $this->simpleProduct();
        $context = $this->context($product, $source);
        $payload = $this->payload($context, 9);
        $payload['variants'][1]['stock_quantity'] = 0;

        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('stock_adjustment');
        $this->assertSame(10, (int) $source->fresh()->stock_quantity);
        $this->assertDatabaseCount('product_conversions', 0);
        $this->assertDatabaseCount('product_options', 0);

        $payload['stock_adjustment'] = ['confirmed_difference' => -1, 'reason' => 'Counted shelf stock'];
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertCreated()->assertJsonPath('data.inventory.difference', -1);
    }

    public function test_stale_context_reservations_and_invalid_variant_assignments_do_not_partially_convert(): void
    {
        [$product, $source] = $this->simpleProduct();
        $context = $this->context($product, $source);
        $payload = $this->payload($context, 10);
        $payload['variants'][0]['option_value_refs'] = ['missing-value'];

        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.option_value_refs.0');
        $this->assertDatabaseCount('product_options', 0);

        $payload = $this->payload($context, 10);
        $source->update(['stock_quantity' => 11]);

        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertConflict()->assertJsonPath('code', 'stale_context');
        $this->assertDatabaseCount('product_options', 0);

        $source->stock_quantity = 10;
        $source->reserved_quantity = 1;
        $source->save();
        $freshContext = $this->context($product, $source);
        $payload = $this->payload($freshContext, 10);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertConflict()->assertJsonPath('code', 'product_has_reservations');
        $this->assertDatabaseCount('product_conversions', 0);
    }

    public function test_superseded_source_cannot_be_reactivated_archived_or_restored(): void
    {
        [$product, $source] = $this->simpleProduct();
        $context = $this->context($product, $source);
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $this->payload($context, 10))
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson("/api/v1/admin/products/{$product->id}/sellable-items/{$source->id}", [
            'status' => 'active', 'stock_quantity' => 10, 'is_default' => true,
        ])->assertConflict()->assertJsonPath('code', 'variant_superseded');
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items/{$source->id}/archive")
            ->assertConflict()->assertJsonPath('code', 'variant_superseded');
        $source->delete();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items/{$source->id}/restore")
            ->assertConflict()->assertJsonPath('code', 'variant_superseded');
        $this->assertDatabaseHas('sellable_items', ['id' => $source->id, 'stock_quantity' => 0, 'status' => 'inactive']);
    }

    public function test_superseded_source_is_hidden_from_catalog_and_rejected_by_quote_and_order_creation(): void
    {
        [$product, $source] = $this->simpleProduct();
        $context = $this->context($product, $source);
        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $this->payload($context, 10))
            ->assertCreated();

        // Exercise the superseded marker independently of the normal inactive status.
        $source->forceFill(['status' => 'active', 'stock_quantity' => 20])->saveQuietly();
        $this->assertFalse(app(OfferPricingService::class)->calculate($source->fresh())['eligible']);

        $detail = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
        $publicIds = array_map('strval', $detail->json('data.sellableItems.*.id'));
        $this->assertNotContains((string) $source->id, $publicIds);
        $this->assertCount(2, $publicIds);

        $summary = app(AdminProductSummaryQuery::class)->hydrate($product->fresh());
        $this->assertSame(10, $summary->active_stock_total);

        $area = ShippingArea::factory()->create(['shipping_fee' => '5.00']);
        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $source->id, 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/orders', [
                'customer' => ['name' => 'Customer', 'phone' => '01110007513'],
                'shipping' => ['shipping_area_id' => $area->id, 'address' => 'Address'],
                'items' => [['sellable_item_id' => $source->id, 'quantity' => 1]],
                'payment_method' => 'cash_on_delivery',
            ])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_duplicate_sku_failure_rolls_back_options_source_inventory_and_conversion_record(): void
    {
        [$product, $source] = $this->simpleProduct();
        $other = Product::create([
            'slug' => 'other-'.Str::lower(Str::random(8)), 'name_ar' => 'منتج آخر', 'name_en' => 'Other', 'status' => 'draft',
        ]);
        SellableItem::create([
            'product_id' => $other->id, 'sku' => 'TAKEN-SKU', 'price' => '5.00',
            'stock_quantity' => 1, 'reserved_quantity' => 0, 'status' => 'inactive', 'is_default' => false,
        ]);
        $payload = $this->payload($this->context($product, $source), 10);
        $payload['variants'][0]['sku'] = 'TAKEN-SKU';

        $this->withToken($this->adminToken)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/admin/products/{$product->id}/convert-simple-item/{$source->id}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('variants');

        $this->assertSame('active', $source->fresh()->status);
        $this->assertSame(10, (int) $source->fresh()->stock_quantity);
        $this->assertNull($source->fresh()->superseded_at);
        $this->assertDatabaseCount('product_options', 0);
        $this->assertDatabaseCount('product_conversions', 0);
        $this->assertSame(1, SellableItem::query()->where('product_id', $product->id)->count());
    }

    private function simpleProduct(): array
    {
        $product = Product::create([
            'slug' => 'conversion-'.Str::lower(Str::random(8)), 'name_ar' => 'منتج', 'name_en' => 'Product',
            'status' => 'active', 'published_at' => now()->subMinute(),
        ]);
        $category = Category::create([
            'slug' => 'conversion-category-'.Str::lower(Str::random(8)), 'name_ar' => 'تصنيف', 'name_en' => 'Category', 'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $source = SellableItem::create([
            'product_id' => $product->id, 'sku' => 'SOURCE-'.Str::upper(Str::random(8)), 'price' => '25.00',
            'stock_quantity' => 10, 'reserved_quantity' => 0, 'status' => 'active', 'is_default' => true,
        ]);

        return [$product, $source];
    }

    private function context(Product $product, SellableItem $source): array
    {
        return $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/products/{$product->id}/conversion-context/{$source->id}")
            ->assertOk()->json('data');
    }

    private function payload(array $context, int $stock): array
    {
        return [
            'context_token' => $context['contextToken'],
            'options' => [[
                'ref' => 'size-option', 'code' => 'size', 'name_ar' => 'المقاس', 'name_en' => 'Size', 'sort_order' => 0,
                'values' => [
                    ['ref' => 'small', 'code' => 'small', 'value_ar' => 'صغير', 'value_en' => 'Small', 'sort_order' => 0],
                    ['ref' => 'large', 'code' => 'large', 'value_ar' => 'كبير', 'value_en' => 'Large', 'sort_order' => 1],
                ],
            ]],
            'variants' => [
                ['ref' => 'small-variant', 'sku' => 'SMALL-'.Str::upper(Str::random(6)), 'price' => '25.00', 'stock_quantity' => $stock, 'sort_order' => 0, 'option_value_refs' => ['small']],
                ['ref' => 'large-variant', 'sku' => 'LARGE-'.Str::upper(Str::random(6)), 'price' => '27.00', 'stock_quantity' => 10 - $stock, 'sort_order' => 1, 'option_value_refs' => ['large']],
            ],
            'default_variant_ref' => 'small-variant',
        ];
    }
}
