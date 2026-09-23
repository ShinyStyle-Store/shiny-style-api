<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\MediaRole;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\SellableItem;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminProductApiTest extends TestCase
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

    public function test_product_routes_require_admin_authentication(): void
    {
        $product = $this->product('auth-product');
        foreach ([
            ['getJson', '/api/v1/admin/products'],
            ['postJson', '/api/v1/admin/products'],
            ['getJson', '/api/v1/admin/products/'.$product->id],
            ['patchJson', '/api/v1/admin/products/'.$product->id],
            ['postJson', '/api/v1/admin/products/'.$product->id.'/archive'],
            ['postJson', '/api/v1/admin/products/'.$product->id.'/restore'],
        ] as [$method, $uri]) {
            $this->{$method}($uri)->assertUnauthorized();
        }
    }

    public function test_create_makes_a_draft_with_categories_and_one_primary_without_variants(): void
    {
        $first = $this->category('first');
        $second = $this->category('second');

        $response = $this->withToken($this->adminToken)->postJson('/api/v1/admin/products', [
            'name_ar' => 'منتج جديد',
            'name_en' => 'New Product',
            'category_ids' => [$first->id, $second->id],
            'primary_category_id' => $second->id,
        ])->assertCreated();

        $product = Product::findOrFail($response->json('data.id'));
        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.primaryCategoryId', $second->id)
            ->assertJsonPath('data.slug', 'new-product');
        $this->assertSame(0, $product->sellableItems()->count());
        $this->assertDatabaseCount('product_options', 0);
        $this->assertDatabaseCount('media_attachments', 0);
        $this->assertDatabaseHas('category_product', ['product_id' => $product->id, 'category_id' => $first->id, 'is_primary' => false]);
        $this->assertDatabaseHas('category_product', ['product_id' => $product->id, 'category_id' => $second->id, 'is_primary' => true]);
    }

    public function test_create_rejects_duplicate_or_unusable_categories_and_protected_fields(): void
    {
        $category = $this->category('valid');
        $archived = $this->category('archived');
        $archived->delete();

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/products', [
            'name_ar' => 'منتج', 'name_en' => 'Product',
            'category_ids' => [$category->id, $category->id],
            'price' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors(['category_ids.1', 'price']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/products', [
            'name_ar' => 'منتج', 'name_en' => 'Product', 'category_ids' => [$archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('category_ids.0');
    }

    public function test_partial_update_preserves_fields_and_category_sync_is_idempotent(): void
    {
        $first = $this->category('first');
        $second = $this->category('second');
        $product = $this->product('update-product', ['description_en' => 'Keep this']);
        $product->categories()->sync([$first->id => ['is_primary' => true]]);

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'name_en' => 'Changed',
            'category_ids' => [$first->id, $second->id],
            'primary_category_id' => $second->id,
        ])->assertOk()->assertJsonPath('data.descriptionEn', 'Keep this');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'category_ids' => [$first->id, $second->id], 'primary_category_id' => $second->id,
        ])->assertOk();

        $this->assertDatabaseCount('category_product', 2);
        $this->assertDatabaseHas('category_product', ['product_id' => $product->id, 'category_id' => $second->id, 'is_primary' => true]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'description_en' => 'Keep this']);
    }

    public function test_active_or_published_product_requires_catalog_invariants(): void
    {
        $product = $this->product('incomplete');
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $category = $this->category('ready');
        $product->categories()->sync([$category->id => ['is_primary' => true]]);
        SellableItem::create([
            'product_id' => $product->id, 'sku' => 'SKU-'.Str::upper(Str::random(8)),
            'price' => 10, 'stock_quantity' => 1, 'status' => 'active', 'is_default' => true,
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'status' => 'active', 'published_at' => now()->subMinute()->toISOString(),
        ])->assertOk();
    }

    public function test_archive_and_restore_preserve_relationships_and_never_publish_on_restore(): void
    {
        $category = $this->category('archive-category');
        $product = $this->product('archive-product', ['status' => 'active', 'published_at' => now()->subDay()]);
        $product->categories()->sync([$category->id => ['is_primary' => true]]);
        $item = SellableItem::create([
            'product_id' => $product->id, 'sku' => 'ARCHIVE-SKU', 'price' => 12,
            'stock_quantity' => 5, 'status' => 'active', 'is_default' => true,
        ]);

        $this->withToken($this->adminToken)->postJson('/api/v1/admin/products/'.$product->id.'/archive')->assertOk();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('sellable_items', ['id' => $item->id, 'product_id' => $product->id]);
        $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/products/'.$product->id.'/restore')
            ->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.publishedAt', null);
        $this->assertDatabaseHas('category_product', ['product_id' => $product->id, 'category_id' => $category->id]);
    }

    public function test_admin_index_search_filters_and_includes_incomplete_products(): void
    {
        $category = $this->category('filter-category');
        $product = $this->product('بحث-منتج', ['name_ar' => 'منتج عربي', 'name_en' => 'Arabic Product']);
        $product->categories()->sync([$category->id => ['is_primary' => true]]);

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/products?search='.urlencode('عربي').'&category_id='.$category->id.'&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.id', $product->id);
    }

    public function test_product_detail_returns_operational_summary_without_embedding_collections(): void
    {
        $product = $this->product('summary-product');
        $first = SellableItem::create([
            'product_id' => $product->id, 'sku' => 'SUMMARY-FIRST', 'price' => '450.00',
            'stock_quantity' => 12, 'reserved_quantity' => 3, 'status' => 'active',
        ]);
        SellableItem::create([
            'product_id' => $product->id, 'sku' => 'SUMMARY-INACTIVE', 'price' => '999.00',
            'stock_quantity' => 100, 'reserved_quantity' => 0, 'status' => 'inactive',
        ]);
        $archived = SellableItem::create([
            'product_id' => $product->id, 'sku' => 'SUMMARY-ARCHIVED', 'price' => '1.00',
            'stock_quantity' => 100, 'reserved_quantity' => 0, 'status' => 'active',
        ]);
        $archived->delete();

        $asset = MediaAsset::create([
            'disk' => 'public', 'path' => 'products/summary.jpg', 'original_name' => 'summary.jpg',
            'media_type' => 'image', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        $attachment = app(MediaService::class)->attach($asset, $product, MediaRole::PRODUCT_IMAGE, [
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.optionsCount', 0)
            ->assertJsonPath('data.hasOptions', false)
            ->assertJsonPath('data.variantsCount', 2)
            ->assertJsonPath('data.activeVariantsCount', 1)
            ->assertJsonPath('data.priceRange.min', '450.00')
            ->assertJsonPath('data.priceRange.max', '450.00')
            ->assertJsonPath('data.stock.total', 12)
            ->assertJsonPath('data.stock.reserved', 3)
            ->assertJsonPath('data.stock.available', 9)
            ->assertJsonPath('data.inStock', true)
            ->assertJsonPath('data.primaryImage.id', $attachment->id)
            ->assertJsonPath('data.primaryImage.role', MediaRole::PRODUCT_IMAGE)
            ->assertJsonPath('data.primaryImage.is_primary', true)
            ->assertJsonPath('data.primaryImage.kind', 'image')
            ->assertJsonStructure(['data' => ['primaryImage' => ['id', 'asset_public_id', 'kind', 'role', 'url']]])
            ->assertJsonMissingPath('data.options')
            ->assertJsonMissingPath('data.sellableItems')
            ->assertJsonMissingPath('data.media');

        $this->assertDatabaseHas('sellable_items', ['id' => $first->id]);
        $this->assertDatabaseHas('media_attachments', [
            'id' => $attachment->id,
            'mediable_type' => $product->getMorphClass(),
            'mediable_id' => $product->id,
            'role' => MediaRole::PRODUCT_IMAGE,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'media_type' => 'image']);
    }

    public function test_product_detail_option_summary_ignores_archived_options_and_empty_active_summary_is_safe(): void
    {
        $product = $this->product('option-summary');
        ProductOption::create([
            'product_id' => $product->id, 'code' => 'color', 'name_ar' => 'اللون', 'name_en' => 'Color',
        ]);
        $archived = ProductOption::create([
            'product_id' => $product->id, 'code' => 'size', 'name_ar' => 'المقاس', 'name_en' => 'Size',
        ]);
        $archived->delete();

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.hasOptions', true)
            ->assertJsonPath('data.optionsCount', 1)
            ->assertJsonPath('data.priceRange', null)
            ->assertJsonPath('data.stock', ['total' => 0, 'reserved' => 0, 'available' => 0])
            ->assertJsonPath('data.inStock', false)
            ->assertJsonPath('data.primaryImage', null);
    }

    private function category(string $name): Category
    {
        return Category::create([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name_ar' => 'تصنيف '.$name, 'name_en' => 'Category '.$name,
            'status' => 'active', 'sort_order' => 0,
        ]);
    }

    private function product(string $slug, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'slug' => Str::slug($slug).'-'.Str::lower(Str::random(6)),
            'name_ar' => 'منتج '.$slug, 'name_en' => 'Product '.$slug,
            'status' => 'draft', 'is_featured' => false, 'published_at' => null,
        ], $attributes));
    }
}
