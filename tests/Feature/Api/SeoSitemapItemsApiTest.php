<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SeoSetting;
use App\Models\SellableItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeoSitemapItemsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_items_continue_across_cursor_pages_without_missing_or_repeating_rows(): void
    {
        config(['seo.indexing_allowed' => true]);
        $this->supportedLocales(['ar']);
        $category = $this->category('sitemap-products');
        $expectedIds = [];

        for ($index = 1; $index <= 101; $index++) {
            $expectedIds[] = $this->product($category, sprintf('sitemap-product-%03d', $index))->getKey();
        }

        $first = $this->getJson('/api/v1/seo/sitemap-items?type=product&limit=100')->assertOk();
        $first->assertJsonCount(100, 'data.items')
            ->assertJsonPath('data.items.0.entityType', 'product')
            ->assertJsonPath('data.items.0.eligibleLocales', ['ar'])
            ->assertJsonPath('data.items.0.locales.0.path', '/products/sitemap-product-001');
        $cursor = $first->json('data.nextCursor');
        $this->assertIsString($cursor);

        $second = $this->getJson('/api/v1/seo/sitemap-items?type=product&limit=100&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.nextCursor', null);

        $actualIds = collect($first->json('data.items'))
            ->merge($second->json('data.items'))
            ->pluck('id')
            ->all();
        $this->assertSame($expectedIds, $actualIds);
    }

    public function test_public_items_continue_past_noindex_rows_and_include_locale_specific_eligibility(): void
    {
        config(['seo.indexing_allowed' => true]);
        $this->supportedLocales(['ar', 'en']);
        $category = $this->category('sitemap-eligibility');
        $excluded = $this->product($category, 'sitemap-noindex');
        $excluded->seoRecords()->create(['locale' => 'ar', 'robots_index' => false]);
        $excluded->seoRecords()->create(['locale' => 'en', 'robots_index' => false]);
        $future = $this->product($category, 'sitemap-future');
        $future->update(['published_at' => now()->addDay()]);
        $draft = $this->product($category, 'sitemap-draft');
        $draft->update(['status' => 'draft']);
        $archived = $this->product($category, 'sitemap-archived');
        $archived->delete();
        $included = $this->product($category, 'sitemap-indexed');
        $included->seoRecords()->create(['locale' => 'en', 'robots_index' => false]);

        $response = $this->getJson('/api/v1/seo/sitemap-items?type=product&limit=10')->assertOk();

        $response->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $included->getKey())
            ->assertJsonPath('data.items.0.eligibleLocales', ['ar'])
            ->assertJsonPath('data.items.0.locales.0.locale', 'ar')
            ->assertJsonPath('data.nextCursor', null);
    }

    public function test_category_and_content_page_paths_use_the_existing_visibility_and_canonical_rules(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-01 12:00:00 UTC'));
        config(['seo.indexing_allowed' => true]);
        $this->supportedLocales(['ar', 'en']);
        $parent = $this->category('sitemap-parent');
        $child = Category::query()->create([
            'parent_id' => $parent->getKey(),
            'slug' => 'sitemap-child',
            'name_ar' => 'قسم فرعي',
            'name_en' => 'Child category',
            'status' => 'active',
        ]);
        $hiddenParent = $this->category('sitemap-hidden-parent', ['status' => 'inactive']);
        $this->category('sitemap-hidden-child', ['parent_id' => $hiddenParent->getKey()]);
        ContentPage::query()->create([
            'slug' => 'about-us',
            'title_ar' => 'من نحن',
            'title_en' => 'About us',
            'body_ar' => 'محتوى عربي',
            'body_en' => 'English content',
        ]);
        $returnsPage = ContentPage::query()->create([
            'slug' => 'returns-policy',
            'title_ar' => 'سياسة الاسترجاع',
            'title_en' => 'Returns policy',
            'body_ar' => 'تفاصيل عربية',
            'body_en' => 'English details',
        ]);
        $returnsPage->seoRecords()->create(['locale' => 'en', 'robots_index' => false]);

        $categoryResponse = $this->getJson('/api/v1/seo/sitemap-items?type=category')->assertOk();
        $categoryResponse->assertJsonFragment([
            'id' => $parent->getKey(),
            'entityType' => 'category',
            'slug' => 'sitemap-parent',
        ])->assertJsonFragment([
            'id' => $child->getKey(),
            'entityType' => 'category',
            'slug' => 'sitemap-child',
        ]);
        $this->assertSame(2, count($categoryResponse->json('data.items')));
        $this->assertNotContains(
            'sitemap-hidden-child',
            collect($categoryResponse->json('data.items'))->pluck('slug')->all(),
        );

        $pageResponse = $this->getJson('/api/v1/seo/sitemap-items?type=page')->assertOk();
        $pageResponse->assertJsonPath('data.items.0.slug', 'about-us')
            ->assertJsonPath('data.items.0.locales.0.path', '/about')
            ->assertJsonPath('data.items.0.locales.0.lastSignificantModifiedAt', '2026-05-01T12:00:00.000000Z')
            ->assertJsonPath('data.items.0.locales.1.path', '/about')
            ->assertJsonPath('data.items.0.locales.1.lastSignificantModifiedAt', '2026-05-01T12:00:00.000000Z')
            ->assertJsonPath('data.items.1.slug', 'returns-policy')
            ->assertJsonPath('data.items.1.eligibleLocales', ['ar'])
            ->assertJsonPath('data.items.1.locales.0.path', '/return-policy');
    }

    public function test_invalid_type_cursor_limit_and_unknown_parameters_return_validation_errors(): void
    {
        $this->getJson('/api/v1/seo/sitemap-items?type=other')->assertUnprocessable();
        $this->getJson('/api/v1/seo/sitemap-items?type=product&limit=101')->assertUnprocessable();
        $this->getJson('/api/v1/seo/sitemap-items?type=product&cursor=not-a-cursor')->assertUnprocessable();

        $cursor = rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'type' => 'category',
            'id' => 1,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $this->getJson('/api/v1/seo/sitemap-items?type=product&cursor='.$cursor)->assertUnprocessable();
        $this->getJson('/api/v1/seo/sitemap-items?type=product&unexpected=1')->assertUnprocessable();
    }

    public function test_disabled_indexing_returns_an_empty_successful_page_without_writes(): void
    {
        config(['seo.indexing_allowed' => false]);
        $category = $this->category('sitemap-disabled');
        $this->product($category, 'sitemap-disabled-product');
        $before = [
            'products' => Product::query()->count(),
            'records' => \App\Models\SeoRecord::query()->count(),
            'settings' => SeoSetting::query()->count(),
        ];

        $this->getJson('/api/v1/seo/sitemap-items?type=product')
            ->assertOk()
            ->assertExactJson(['data' => ['items' => [], 'nextCursor' => null]]);

        $this->assertSame($before, [
            'products' => Product::query()->count(),
            'records' => \App\Models\SeoRecord::query()->count(),
            'settings' => SeoSetting::query()->count(),
        ]);
    }

    public function test_sitemap_read_failure_is_not_returned_as_an_empty_successful_page(): void
    {
        config(['seo.indexing_allowed' => true]);

        $failSeoSettingsRead = false;
        $seoSettingsReadFailed = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$failSeoSettingsRead, &$seoSettingsReadFailed): void {
            if ($failSeoSettingsRead
                && str_starts_with(strtolower(ltrim($query)), 'select')
                && str_contains(strtolower($query), 'seo_settings')) {
                $seoSettingsReadFailed = true;

                throw new \RuntimeException('Sitemap data store is unavailable.');
            }
        });

        $failSeoSettingsRead = true;

        try {
            $response = $this->getJson('/api/v1/seo/sitemap-items?type=product');
        } finally {
            $failSeoSettingsRead = false;
        }

        $response
            ->assertInternalServerError()
            ->assertJsonMissingPath('data.items');
        $this->assertTrue($seoSettingsReadFailed, 'The request did not reach the SEO settings database read.');
    }

    public function test_item_lastmod_uses_the_existing_significant_timestamp_without_writing_during_get(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-05-01 12:00:00 UTC'));
        config(['seo.indexing_allowed' => true]);
        $this->supportedLocales(['ar']);
        $category = $this->category('sitemap-lastmod');
        $product = $this->product($category, 'sitemap-lastmod-product');
        $before = [
            'product' => $product->refresh()->updated_at?->toISOString(),
            'seoRecords' => \App\Models\SeoRecord::query()->count(),
            'audits' => \App\Models\SeoAudit::query()->count(),
        ];

        $response = $this->getJson('/api/v1/seo/sitemap-items?type=product')->assertOk();
        $response->assertJsonPath('data.items.0.locales.0.lastSignificantModifiedAt', '2026-05-01T12:00:00.000000Z');

        $this->assertSame($before, [
            'product' => $product->refresh()->updated_at?->toISOString(),
            'seoRecords' => \App\Models\SeoRecord::query()->count(),
            'audits' => \App\Models\SeoAudit::query()->count(),
        ]);
    }

    private function supportedLocales(array $locales): void
    {
        SeoSetting::query()->create([
            'key' => 'supported_locales',
            'locale' => '',
            'value_json' => $locales,
        ]);
    }

    private function category(string $slug, array $attributes = []): Category
    {
        return Category::query()->create(array_merge([
            'slug' => $slug,
            'name_ar' => 'اسم '.$slug,
            'name_en' => 'Name '.$slug,
            'status' => 'active',
        ], $attributes));
    }

    private function product(Category $category, string $slug): Product
    {
        $product = Product::query()->create([
            'slug' => $slug,
            'name_ar' => 'منتج '.$slug,
            'name_en' => 'Product '.$slug,
            'description_ar' => 'وصف عربي',
            'description_en' => 'English description',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => 'SITEM-'.strtoupper($slug),
            'price' => 100,
            'stock_quantity' => 0,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
        ]);

        return $product;
    }
}
