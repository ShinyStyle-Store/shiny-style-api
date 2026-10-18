<?php

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoSetting;
use App\Models\SellableItem;
use App\Services\CategoryHierarchyService;
use App\Services\SeoEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeoStagingAcceptanceFixturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureStaging();
    }

    public function test_fixture_commands_refuse_non_staging_and_production_environments(): void
    {
        $this->app['env'] = 'testing';
        $this->artisan('seo:staging-fixtures')->assertFailed();
        $this->artisan('seo:staging-fixtures:clean')->assertFailed();

        $this->app['env'] = 'production';
        $this->artisan('seo:staging-fixtures')->assertFailed();
        $this->artisan('seo:staging-fixtures:clean')->assertFailed();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('seo_staging_fixture_records', 0);
    }

    public function test_default_seed_is_idempotent_and_creates_only_small_public_fixtures(): void
    {
        $this->artisan('seo:staging-fixtures')->assertSuccessful();
        $this->artisan('seo:staging-fixtures')->assertSuccessful();

        $this->assertSame(4, Product::query()->where('slug', 'like', 'seo-staging-%')->count());
        $this->assertSame(4, DB::table('seo_staging_fixture_records')->where('record_type', 'product')->count());

        $category = Category::query()->where('slug', 'seo-staging-category')->firstOrFail();
        $bilingual = Product::query()->where('slug', 'seo-staging-bilingual-001')->firstOrFail();
        $singleLocale = Product::query()->where('slug', 'seo-staging-single-locale-001')->firstOrFail();
        $visibleCategoryIds = app(CategoryHierarchyService::class)->effectiveVisibleIds();

        $this->assertSame('active', $category->status);
        $this->assertSame('active', $bilingual->status);
        $this->assertNotSame($bilingual->name_ar, $bilingual->name_en);
        $this->assertNotNull($bilingual->published_at);
        $this->assertDatabaseHas('sellable_items', [
            'product_id' => $bilingual->getKey(),
            'status' => 'active',
            'stock_quantity' => 10,
        ]);
        $this->assertTrue(Product::query()->visible($visibleCategoryIds)->whereKey($bilingual->getKey())->exists());
        $this->assertSame(['ar', 'en'], app(SeoEligibilityService::class)->availableLocales($bilingual, ['ar', 'en']));
        $this->assertSame(['ar'], app(SeoEligibilityService::class)->availableLocales($singleLocale, ['ar', 'en']));

        foreach (['about-us', 'returns-policy', 'shipping-policy'] as $slug) {
            $this->assertDatabaseHas('content_pages', ['slug' => $slug]);
        }
        $supportedPage = ContentPage::query()->where('slug', 'about-us')->firstOrFail();
        $this->assertNotSame('', $supportedPage->title_ar);
        $this->assertNotSame('', $supportedPage->title_en);

        $this->assertDatabaseHas('seo_settings', ['key' => 'store_name', 'locale' => 'ar']);
        $this->assertDatabaseHas('seo_settings', ['key' => 'store_name', 'locale' => 'en']);
        $this->assertDatabaseHas('seo_settings', ['key' => 'canonical_base_url', 'locale' => '']);
    }

    public function test_missing_required_store_configuration_fails_without_creating_records(): void
    {
        config(['seo_staging_fixtures.store_name_en' => null]);

        $this->artisan('seo:staging-fixtures')->assertFailed();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('seo_settings', 0);
        $this->assertDatabaseCount('seo_staging_fixture_records', 0);
    }

    public function test_existing_operator_setting_is_never_overwritten_by_fixture_configuration(): void
    {
        $setting = SeoSetting::query()->create([
            'key' => 'store_name',
            'locale' => 'ar',
            'value_json' => 'Existing operator-approved Arabic name',
        ]);

        $this->artisan('seo:staging-fixtures')->assertFailed();

        $this->assertSame('Existing operator-approved Arabic name', $setting->fresh()->value_json);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('seo_staging_fixture_records', 0);
    }

    public function test_pagination_option_creates_at_least_twenty_five_products_available_in_each_locale(): void
    {
        $this->artisan('seo:staging-fixtures', ['--with-pagination' => true])->assertSuccessful();

        $visibleCategoryIds = app(CategoryHierarchyService::class)->effectiveVisibleIds();
        $arabicCount = Product::query()->visible($visibleCategoryIds)
            ->whereNotNull('name_ar')->where('name_ar', '<>', '')->count();
        $englishCount = Product::query()->visible($visibleCategoryIds)
            ->whereNotNull('name_en')->where('name_en', '<>', '')->count();

        $this->assertGreaterThanOrEqual(25, $arabicCount);
        $this->assertGreaterThanOrEqual(25, $englishCount);
        $this->assertSame(26, Product::query()->where('slug', 'like', 'seo-staging-%')->count());
    }

    public function test_cleanup_removes_only_owned_fixtures_and_preserves_existing_settings_pages_and_media(): void
    {
        $existingCategory = Category::query()->create([
            'slug' => 'existing-staging-category',
            'name_ar' => 'Existing category',
            'name_en' => 'Existing category',
            'status' => 'active',
        ]);
        $existingProduct = Product::query()->create([
            'slug' => 'existing-staging-product',
            'name_ar' => 'Existing product',
            'name_en' => 'Existing product',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $existingProduct->categories()->attach($existingCategory, ['is_primary' => true]);
        SellableItem::query()->create([
            'product_id' => $existingProduct->getKey(),
            'sku' => 'EXISTING-STAGING-SKU',
            'price' => '1.00',
            'stock_quantity' => 3,
            'status' => 'active',
            'is_default' => true,
        ]);
        $existingPage = ContentPage::query()->create([
            'slug' => 'returns-policy',
            'title_ar' => 'Existing Arabic policy',
            'title_en' => 'Existing English policy',
        ]);
        $existingSetting = SeoSetting::query()->create([
            'key' => 'store_name',
            'locale' => 'ar',
            'value_json' => 'Operator-managed Arabic store',
        ]);
        $existingMedia = MediaAsset::query()->create([
            'disk' => 'public',
            'path' => 'existing/logo.webp',
            'original_name' => 'logo.webp',
            'media_type' => 'image',
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'size_bytes' => 100,
        ]);
        config([
            'filesystems.disks.public.url' => 'https://staging.example.test/storage',
            'filesystems.disks.public.visibility' => 'public',
            'seo_staging_fixtures.logo_media_id' => $existingMedia->getKey(),
        ]);

        $this->artisan('seo:staging-fixtures')->assertSuccessful();
        $this->assertSame(
            $existingMedia->getKey(),
            SeoSetting::query()->where('key', 'store_logo_id')->where('locale', '')->firstOrFail()->value_json,
        );
        $this->artisan('seo:staging-fixtures:clean')->assertSuccessful();

        $this->assertModelExists($existingCategory);
        $this->assertModelExists($existingProduct);
        $this->assertModelExists($existingPage);
        $this->assertModelExists($existingSetting);
        $this->assertSame('Operator-managed Arabic store', $existingSetting->fresh()->value_json);
        $this->assertModelExists($existingMedia);
        $this->assertSame(0, Product::query()->where('slug', 'like', 'seo-staging-%')->count());
        $this->assertSame(0, Category::query()->where('slug', 'like', 'seo-staging-%')->count());
        $this->assertDatabaseMissing('content_pages', ['slug' => 'shipping-policy']);
        $this->assertDatabaseCount('seo_staging_fixture_records', 0);
    }

    private function configureStaging(): void
    {
        $this->app['env'] = 'staging';
        config([
            'app.url' => 'https://staging.example.test',
            'seo_staging_fixtures.store_name_ar' => 'Operator-managed Arabic store',
            'seo_staging_fixtures.store_name_en' => 'SEO-STAGING- English test store',
            'seo_staging_fixtures.canonical_base_url' => 'https://staging.example.test',
            'seo_staging_fixtures.logo_media_id' => null,
        ]);
    }
}
