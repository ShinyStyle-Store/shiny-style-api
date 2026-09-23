<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\MediaRole;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\SellableItem;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
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

    public function test_create_and_update_store_normalized_external_video_url_without_media_rows(): void
    {
        $response = $this->withToken($this->adminToken)->postJson('/api/v1/admin/products', [
            'name_ar' => 'منتج فيديو',
            'name_en' => 'Video Product',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10',
        ])->assertCreated()
            ->assertJsonPath('data.videoUrl', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

        $product = Product::findOrFail($response->json('data.id'));
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $product->video_url);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media_attachments', 0);

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'video_url' => 'https://player.vimeo.com/video/123456789',
        ])->assertOk()
            ->assertJsonPath('data.videoUrl', 'https://player.vimeo.com/video/123456789');

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [])
            ->assertUnprocessable();

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'video_url' => null,
        ])->assertOk()
            ->assertJsonPath('data.videoUrl', null);
    }

    public function test_invalid_external_video_url_does_not_change_product(): void
    {
        $product = $this->product('external-video');
        $product->update(['video_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ']);

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'video_url' => 'https://youtube.com.attacker.example/embed/dQw4w9WgXcQ',
        ])->assertUnprocessable()->assertJsonValidationErrors('video_url');

        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $product->refresh()->video_url);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media_attachments', 0);
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

    public function test_multipart_product_creation_uploads_primary_and_ordered_gallery_images(): void
    {
        Storage::fake('admin-product-media-test');
        config(['media.disk' => 'admin-product-media-test']);
        $category = $this->category('multipart');

        $response = $this->withToken($this->adminToken)->post('/api/v1/admin/products', [
            'name_ar' => 'Ù…Ù†ØªØ¬ Ù…Ù„Ù',
            'name_en' => 'Multipart Product',
            'status' => 'draft',
            'is_featured' => '0',
            'category_ids' => [(string) $category->id],
            'primary_category_id' => (string) $category->id,
            'features' => json_encode(['Soft fabric']),
            'specifications' => json_encode(['material' => 'Cotton']),
            'primary_image' => UploadedFile::fake()->createWithContent('primary.jpg', $this->imageBytes()),
            'gallery_images' => [
                UploadedFile::fake()->createWithContent('gallery-a.jpg', $this->imageBytes()),
                UploadedFile::fake()->createWithContent('gallery-b.jpg', $this->imageBytes()),
            ],
        ])->assertCreated();

        $product = Product::findOrFail($response->json('data.id'));
        $attachments = $product->productImages()->with('mediaAsset')->get();

        $this->assertCount(3, $attachments);
        $this->assertSame([0, 1, 2], $attachments->pluck('sort_order')->all());
        $this->assertSame(1, $attachments->where('is_primary', true)->count());
        $this->assertTrue($attachments->first()->is_primary);
        $this->assertSame('product', $attachments->first()->mediable_type);
        $this->assertSame('image', $attachments->first()->mediaAsset->media_type);
        $response->assertJsonPath('data.primaryImage.id', $attachments->first()->id);
    }

    public function test_multipart_gallery_first_image_becomes_primary_when_primary_is_omitted(): void
    {
        Storage::fake('admin-product-media-test');
        config(['media.disk' => 'admin-product-media-test']);

        $response = $this->withToken($this->adminToken)->post('/api/v1/admin/products', [
            'name_ar' => 'Ù…Ù†ØªØ¬ ØµÙˆØ±',
            'name_en' => 'Gallery Product',
            'gallery_images' => [
                UploadedFile::fake()->createWithContent('first.jpg', $this->imageBytes()),
                UploadedFile::fake()->createWithContent('second.jpg', $this->imageBytes()),
            ],
        ])->assertCreated();

        $attachments = Product::findOrFail($response->json('data.id'))->productImages()->get();
        $this->assertSame(0, $attachments->first()->sort_order);
        $this->assertTrue($attachments->first()->is_primary);
        $this->assertSame(1, $attachments->where('is_primary', true)->count());
    }

    public function test_multipart_product_rejects_malformed_json_and_does_not_store_media(): void
    {
        Storage::fake('admin-product-media-test');
        config(['media.disk' => 'admin-product-media-test']);

        $this->withToken($this->adminToken)->post('/api/v1/admin/products', [
            'name_ar' => 'Ù…Ù†ØªØ¬ Ø®Ø§Ø·Ø¦',
            'name_en' => 'Invalid Multipart Product',
            'features' => '{invalid-json',
            'primary_image' => UploadedFile::fake()->createWithContent('invalid.jpg', $this->imageBytes()),
        ])->assertUnprocessable()->assertJsonValidationErrors('features');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media_attachments', 0);
    }

    public function test_multipart_product_enforces_the_configured_image_limit(): void
    {
        Storage::fake('admin-product-media-test');
        config(['media.disk' => 'admin-product-media-test', 'media.images.max_product_images_per_create' => 1]);

        $this->withToken($this->adminToken)->post('/api/v1/admin/products', [
            'name_ar' => 'Ù…Ù†ØªØ¬ Ø­Ø¯',
            'name_en' => 'Limited Images Product',
            'gallery_images' => [
                UploadedFile::fake()->createWithContent('one.jpg', $this->imageBytes()),
                UploadedFile::fake()->createWithContent('two.jpg', $this->imageBytes()),
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('gallery_images');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('media_assets', 0);
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

    public function test_update_can_select_and_remove_product_images_with_deterministic_ordering(): void
    {
        $product = $this->product('media-update');
        $first = $this->productImage($product, 'first.jpg', 0, true);
        $second = $this->productImage($product, 'second.jpg', 1, false);
        $third = $this->productImage($product, 'third.jpg', 2, false);

        $response = $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'primary_attachment_id' => $third->id,
            'remove_attachment_ids' => [$second->id],
        ])->assertOk()
            ->assertJsonPath('data.primaryImage.id', $third->id);

        $images = $product->fresh()->productImages()->get();
        $this->assertSame([$third->id, $first->id], $images->modelKeys());
        $this->assertSame([0, 1], $images->pluck('sort_order')->all());
        $this->assertSame(1, $images->where('is_primary', true)->count());
        $this->assertTrue($images->first()->is_primary);
        $this->assertSame($third->id, $response->json('data.primaryImage.id'));
        $this->assertDatabaseMissing('media_attachments', ['id' => $second->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'remove_attachment_ids' => [$third->id],
        ])->assertOk()->assertJsonPath('data.primaryImage.id', $first->id);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'remove_attachment_ids' => [$first->id],
        ])->assertOk()->assertJsonPath('data.primaryImage', null);
    }

    public function test_update_with_new_primary_preserves_the_old_image_as_gallery(): void
    {
        Storage::fake('admin-product-media-test');
        config(['media.disk' => 'admin-product-media-test']);
        $product = $this->product('new-primary-update');
        $old = $this->productImage($product, 'old.jpg', 0, true);

        $this->withToken($this->adminToken)->post('/api/v1/admin/products/'.$product->id, [
            '_method' => 'PATCH',
            'primary_image' => UploadedFile::fake()->createWithContent('new.jpg', $this->imageBytes()),
        ])->assertOk();

        $images = $product->fresh()->productImages()->get();
        $this->assertCount(2, $images);
        $this->assertTrue($images->first()->is_primary);
        $this->assertSame(0, $images->first()->sort_order);
        $this->assertSame($old->id, $images->last()->id);
        $this->assertFalse($images->last()->is_primary);
    }

    public function test_update_rejects_conflicting_primary_commands_before_media_changes(): void
    {
        $product = $this->product('conflicting-media-update');
        $image = $this->productImage($product, 'existing.jpg', 0, true);

        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/products/'.$product->id, [
            'primary_attachment_id' => $image->id,
            'remove_attachment_ids' => [$image->id, $image->id],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('media_attachments', ['id' => $image->id, 'is_primary' => true, 'sort_order' => 0]);
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

    public function test_admin_index_includes_the_same_operational_summary_as_product_detail(): void
    {
        $product = $this->product('index-summary');
        $first = SellableItem::create([
            'product_id' => $product->id, 'sku' => 'INDEX-FIRST', 'price' => '450.00',
            'stock_quantity' => 10, 'reserved_quantity' => 2, 'status' => 'active',
        ]);
        SellableItem::create([
            'product_id' => $product->id, 'sku' => 'INDEX-SECOND', 'price' => '700.00',
            'stock_quantity' => 5, 'reserved_quantity' => 8, 'status' => 'active',
        ]);
        SellableItem::create([
            'product_id' => $product->id, 'sku' => 'INDEX-INACTIVE', 'price' => '1.00',
            'stock_quantity' => 100, 'reserved_quantity' => 0, 'status' => 'inactive',
        ]);
        $archived = SellableItem::create([
            'product_id' => $product->id, 'sku' => 'INDEX-ARCHIVED', 'price' => '2.00',
            'stock_quantity' => 100, 'reserved_quantity' => 0, 'status' => 'active',
        ]);
        $archived->delete();
        $this->productImage($product, 'index-primary.jpg', 0, true);

        $inactiveOnly = $this->product('index-inactive-only');
        SellableItem::create([
            'product_id' => $inactiveOnly->id, 'sku' => 'INDEX-NO-ACTIVE', 'price' => '999.00',
            'stock_quantity' => 50, 'reserved_quantity' => 0, 'status' => 'inactive',
        ]);

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/products?per_page=100')
            ->assertOk();

        $items = collect($response->json('data'))->keyBy('slug');
        $summary = $items->get($product->slug);
        $this->assertNotNull($summary);
        $this->assertSame([
            'id', 'url', 'altAr', 'altEn', 'width', 'height',
        ], array_keys($summary['primaryImage']));
        $this->assertSame(['min' => '450.00', 'max' => '700.00'], $summary['priceRange']);
        $this->assertSame(['total' => 15, 'reserved' => 10, 'available' => 8], $summary['stock']);
        $this->assertTrue($summary['inStock']);
        $this->assertSame(3, $summary['variantsCount']);
        $this->assertSame(2, $summary['activeVariantsCount']);

        $inactiveSummary = $items->get($inactiveOnly->slug);
        $this->assertSame(null, $inactiveSummary['priceRange']);
        $this->assertSame(['total' => 0, 'reserved' => 0, 'available' => 0], $inactiveSummary['stock']);
        $this->assertFalse($inactiveSummary['inStock']);
        $this->assertNull($inactiveSummary['primaryImage']);

        $detail = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/products/'.$product->id)
            ->assertOk();
        $this->assertSame($summary['priceRange'], $detail->json('data.priceRange'));
        $this->assertSame($summary['stock'], $detail->json('data.stock'));
        $this->assertSame($summary['inStock'], $detail->json('data.inStock'));
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

        $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/products/'.$product->id)
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
            ->assertJsonPath('data.primaryImage.altAr', null)
            ->assertJsonPath('data.primaryImage.altEn', null)
            ->assertJsonPath('data.primaryImage.width', null)
            ->assertJsonPath('data.primaryImage.height', null)
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
        $this->assertSame(
            ['id', 'url', 'altAr', 'altEn', 'width', 'height'],
            array_keys($response->json('data.primaryImage')),
        );
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

    private function productImage(Product $product, string $name, int $sortOrder, bool $primary): MediaAttachment
    {
        $asset = MediaAsset::create([
            'disk' => 'public', 'path' => 'products/'.$name, 'original_name' => $name,
            'media_type' => 'image', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        return app(MediaService::class)->attach($asset, $product, MediaRole::PRODUCT_IMAGE, [
            'sort_order' => $sortOrder,
            'is_primary' => $primary,
        ]);
    }

    private function imageBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=',
            true,
        );
    }
}
