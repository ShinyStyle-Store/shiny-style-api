<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\SellableItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSellableItemApiTest extends TestCase
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

    public function test_variant_routes_require_admin_and_are_product_owned(): void
    {
        $first = $this->product('first');
        $second = $this->product('second');
        $variant = $this->variant($first);

        $this->getJson("/api/v1/admin/products/{$first->id}/sellable-items")->assertUnauthorized();
        $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/products/{$second->id}/sellable-items/{$variant->id}")
            ->assertNotFound();
    }

    public function test_create_returns_exact_money_stock_and_localized_option_values(): void
    {
        [$product, $values] = $this->configuredProduct();
        $response = $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", [
            'sku' => '  THROW-BEIGE-M  ', 'price' => '450.00', 'original_price' => '500.00',
            'stock_quantity' => 10, 'status' => 'active', 'is_default' => true, 'sort_order' => 4,
            'option_value_ids' => [$values['color']->id, $values['size']->id],
        ])->assertCreated();

        $response->assertJsonPath('data.sku', 'THROW-BEIGE-M')
            ->assertJsonPath('data.price', '450.00')
            ->assertJsonPath('data.originalPrice', '500.00')
            ->assertJsonPath('data.stockQuantity', 10)
            ->assertJsonPath('data.reservedQuantity', 0)
            ->assertJsonPath('data.availableQuantity', 10)
            ->assertJsonPath('data.optionValues.0.valueEn', 'Beige');
    }

    public function test_products_without_options_allow_empty_assignments_and_numeric_labels_remain_strings(): void
    {
        $plain = $this->product('plain');
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$plain->id}/sellable-items", [
            'sku' => 'PLAIN-MISSING', 'price' => '4500.00', 'stock_quantity' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('option_value_ids');

        $configuredWithoutValues = $this->configuredProduct();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$configuredWithoutValues[0]->id}/sellable-items", [
            'sku' => 'INCOMPLETE', 'price' => '10.00', 'stock_quantity' => 1, 'option_value_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('option_value_ids');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$plain->id}/sellable-items", [
            'sku' => 'PLAIN-001', 'price' => '4500.00', 'stock_quantity' => 3, 'option_value_ids' => [],
        ])->assertCreated()->assertJsonPath('data.optionValues', []);

        [$product, $values] = $this->configuredProduct();
        $number = $this->value($values['size']->option, ['code' => '38', 'value_ar' => '٣٨', 'value_en' => '38']);
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", [
            'sku' => 'SIZE-38', 'price' => '38.00', 'stock_quantity' => 1,
            'option_value_ids' => [$values['color']->id, $number->id],
        ])->assertCreated()->assertJsonPath('data.optionValues.1.valueEn', '38');
    }

    public function test_invalid_assignments_and_duplicate_combinations_are_rejected(): void
    {
        [$product, $values] = $this->configuredProduct();
        $this->createVariantThroughApi($product, [$values['color']->id, $values['size']->id], 'FIRST');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", [
            'sku' => 'REVERSE', 'price' => '10.00', 'stock_quantity' => 1,
            'option_value_ids' => [$values['size']->id, $values['color']->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('option_value_ids');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", [
            'sku' => 'DUPLICATE-ID', 'price' => '10.00', 'stock_quantity' => 1,
            'option_value_ids' => [$values['color']->id, $values['color']->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('option_value_ids.1');

        $foreign = $this->configuredProduct();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", [
            'sku' => 'FOREIGN', 'price' => '10.00', 'stock_quantity' => 1,
            'option_value_ids' => [$foreign[1]['color']->id, $values['size']->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('option_value_ids');
    }

    public function test_first_combination_is_not_reported_as_a_duplicate_and_duplicate_is_product_scoped(): void
    {
        [$product, $values] = $this->configuredProduct();
        $this->assertSame(0, SellableItem::withTrashed()->where('product_id', $product->id)->count());

        $first = $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", [
            'sku' => 'FIRST-COMBINATION', 'price' => '10.00', 'stock_quantity' => 1,
            'option_value_ids' => [$values['color']->id, $values['size']->id],
        ])->assertCreated();

        $this->assertDatabaseHas('sellable_items', ['id' => $first->json('data.id'), 'product_id' => $product->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", [
            'sku' => 'SECOND-COMBINATION', 'price' => '11.00', 'stock_quantity' => 1,
            'option_value_ids' => [$values['size']->id, $values['color']->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('option_value_ids');

        [$otherProduct, $otherValues] = $this->configuredProduct();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$otherProduct->id}/sellable-items", [
            'sku' => 'OTHER-PRODUCT-COMBINATION', 'price' => '10.00', 'stock_quantity' => 1,
            'option_value_ids' => [$otherValues['color']->id, $otherValues['size']->id],
        ])->assertCreated();
    }

    public function test_patch_is_partial_respects_reservations_and_switches_default_atomically(): void
    {
        $product = $this->product('plain');
        $first = $this->createVariantThroughApi($product, [], 'FIRST', ['is_default' => true, 'stock_quantity' => 5]);
        $second = $this->createVariantThroughApi($product, [], 'SECOND', ['stock_quantity' => 7]);
        SellableItem::findOrFail($first->json('data.id'))->update(['reserved_quantity' => 4]);

        $this->withToken($this->adminToken)->patchJson("/api/v1/admin/products/{$product->id}/sellable-items/{$first->json('data.id')}", [
            'stock_quantity' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson("/api/v1/admin/products/{$product->id}/sellable-items/{$second->json('data.id')}", [
            'is_default' => true, 'price' => '12.50',
        ])->assertOk()->assertJsonPath('data.sku', 'SECOND');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson("/api/v1/admin/products/{$product->id}/sellable-items/{$second->json('data.id')}", [
            'option_value_ids' => [],
        ])->assertOk()->assertJsonPath('data.optionValues', []);

        $this->assertDatabaseHas('sellable_items', ['id' => $first->json('data.id'), 'is_default' => false, 'stock_quantity' => 5]);
        $this->assertDatabaseHas('sellable_items', ['id' => $second->json('data.id'), 'is_default' => true, 'price' => '12.50']);
    }

    public function test_patch_preserves_or_validates_option_assignments_by_product_state(): void
    {
        [$product, $values] = $this->configuredProduct();
        $created = $this->createVariantThroughApi($product, [$values['color']->id, $values['size']->id], 'PATCH-KEEP');

        $this->withToken($this->adminToken)->patchJson("/api/v1/admin/products/{$product->id}/sellable-items/{$created->json('data.id')}", [
            'price' => '11.00',
        ])->assertOk()->assertJsonCount(2, 'data.optionValues');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson("/api/v1/admin/products/{$product->id}/sellable-items/{$created->json('data.id')}", [
            'option_value_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('option_value_ids');
    }

    public function test_archive_preserves_pivots_and_restore_does_not_reactivate(): void
    {
        [$product, $values] = $this->configuredProduct();
        $response = $this->createVariantThroughApi($product, [$values['color']->id, $values['size']->id], 'ARCHIVE');
        $id = $response->json('data.id');

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items/{$id}/archive")->assertNoContent();
        $this->assertSoftDeleted('sellable_items', ['id' => $id]);
        $this->assertDatabaseHas('sellable_item_option_values', ['sellable_item_id' => $id, 'product_option_value_id' => $values['color']->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items/{$id}/restore")
            ->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_reserved_variant_cannot_be_archived_and_unknown_fields_are_rejected(): void
    {
        $product = $this->product('reserved');
        $variant = $this->variant($product, ['reserved_quantity' => 2, 'stock_quantity' => 2]);
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items/{$variant->id}/archive")
            ->assertConflict()->assertJsonPath('code', 'variant_has_reservations');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", [
            'sku' => 'UNKNOWN', 'price' => '10.00', 'stock_quantity' => 1, 'option_value_ids' => [], 'reserved_quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('reserved_quantity');
    }

    private function configuredProduct(): array
    {
        $product = $this->product('configured');
        $color = ProductOption::create(['product_id' => $product->id, 'code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color']);
        $size = ProductOption::create(['product_id' => $product->id, 'code' => 'size', 'name_ar' => 'المقاس', 'name_en' => 'Size']);

        return [$product, [
            'color' => $this->value($color, ['code' => 'beige', 'value_ar' => 'بيج', 'value_en' => 'Beige']),
            'size' => $this->value($size, ['code' => 'medium', 'value_ar' => 'متوسط', 'value_en' => 'Medium']),
        ]];
    }

    private function createVariantThroughApi(Product $product, array $valueIds, string $sku, array $extra = [])
    {
        return $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/sellable-items", array_merge([
            'sku' => $sku, 'price' => '10.00', 'stock_quantity' => 1, 'option_value_ids' => $valueIds,
        ], $extra))->assertCreated();
    }

    private function product(string $suffix): Product
    {
        return Product::create(['slug' => $suffix.'-'.Str::lower(Str::random(8)), 'name_ar' => 'منتج '.$suffix, 'name_en' => 'Product '.$suffix, 'status' => 'draft']);
    }

    private function option(Product $product): ProductOption
    {
        return ProductOption::create(['product_id' => $product->id, 'code' => 'size', 'name_ar' => 'المقاس', 'name_en' => 'Size']);
    }

    private function value(ProductOption $option, array $attributes = []): ProductOptionValue
    {
        return ProductOptionValue::create(array_merge([
            'product_option_id' => $option->id, 'code' => 'value-'.Str::lower(Str::random(6)),
            'value_ar' => 'قيمة', 'value_en' => 'Value',
        ], $attributes, ['product_option_id' => $option->id]));
    }

    private function variant(Product $product, array $attributes = []): SellableItem
    {
        return SellableItem::create(array_merge([
            'product_id' => $product->id, 'sku' => 'SKU-'.Str::upper(Str::random(8)), 'price' => '10.00',
            'stock_quantity' => 1, 'reserved_quantity' => 0, 'status' => 'inactive', 'is_default' => false,
        ], $attributes, ['product_id' => $product->id]));
    }
}
