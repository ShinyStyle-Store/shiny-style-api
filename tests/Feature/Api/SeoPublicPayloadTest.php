<?php

namespace Tests\Feature\Api;

use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoRecord;
use App\Models\SeoSetting;
use App\Models\SellableItem;
use App\Services\SeoEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SeoPublicPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_seo_uses_live_content_then_custom_values_without_writing_during_reads(): void
    {
        config(['seo.indexing_allowed' => true]);
        $product = $this->product([
            'name_ar' => 'منتج عربي',
            'name_en' => 'English product',
            'description_ar' => '<p>وصف <strong>عربي</strong></p>',
            'description_en' => '<p>Fresh&nbsp; description</p>',
        ]);

        $response = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.seo.title', 'English product')
            ->assertJsonPath('data.seo.description', 'Fresh description')
            ->assertJsonPath('data.seo.canonicalPath', '/products/'.$product->slug)
            ->assertJsonPath('data.seo.robots.index', true)
            ->assertJsonPath('data.seo.robots.follow', true)
            ->assertJsonPath('data.seo.indexable', true)
            ->assertJsonPath('data.seo.availableLocales', ['ar', 'en']);
        $this->assertNotNull($response->json('data.seo.lastSignificantModifiedAt'));
        $this->assertDatabaseCount('seo_records', 0);
        $this->assertDatabaseCount('seo_audits', 0);

        $product->update(['name_en' => 'Changed automatic name', 'description_en' => '<div>Changed text</div>']);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Changed automatic name')
            ->assertJsonPath('data.seo.description', 'Changed text');

        $product->seoRecords()->create([
            'locale' => 'en',
            'meta_title' => 'Manual SEO title',
            'meta_description' => 'Manual SEO description',
            'robots_index' => false,
            'og_title' => 'Manual share title',
            'og_description' => '<p>Manual <strong>share</strong> description</p>',
        ]);
        $product->update(['name_en' => 'Later product name', 'description_en' => 'Later description']);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Manual SEO title')
            ->assertJsonPath('data.seo.description', 'Manual SEO description')
            ->assertJsonPath('data.seo.openGraph.title', 'Manual share title')
            ->assertJsonPath('data.seo.openGraph.description', 'Manual share description')
            ->assertJsonPath('data.seo.robots.index', false)
            ->assertJsonPath('data.seo.indexable', false);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'منتج عربي')
            ->assertJsonPath('data.seo.availableLocales', ['ar', 'en']);

        $product->seoRecords()->where('locale', 'en')->update(['meta_title' => null, 'meta_description' => null]);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Later product name')
            ->assertJsonPath('data.seo.description', 'Later description');
        $this->assertDatabaseCount('seo_records', 1);
        $this->assertDatabaseCount('seo_audits', 0);
    }

    public function test_zero_stock_does_not_hide_a_visible_product_or_make_it_ineligible(): void
    {
        config(['seo.indexing_allowed' => true]);
        $product = $this->product(['name_ar' => 'متاح', 'name_en' => 'Available']);
        $product->sellableItems()->firstOrFail()->update(['stock_quantity' => 0, 'reserved_quantity' => 0]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.inStock', false)
            ->assertJsonPath('data.seo.robots.index', true)
            ->assertJsonPath('data.seo.indexable', true);
    }

    public function test_missing_requested_translation_is_noindex_for_an_unpersisted_product_state(): void
    {
        config(['seo.indexing_allowed' => true]);
        $product = new Product([
            'name_ar' => 'اسم عربي',
            'name_en' => null,
        ]);
        $eligibility = app(SeoEligibilityService::class);
        $availableLocales = $eligibility->availableLocales($product, ['ar', 'en']);
        $resolved = $eligibility->resolve(true, 'en', $availableLocales, [
            'index' => true,
            'follow' => true,
        ]);

        $this->assertSame(['ar'], $availableLocales);
        $this->assertFalse($resolved['robots']['index']);
        $this->assertFalse($resolved['indexable']);
    }

    public function test_configured_supported_locales_bound_available_locales_without_hiding_the_page(): void
    {
        config(['seo.indexing_allowed' => true]);
        $product = $this->product();
        SeoSetting::query()->create([
            'key' => 'supported_locales',
            'locale' => '',
            'value_json' => ['ar'],
        ]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.availableLocales', ['ar'])
            ->assertJsonPath('data.seo.robots.index', false)
            ->assertJsonPath('data.seo.indexable', false);
    }

    public function test_last_significant_modified_at_is_locale_specific_and_get_does_not_write(): void
    {
        config(['seo.indexing_allowed' => true]);
        $this->travelTo(now()->setDate(2026, 5, 1)->setTime(12, 0));
        $product = $this->product();
        $arabicMarker = $product->seo_last_significant_modified_at_ar;
        $originalUpdatedAt = $product->updated_at->toDateTimeString();
        $englishChangeAt = now()->addMinutes(3)->toImmutable();

        app(\App\Services\SeoSignificantModificationService::class)->mark($product, $englishChangeAt, 'en');

        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk();
        $this->travelTo($englishChangeAt);
        $english = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk();
        $stored = DB::table('products')->where('id', $product->getKey())->first();

        $this->assertSame('2026-05-01T12:00:00.000000Z', $arabic->json('data.seo.lastSignificantModifiedAt'));
        $this->assertSame('2026-05-01T12:03:00.000000Z', $english->json('data.seo.lastSignificantModifiedAt'));
        $this->assertSame($arabicMarker, $stored->seo_last_significant_modified_at_ar);
        $this->assertSame($englishChangeAt->toDateTimeString(), $stored->seo_last_significant_modified_at_en);
        $this->assertSame($originalUpdatedAt, $stored->updated_at);
        $this->assertDatabaseCount('seo_audits', 0);
    }

    public function test_offer_start_and_scheduled_publication_use_event_times_without_future_dates(): void
    {
        config(['seo.indexing_allowed' => true]);
        $this->travelTo(now()->setDate(2026, 5, 1)->setTime(12, 0));
        $product = $this->product();
        $offerStartsAt = now()->addMinutes(5);
        $offerEndsAt = now()->addMinutes(10);
        $offer = DB::table('offers')->insertGetId([
            'name' => 'Timed discount',
            'discount_percentage' => 10,
            'starts_at' => $offerStartsAt,
            'ends_at' => $offerEndsAt,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('offer_product')->insert(['offer_id' => $offer, 'product_id' => $product->getKey()]);

        $beforeStart = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->travelTo($offerStartsAt);
        $afterStart = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();

        $this->assertSame('2026-05-01T12:00:00.000000Z', $beforeStart->json('data.seo.lastSignificantModifiedAt'));
        $this->assertSame('2026-05-01T12:05:00.000000Z', $afterStart->json('data.seo.lastSignificantModifiedAt'));
        $this->travelTo($offerEndsAt);
        $afterEnd = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertSame('2026-05-01T12:10:00.000000Z', $afterEnd->json('data.seo.lastSignificantModifiedAt'));

        $publicationAt = now()->addMinutes(10);
        $scheduled = $this->product(['published_at' => $publicationAt]);
        $this->getJson('/api/v1/products/'.$scheduled->slug)->assertNotFound();
        $this->travelTo($publicationAt);
        $published = $this->getJson('/api/v1/products/'.$scheduled->slug)->assertOk();

        $this->assertSame('2026-05-01T12:20:00.000000Z', $published->json('data.seo.lastSignificantModifiedAt'));
    }

    public function test_significant_timestamp_write_rolls_back_with_its_transaction(): void
    {
        $this->travelTo(now()->setDate(2026, 5, 1)->setTime(12, 0));
        $product = $this->product();
        $before = DB::table('products')->where('id', $product->getKey())->value('seo_last_significant_modified_at');

        try {
            DB::transaction(function () use ($product): void {
                app(\App\Services\SeoSignificantModificationService::class)->mark($product);
                throw new RuntimeException('Force transaction rollback.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force transaction rollback.', $exception->getMessage());
        }

        $after = DB::table('products')->where('id', $product->getKey())->value('seo_last_significant_modified_at');
        $this->assertSame($before, $after);
    }

    public function test_product_image_falls_back_from_unavailable_custom_image_to_the_displayed_variant(): void
    {
        config(['seo.indexing_allowed' => true]);
        $product = $this->product();
        $variant = $product->sellableItems()->firstOrFail();
        $variantImage = $this->image('variant-image.jpg');
        $removedCustomImage = $this->image('removed-custom-image.jpg');
        $defaultImage = $this->image('default-image.jpg');
        $removedCustomImage->delete();

        $variant->variantImages()->create([
            'media_asset_id' => $variantImage->getKey(),
            'role' => MediaRole::VARIANT_IMAGE,
            'is_primary' => true,
        ]);
        $product->seoRecords()->create([
            'locale' => 'en',
            'og_image_id' => $removedCustomImage->getKey(),
        ]);
        SeoSetting::query()->create([
            'key' => 'default_og_image_id',
            'locale' => '',
            'value_json' => $defaultImage->getKey(),
        ]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.image', Storage::disk('public')->url($variantImage->path))
            ->assertJsonPath('data.seo.openGraph.image', Storage::disk('public')->url($variantImage->path));

        $product->seoRecords()->where('locale', 'en')->update(['og_image_id' => $defaultImage->getKey()]);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.openGraph.image', Storage::disk('public')->url($defaultImage->path));
    }

    public function test_product_media_attachment_timestamps_contribute_to_last_significant_modified_at(): void
    {
        config(['seo.indexing_allowed' => true]);
        $this->travelTo(now()->setDate(2026, 5, 1)->setTime(12, 0));
        $product = $this->product();
        $image = $this->image('seo-product-attachment.jpg');

        $this->travelTo(now()->addMinutes(5));
        $attachment = $product->mediaAttachments()->create([
            'media_asset_id' => $image->getKey(),
            'role' => MediaRole::PRODUCT_IMAGE,
            'is_primary' => true,
        ]);

        $this->travelTo(now()->addMinutes(2));
        $attachment->update(['alt_en' => 'Updated image description']);

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.lastSignificantModifiedAt', '2026-05-01T12:07:00.000000Z');
    }

    public function test_canonical_falls_back_when_a_saved_path_no_longer_matches_the_live_route(): void
    {
        config(['seo.indexing_allowed' => true]);
        $product = $this->product();
        $oldSlug = $product->slug;
        $product->seoRecords()->create([
            'locale' => 'en',
            'canonical_path' => '/products/'.$oldSlug,
        ]);
        $product->update(['slug' => 'seo-renamed-product-'.uniqid()]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.canonicalPath', '/products/'.$product->slug);
    }

    public function test_category_noindex_does_not_hide_it_and_category_lists_keep_their_existing_shape(): void
    {
        config(['seo.indexing_allowed' => true]);
        $category = Category::query()->create([
            'slug' => 'seo-empty-category',
            'name_ar' => 'قسم فارغ',
            'name_en' => 'Empty category',
            'status' => 'active',
        ]);
        $category->seoRecords()->create([
            'locale' => 'en',
            'robots_index' => false,
        ]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/categories/'.$category->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Empty category')
            ->assertJsonPath('data.seo.canonicalPath', '/category/'.$category->slug)
            ->assertJsonPath('data.seo.robots.index', false)
            ->assertJsonPath('data.seo.indexable', false);

        $list = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/categories')->assertOk();
        $this->assertArrayNotHasKey('seo', $list->json('data.0'));
    }

    public function test_content_page_seo_reports_only_actual_translation_and_no_guessed_frontend_canonical(): void
    {
        config(['seo.indexing_allowed' => true]);
        ContentPage::query()->create([
            'slug' => 'about-us',
            'title_ar' => 'من نحن',
            'title_en' => null,
            'body_ar' => '<p>عن <strong>المتجر</strong>&nbsp;اليوم</p>',
            'body_en' => null,
        ]);
        SeoSetting::query()->create([
            'key' => 'default_seo_title',
            'locale' => 'en',
            'value_json' => 'Default page title',
        ]);
        SeoSetting::query()->create([
            'key' => 'default_meta_description',
            'locale' => 'en',
            'value_json' => 'Default page description',
        ]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/content-pages/about-us')
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Default page title')
            ->assertJsonPath('data.seo.description', 'Default page description')
            ->assertJsonPath('data.seo.canonicalPath', null)
            ->assertJsonPath('data.seo.robots.index', false)
            ->assertJsonPath('data.seo.indexable', false)
            ->assertJsonPath('data.seo.availableLocales', ['ar']);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/content-pages/about-us')
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'من نحن')
            ->assertJsonPath('data.seo.description', 'عن المتجر اليوم')
            ->assertJsonPath('data.seo.robots.index', true)
            ->assertJsonPath('data.seo.indexable', true);
    }

    public function test_runtime_indexing_gate_blocks_index_without_changing_public_access(): void
    {
        config(['seo.indexing_allowed' => false]);
        $product = $this->product();

        $this->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.robots.index', false)
            ->assertJsonPath('data.seo.robots.follow', true)
            ->assertJsonPath('data.seo.indexable', false);
    }

    public function test_index_true_does_not_make_unpublished_products_or_hidden_category_ancestors_public(): void
    {
        config(['seo.indexing_allowed' => true]);
        $product = $this->product(['published_at' => now()->addHour()]);
        $product->seoRecords()->create([
            'locale' => 'en',
            'robots_index' => true,
        ]);

        $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();

        $product->update(['published_at' => now()->subMinute()]);
        $category = $product->categories()->firstOrFail();
        $category->update(['status' => 'inactive']);

        $this->getJson('/api/v1/products/'.$product->slug)->assertNotFound();
        $this->getJson('/api/v1/categories/'.$category->slug)->assertNotFound();
    }

    public function test_auto_robots_values_use_the_global_policy_and_preserve_explicit_false(): void
    {
        config(['seo.indexing_allowed' => true]);
        $product = $this->product();
        SeoSetting::query()->create([
            'key' => 'default_robots_policy',
            'locale' => '',
            'value_json' => ['index' => false, 'follow' => false],
        ]);
        $product->seoRecords()->create([
            'locale' => 'en',
            'robots_index' => null,
            'robots_follow' => null,
        ]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.robots.index', false)
            ->assertJsonPath('data.seo.robots.follow', false)
            ->assertJsonPath('data.seo.indexable', false);

        $product->seoRecords()->where('locale', 'en')->update([
            'robots_index' => false,
            'robots_follow' => true,
        ]);
        SeoSetting::query()->where('key', 'default_robots_policy')->update([
            'value_json' => json_encode(['index' => true, 'follow' => false]),
        ]);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.seo.robots.index', false)
            ->assertJsonPath('data.seo.robots.follow', true)
            ->assertJsonPath('data.seo.indexable', false);
    }

    private function product(array $attributes = []): Product
    {
        $product = Product::query()->create(array_merge([
            'slug' => 'seo-public-product-'.uniqid(),
            'name_ar' => 'منتج عربي',
            'name_en' => 'English product',
            'description_ar' => 'وصف عربي',
            'description_en' => 'English description',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ], $attributes));
        $category = Category::query()->create([
            'slug' => 'seo-product-category-'.uniqid(),
            'name_ar' => 'قسم عربي',
            'name_en' => 'English category',
            'status' => 'active',
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => 'SEO-'.strtoupper(uniqid()),
            'price' => 100,
            'stock_quantity' => 2,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
        ]);

        return $product;
    }

    private function image(string $path): MediaAsset
    {
        return MediaAsset::query()->create([
            'disk' => 'public',
            'path' => $path,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
        ]);
    }
}
