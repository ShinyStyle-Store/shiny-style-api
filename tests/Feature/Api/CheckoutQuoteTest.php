<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\SellableItem;
use App\Models\ShippingArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_arabic_quote_is_public_and_returns_exact_money_values(): void
    {
        $area = $this->shippingArea(70);
        $item = $this->sellableItem(['price' => '700.00', 'stock_quantity' => 4]);

        $response = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/checkout/quote', [
                'shipping_area_id' => $area->id,
                'items' => [['sellable_item_id' => $item->id, 'quantity' => 2]],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.items.0.product_name', 'منتج عربي')
            ->assertJsonPath('data.items.0.unit_price', '700.00')
            ->assertJsonPath('data.items.0.line_total', '1400.00')
            ->assertJsonPath('data.subtotal', '1400.00')
            ->assertJsonPath('data.shipping_fee', '70.00')
            ->assertJsonPath('data.total', '1470.00');
        $this->assertGuest();
    }

    public function test_english_quotes_support_multiple_items_and_preserve_input_order(): void
    {
        $area = $this->shippingArea(0);
        $first = $this->sellableItem(['sku' => 'FIRST', 'price' => '125.50', 'stock_quantity' => 2]);
        $second = $this->sellableItem(['sku' => 'SECOND', 'price' => '10.25', 'stock_quantity' => 2]);

        $response = $this->withHeader('Accept-Language', 'en-US')
            ->postJson('/api/v1/checkout/quote', [
                'shipping_area_id' => $area->id,
                'items' => [
                    ['sellable_item_id' => $second->id, 'quantity' => 2],
                    ['sellable_item_id' => $first->id, 'quantity' => 1],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.sellable_item_id', $second->id)
            ->assertJsonPath('data.items.0.product_name', 'English Product')
            ->assertJsonPath('data.items.0.line_total', '20.50')
            ->assertJsonPath('data.items.1.sellable_item_id', $first->id)
            ->assertJsonPath('data.subtotal', '146.00')
            ->assertJsonPath('data.shipping_fee', '0.00')
            ->assertJsonPath('data.total', '146.00');
    }

    public function test_selected_options_are_localized_and_deterministically_ordered(): void
    {
        $area = $this->shippingArea(5);
        $item = $this->sellableItem();
        $size = ProductOption::create([
            'product_id' => $item->product_id,
            'code' => 'size',
            'name_ar' => 'المقاس',
            'name_en' => 'Size',
            'sort_order' => 2,
        ]);
        $color = ProductOption::create([
            'product_id' => $item->product_id,
            'code' => 'color',
            'name_ar' => 'اللون',
            'name_en' => 'Color',
            'sort_order' => 1,
        ]);
        $sizeValue = ProductOptionValue::create([
            'product_option_id' => $size->id,
            'code' => 'medium',
            'value_ar' => 'متوسط',
            'value_en' => 'Medium',
        ]);
        $colorValue = ProductOptionValue::create([
            'product_option_id' => $color->id,
            'code' => 'red',
            'value_ar' => 'أحمر',
            'value_en' => 'Red',
        ]);
        $item->optionValues()->sync([$sizeValue->id, $colorValue->id]);

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/checkout/quote', [
                'shipping_area_id' => $area->id,
                'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.selected_options.0.name', 'Color')
            ->assertJsonPath('data.items.0.selected_options.0.value', 'Red')
            ->assertJsonPath('data.items.0.selected_options.1.name', 'Size')
            ->assertJsonPath('data.items.0.selected_options.1.value', 'Medium');
    }

    public function test_products_without_options_return_an_empty_selected_options_array(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.items.0.selected_options', []);
    }

    public function test_payload_validation_rejects_missing_empty_duplicate_and_unexpected_values(): void
    {
        $cases = [
            [],
            ['shipping_area_id' => 1],
            ['shipping_area_id' => 1, 'items' => []],
            ['shipping_area_id' => 1, 'items' => [['quantity' => 1]]],
            ['shipping_area_id' => 1, 'items' => [['sellable_item_id' => 1, 'quantity' => 0]]],
            ['shipping_area_id' => 1, 'items' => [['sellable_item_id' => 1, 'quantity' => 1.5]]],
            ['shipping_area_id' => 1, 'items' => [
                ['sellable_item_id' => 1, 'quantity' => 1],
                ['sellable_item_id' => 1, 'quantity' => 1],
            ]],
        ];

        foreach ($cases as $payload) {
            $this->postJson('/api/v1/checkout/quote', $payload)->assertUnprocessable();
        }

        $tooManyLines = array_fill(0, 51, ['sellable_item_id' => 1, 'quantity' => 1]);
        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => 1,
            'items' => $tooManyLines,
        ])->assertUnprocessable();
    }

    public function test_top_level_total_is_rejected_without_calculating_a_quote(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem(['stock_quantity' => 3]);

        $response = $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
            'total' => '0.00',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('total');
        $this->assertSame(3, $item->refresh()->stock_quantity);
    }

    public function test_arbitrary_top_level_keys_are_rejected_by_the_allowlist(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
            'unexpected_key' => 'not allowed',
        ])->assertUnprocessable()->assertJsonValidationErrors('unexpected_key');
    }

    public function test_nested_unit_price_is_rejected_without_reporting_that_the_item_is_not_an_array(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();

        $response = $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1, 'unit_price' => '1.00']],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_price');
        $errors = $response->json('errors');
        $this->assertArrayHasKey('items.0.unit_price', $errors);
        $this->assertNotContains(
            'The items.0 field must be an array.',
            $errors['items.0.unit_price'],
        );
    }

    public function test_arbitrary_nested_keys_are_rejected_by_the_allowlist(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1, 'unexpected_key' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.unexpected_key');
    }

    public function test_invalid_shipping_areas_fail_the_complete_quote(): void
    {
        $item = $this->sellableItem();
        $areas = [
            null,
            ShippingArea::factory()->create(['is_active' => false]),
            ShippingArea::factory()->create(['is_selectable' => false, 'shipping_fee' => null]),
            ShippingArea::factory()->create(['shipping_fee' => null]),
        ];

        foreach ($areas as $area) {
            $this->postJson('/api/v1/checkout/quote', [
                'shipping_area_id' => $area?->id ?? 999999,
                'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
            ])->assertUnprocessable();
        }
    }

    public function test_catalog_visibility_and_stock_rules_are_reused(): void
    {
        $area = $this->shippingArea(10);
        $cases = [
            $this->sellableItem(['status' => 'inactive']),
            $this->sellableItem(['stock_quantity' => 0]),
            $this->sellableItem(['stock_quantity' => 1]),
        ];
        $cases[2]->product->update(['status' => 'inactive']);

        foreach ($cases as $item) {
            $this->postJson('/api/v1/checkout/quote', [
                'shipping_area_id' => $area->id,
                'items' => [['sellable_item_id' => $item->id, 'quantity' => 2]],
            ])->assertUnprocessable();
        }
    }

    public function test_products_without_an_active_category_are_not_quotable(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();
        $item->product->categories()->firstOrFail()->update(['status' => 'inactive']);

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_products_behind_an_inactive_category_ancestor_are_not_quotable(): void
    {
        $area = $this->shippingArea(10);
        $item = $this->sellableItem();
        $category = $item->product->categories()->firstOrFail();
        $inactiveAncestor = Category::create([
            'slug' => 'quote-inactive-ancestor-'.uniqid(),
            'name_ar' => 'تصنيف',
            'name_en' => 'Inactive ancestor',
            'status' => 'inactive',
        ]);
        $category->update(['parent_id' => $inactiveAncestor->getKey()]);

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [['sellable_item_id' => $item->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_one_invalid_item_fails_without_partial_quote_or_side_effects(): void
    {
        $area = $this->shippingArea(10);
        $valid = $this->sellableItem(['stock_quantity' => 4]);
        $stockBefore = $valid->stock_quantity;

        $this->postJson('/api/v1/checkout/quote', [
            'shipping_area_id' => $area->id,
            'items' => [
                ['sellable_item_id' => $valid->id, 'quantity' => 1],
                ['sellable_item_id' => 999999, 'quantity' => 1],
            ],
        ])->assertUnprocessable();

        $this->assertSame($stockBefore, $valid->refresh()->stock_quantity);
        $this->assertDatabaseCount('shipping_areas', 1);
    }

    private function shippingArea(string|int $fee): ShippingArea
    {
        return ShippingArea::factory()->create(['shipping_fee' => (string) $fee]);
    }

    private function sellableItem(array $attributes = []): SellableItem
    {
        $product = Product::create([
            'slug' => 'product-'.uniqid(),
            'name_ar' => 'منتج عربي',
            'name_en' => 'English Product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $category = Category::create([
            'slug' => 'category-'.uniqid(),
            'name_ar' => 'تصنيف',
            'name_en' => 'Category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);

        return SellableItem::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'price' => '100.00',
            'stock_quantity' => 5,
            'status' => 'active',
            'is_default' => true,
            'sort_order' => 1,
        ], $attributes));
    }
}
