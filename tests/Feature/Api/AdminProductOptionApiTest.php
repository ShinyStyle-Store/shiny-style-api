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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProductOptionApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create([
            'user_id' => $admin->getKey(),
            'status' => AdminMembershipStatus::Active,
        ]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_nested_option_and_value_routes_require_admin_authentication(): void
    {
        $product = $this->product();
        $option = $this->option($product);
        $value = $this->value($option);

        foreach ([
            ['getJson', "/api/v1/admin/products/{$product->id}/options"],
            ['postJson', "/api/v1/admin/products/{$product->id}/options"],
            ['getJson', "/api/v1/admin/products/{$product->id}/options/{$option->id}"],
            ['getJson', "/api/v1/admin/products/{$product->id}/options/{$option->id}/values"],
            ['getJson', "/api/v1/admin/products/{$product->id}/options/{$option->id}/values/{$value->id}"],
            ['getJson', '/api/v1/admin/catalog/colors'],
        ] as [$method, $uri]) {
            $this->{$method}($uri)->assertUnauthorized();
        }

        $nonAdmin = User::factory()->create(['is_active' => true]);
        $this->withToken($nonAdmin->createToken('non-admin', ['admin-access'])->plainTextToken)
            ->getJson('/api/v1/admin/catalog/colors')->assertForbidden();
    }

    public function test_create_option_and_numeric_string_value_preserves_localized_editable_fields(): void
    {
        $product = $this->product();
        $optionResponse = $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options", [
            'code' => 'size', 'name_ar' => '  المقاس  ', 'name_en' => ' Size ', 'sort_order' => 2,
        ])->assertCreated()->assertJsonPath('data.nameEn', 'Size');
        $optionId = $optionResponse->json('data.id');

        $valueResponse = $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$optionId}/values", [
            'code' => '38', 'value_ar' => '  ٣٨ ', 'value_en' => ' 38 ',
        ])->assertCreated()->assertJsonPath('data.valueEn', '38');

        $this->assertIsString($valueResponse->json('data.valueEn'));
        $this->assertSame('38', $valueResponse->json('data.valueEn'));
        $this->getJson("/api/v1/products/{$product->slug}")->assertNotFound();
    }

    public function test_duplicates_are_scoped_to_parent_and_unknown_fields_are_rejected(): void
    {
        $firstProduct = $this->product('first');
        $secondProduct = $this->product('second');
        $firstOption = $this->option($firstProduct, [
            'code' => 'existing-size',
            'name_ar' => 'المقاس الأساسي',
            'name_en' => 'Size',
        ]);

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$firstProduct->id}/options", [
            'code' => 'duplicate-size-name', 'name_ar' => 'مقاس مختلف', 'name_en' => ' size ',
        ])->assertUnprocessable()->assertJsonValidationErrors('name_en')
            ->assertJsonMissingValidationErrors(['code', 'name_ar']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$firstProduct->id}/options", [
            'code' => 'unknown-field-option', 'name_ar' => 'مقاس', 'name_en' => 'Other', 'product_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$secondProduct->id}/options", [
            'code' => 'other-product-size', 'name_ar' => 'المقاس', 'name_en' => 'Size',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$firstProduct->id}/options", [
            'code' => 'existing-size', 'name_ar' => 'اسم مختلف', 'name_en' => 'Different name',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertSame($firstProduct->id, $firstOption->product_id);
    }

    public function test_value_duplicates_are_scoped_to_option_and_patch_is_partial(): void
    {
        $product = $this->product();
        $option = $this->option($product);
        $otherOption = $this->option($product, ['code' => 'material', 'name_en' => 'Material']);
        $value = $this->value($option, [
            'code' => 'existing-large',
            'value_ar' => 'الكبير الأساسي',
            'value_en' => 'Large',
        ]);

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/values", [
            'code' => 'duplicate-large-name', 'value_ar' => 'قيمة كبيرة مختلفة', 'value_en' => ' large ',
        ])->assertUnprocessable()->assertJsonValidationErrors('value_en')
            ->assertJsonMissingValidationErrors(['code', 'value_ar']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$otherOption->id}/values", [
            'code' => 'other-option-large', 'value_ar' => 'كبير', 'value_en' => 'Large',
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/values", [
            'code' => 'existing-large', 'value_ar' => 'قيمة مختلفة', 'value_en' => 'Different value',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/values/{$value->id}", [
            'metadata' => ['hex' => '#fff'],
        ])->assertOk()->assertJsonPath('data.valueEn', 'Large')
            ->assertJsonPath('data.metadata.hex', '#fff');
    }

    public function test_color_codes_are_canonical_and_reused_translations_must_match(): void
    {
        $firstProduct = $this->product('first-color');
        $secondProduct = $this->product('second-color');
        $url = "/api/v1/admin/products/{$firstProduct->id}/options";

        $this->withToken($this->adminToken)->postJson($url, [
            'code' => 'colors', 'name_ar' => 'الألوان', 'name_en' => 'Colors',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $optionResponse = $this->withToken($this->adminToken)->postJson($url, [
            'code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color',
        ])->assertCreated();
        $colorOption = ProductOption::query()->findOrFail($optionResponse->json('data.id'));

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$firstProduct->id}/options/{$colorOption->id}/values", [
            'code' => 'navy_blue', 'value_ar' => 'كحلي', 'value_en' => 'Navy blue',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$firstProduct->id}/options/{$colorOption->id}/values", [
            'code' => 'navy-blue', 'value_ar' => 'كحلي', 'value_en' => 'Navy blue',
        ])->assertCreated();

        $secondOption = $this->option($secondProduct, [
            'code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color',
        ]);
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$secondProduct->id}/options/{$secondOption->id}/values", [
            'code' => 'navy-blue', 'value_ar' => 'أزرق داكن', 'value_en' => 'Dark navy',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$secondProduct->id}/options/{$secondOption->id}/values", [
            'code' => 'navy-blue', 'value_ar' => 'كحلي', 'value_en' => 'Navy blue',
        ])->assertCreated();

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$firstProduct->id}/options", [
            'code' => 'sleeve_length', 'name_ar' => 'طول الكم', 'name_en' => 'Sleeve length',
        ])->assertCreated();
    }

    public function test_admin_color_suggestions_include_nonpublic_products_and_do_not_write(): void
    {
        $draft = $this->product('color-draft');
        $inactive = $this->product('color-inactive', ['status' => 'inactive']);
        $draftOption = $this->option($draft, ['code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color']);
        $inactiveOption = $this->option($inactive, ['code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color']);
        $this->value($draftOption, ['code' => 'beige', 'value_ar' => 'بيج', 'value_en' => 'Beige']);
        $this->value($inactiveOption, ['code' => 'grey', 'value_ar' => 'رمادي', 'value_en' => 'Grey']);
        $before = [
            'products' => DB::table('products')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'options' => DB::table('product_options')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'values' => DB::table('product_option_values')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'audits' => DB::table('seo_audits')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ];

        $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/catalog/colors')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['code' => 'beige', 'nameAr' => 'بيج', 'nameEn' => 'Beige'],
                ['code' => 'grey', 'nameAr' => 'رمادي', 'nameEn' => 'Grey'],
            ]]);
        $this->assertArrayNotHasKey('id', $response->json('data.0'));
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/catalog/colors?unexpected=1')
            ->assertUnprocessable();
        $this->assertSame($before, [
            'products' => DB::table('products')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'options' => DB::table('product_options')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'values' => DB::table('product_option_values')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            'audits' => DB::table('seo_audits')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        ]);
    }

    public function test_admin_color_suggestions_report_legacy_translation_conflicts(): void
    {
        $firstOption = $this->option($this->product('legacy-color-first'), [
            'code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color',
        ]);
        $secondOption = $this->option($this->product('legacy-color-second'), [
            'code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color',
        ]);
        $this->value($firstOption, ['code' => 'beige', 'value_ar' => 'بيج', 'value_en' => 'Beige']);
        $this->value($secondOption, ['code' => 'beige', 'value_ar' => 'رملي', 'value_en' => 'Sand']);

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/catalog/colors')
            ->assertConflict()
            ->assertJsonPath('message', 'Legacy color records require canonical codes and consistent translations.')
            ->assertJsonPath('conflicts.0.code', 'beige')
            ->assertJsonCount(2, 'conflicts.0.translations');
    }

    public function test_legacy_color_options_and_values_cannot_be_restored_with_noncanonical_codes(): void
    {
        $product = $this->product('legacy-color-restore');
        $alias = $this->option($product, [
            'code' => 'colors', 'name_ar' => 'الألوان', 'name_en' => 'Colors',
        ]);
        $alias->delete();
        $this->withToken($this->adminToken)
            ->postJson("/api/v1/admin/products/{$product->id}/options/{$alias->id}/restore")
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $color = $this->option($product, [
            'code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color',
        ]);
        $legacyValue = $this->value($color, [
            'code' => 'navy_blue', 'value_ar' => 'كحلي', 'value_en' => 'Navy blue',
        ]);
        $legacyValue->delete();
        $this->withToken($this->adminToken)
            ->postJson("/api/v1/admin/products/{$product->id}/options/{$color->id}/values/{$legacyValue->id}/restore")
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_cross_parent_resources_return_not_found(): void
    {
        $firstProduct = $this->product('first');
        $secondProduct = $this->product('second');
        $firstOption = $this->option($firstProduct);
        $value = $this->value($firstOption);
        $secondOption = $this->option($secondProduct);

        $this->withToken($this->adminToken)->getJson("/api/v1/admin/products/{$secondProduct->id}/options/{$firstOption->id}")->assertNotFound();
        $this->withToken($this->adminToken)->getJson("/api/v1/admin/products/{$secondProduct->id}/options/{$secondOption->id}/values/{$value->id}")->assertNotFound();
    }

    public function test_referenced_options_and_values_cannot_be_archived(): void
    {
        $product = $this->product('referenced-option', ['status' => 'active']);
        $option = $this->option($product);
        $value = $this->value($option);
        SellableItem::create([
            'product_id' => $product->id, 'sku' => 'OPTION-'.Str::upper(Str::random(8)),
            'price' => 10, 'stock_quantity' => 2, 'status' => 'active', 'is_default' => true,
        ])->optionValues()->sync([$value->id]);

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/values/{$value->id}/archive")
            ->assertConflict()->assertJsonPath('code', 'value_in_use');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/archive")
            ->assertConflict()->assertJsonPath('code', 'option_in_use');
        $this->assertDatabaseHas('sellable_item_option_values', [
            'product_option_value_id' => $value->id,
        ]);
    }

    public function test_unused_resources_archive_restore_and_order_deterministically(): void
    {
        $product = $this->product();
        $option = $this->option($product, ['sort_order' => 3]);
        $value = $this->value($option, ['sort_order' => 4]);

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/999/archive")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/values/{$value->id}/archive")->assertNoContent();
        $this->assertSoftDeleted('product_option_values', ['id' => $value->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/values/{$value->id}/restore")
            ->assertOk()->assertJsonPath('data.id', $value->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/archive")->assertNoContent();
        $this->assertSoftDeleted('product_options', ['id' => $option->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/restore")
            ->assertOk()->assertJsonPath('data.id', $option->id);
    }

    public function test_restoring_an_option_that_makes_an_active_product_incomplete_rolls_back(): void
    {
        $product = $this->product('restore-incomplete', ['status' => 'active']);
        $option = $this->option($product);
        $this->value($option);
        $item = SellableItem::create([
            'product_id' => $product->id, 'sku' => 'RESTORE-INCOMPLETE-'.Str::upper(Str::random(5)),
            'price' => 10, 'stock_quantity' => 1, 'status' => 'active', 'is_default' => true,
        ]);

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/archive")
            ->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/restore")
            ->assertUnprocessable()->assertJsonValidationErrors("sellable_items.{$item->id}.option_value_ids");

        $this->assertSoftDeleted('product_options', ['id' => $option->id]);
    }

    public function test_archiving_an_unused_option_cannot_leave_an_active_product_with_multiple_simple_variants(): void
    {
        $product = $this->product('archive-option-readiness', ['status' => 'active']);
        $option = $this->option($product);
        $this->value($option);
        foreach (['ARCHIVE-ONE', 'ARCHIVE-TWO'] as $sku) {
            SellableItem::create([
                'product_id' => $product->id, 'sku' => $sku, 'price' => 10,
                'stock_quantity' => 1, 'status' => 'active', 'is_default' => $sku === 'ARCHIVE-ONE',
            ]);
        }

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options/{$option->id}/archive")
            ->assertUnprocessable()->assertJsonValidationErrors('sellable_items');

        $this->assertDatabaseHas('product_options', ['id' => $option->id, 'deleted_at' => null]);
    }

    public function test_active_product_with_existing_variants_cannot_gain_a_new_option(): void
    {
        $product = $this->product('active', ['status' => 'active']);
        SellableItem::create([
            'product_id' => $product->id, 'sku' => 'ACTIVE-'.Str::upper(Str::random(8)),
            'price' => 10, 'stock_quantity' => 1, 'status' => 'active', 'is_default' => true,
        ]);

        $this->withToken($this->adminToken)->postJson("/api/v1/admin/products/{$product->id}/options", [
            'code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color',
        ])->assertUnprocessable()->assertJsonValidationErrors('product');
    }

    private function product(string $suffix = 'product', array $attributes = []): Product
    {
        return Product::create(array_merge([
            'slug' => $suffix.'-'.Str::lower(Str::random(8)),
            'name_ar' => 'منتج '.$suffix, 'name_en' => 'Product '.$suffix,
            'status' => 'draft', 'is_featured' => false,
        ], $attributes));
    }

    private function option(Product $product, array $attributes = []): ProductOption
    {
        return ProductOption::create(array_merge([
            'product_id' => $product->id,
            'code' => 'size-'.Str::lower(Str::random(5)),
            'name_ar' => 'المقاس '.Str::random(5), 'name_en' => 'Size '.Str::random(5),
            'sort_order' => 0,
        ], $attributes, ['product_id' => $product->id]));
    }

    private function value(ProductOption $option, array $attributes = []): ProductOptionValue
    {
        return ProductOptionValue::create(array_merge([
            'product_option_id' => $option->id,
            'code' => 'large-'.Str::lower(Str::random(5)),
            'value_ar' => 'كبير '.Str::random(5), 'value_en' => 'Large '.Str::random(5),
            'sort_order' => 0,
        ], $attributes, ['product_option_id' => $option->id]));
    }
}
