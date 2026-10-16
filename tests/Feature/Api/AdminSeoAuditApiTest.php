<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoRedirect;
use App\Models\SeoSetting;
use App\Models\SellableItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSeoAuditApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->getKey()]);
        $this->adminToken = $admin->createToken('seo-audit-admin', ['admin-access'])->plainTextToken;
    }

    public function test_audit_requires_the_existing_admin_authentication_and_membership(): void
    {
        $this->getJson('/api/v1/admin/seo/audit')->assertUnauthorized();

        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('non-admin', ['admin-access'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/seo/audit')->assertForbidden();
    }

    public function test_report_returns_zero_counts_for_an_empty_catalog(): void
    {
        config(['seo.indexing_allowed' => true]);
        $this->setting('supported_locales', '', ['ar', 'en']);

        $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/seo/audit')
            ->assertOk()
            ->assertJsonPath('data.products.indexEligible.distinctProducts', 0)
            ->assertJsonPath('data.products.indexEligible.byLocale.ar', 0)
            ->assertJsonPath('data.products.indexEligible.byLocale.en', 0)
            ->assertJsonPath('data.products.indexEligible.totalProductLocalePages', 0)
            ->assertJsonPath('data.products.customSeoTitleByLocale.ar', 0)
            ->assertJsonPath('data.products.customSeoTitleByLocale.en', 0)
            ->assertJsonPath('data.products.missingUsefulDescriptionByLocale.ar', 0)
            ->assertJsonPath('data.products.missingUsefulDescriptionByLocale.en', 0)
            ->assertJsonPath('data.seoImages.invalidCustomOverrides.byLocale.ar', 0)
            ->assertJsonPath('data.seoImages.invalidCustomOverrides.byLocale.en', 0)
            ->assertJsonPath('data.seoImages.invalidCustomOverrides.byEntityType.products.ar', 0)
            ->assertJsonPath('data.seoImages.invalidCustomOverrides.byEntityType.categories.ar', 0)
            ->assertJsonPath('data.seoImages.invalidCustomOverrides.byEntityType.pages.en', 0)
            ->assertJsonPath('data.sitemap.eligibleUrlsByType.product', [
                'ar' => 0, 'en' => 0, 'total' => 0, 'distinctEntities' => 0,
            ])
            ->assertJsonPath('data.sitemap.eligibleUrlsByType.category', [
                'ar' => 0, 'en' => 0, 'total' => 0, 'distinctEntities' => 0,
            ])
            ->assertJsonPath('data.sitemap.eligibleUrlsByType.page', [
                'ar' => 0, 'en' => 0, 'total' => 0, 'distinctEntities' => 0,
            ])
            ->assertJsonPath('data.sitemap.eligibleUrlsByLocale.ar', 0)
            ->assertJsonPath('data.sitemap.eligibleUrlsByLocale.en', 0)
            ->assertJsonPath('data.sitemap.totalEligibleUrls', 0)
            ->assertJsonPath('data.activeRedirects', 0);
    }

    public function test_report_counts_localized_public_content_fallbacks_and_invalid_custom_images_without_writes(): void
    {
        config([
            'seo.indexing_allowed' => true,
            'seo_revalidation.enabled' => false,
            'filesystems.disks.seo-audit-public' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/seo-audit-public'),
                'url' => 'https://cdn.example.com/media',
                'visibility' => 'public',
            ],
            'filesystems.disks.seo-audit-private' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/seo-audit-private'),
                'url' => 'https://cdn.example.com/private',
                'visibility' => 'private',
            ],
        ]);
        $this->setting('supported_locales', '', ['ar', 'en']);
        $this->setting('default_meta_description', 'ar', 'وصف المتجر الافتراضي');

        $validFallbackImage = $this->image('seo-audit-public', 'default-og.webp');
        $invalidCustomImage = $this->image('seo-audit-private', 'private-custom.webp');
        $this->setting('default_og_image_id', '', $validFallbackImage->getKey());

        $category = Category::query()->create([
            'slug' => 'audit-category',
            'name_ar' => 'قسم الاختبار',
            'name_en' => 'Audit category',
            'status' => 'active',
        ]);
        $firstProduct = $this->product($category, 'audit-first', 'وصف عربي', 'English description');
        $firstProduct->seoRecords()->create([
            'locale' => 'ar',
            'meta_title' => 'عنوان مخصص',
            'meta_description' => '   ',
            'og_image_id' => $invalidCustomImage->getKey(),
        ]);
        $firstProduct->seoRecords()->create(['locale' => 'en', 'robots_index' => false]);
        $secondProduct = $this->product($category, 'audit-second', null, null);
        ContentPage::query()->create([
            'slug' => 'about-us',
            'title_ar' => 'من نحن',
            'title_en' => 'About us',
            'body_ar' => 'محتوى عربي',
            'body_en' => 'English content',
        ]);
        SeoRedirect::query()->create([
            'domain_scope' => 'merchant.example.com',
            'source_path' => '/ar/products/old-audit-product',
            'source_hash' => hash('sha256', '/ar/products/old-audit-product'),
            'destination_path' => '/ar/products/audit-first',
            'status_code' => 301,
            'is_active' => true,
            'reason' => 'slug_changed',
        ]);

        $before = $this->snapshotTables([
            'products', 'categories', 'sellable_items', 'content_pages',
            'seo_records', 'seo_settings', 'seo_redirects', 'seo_audits', 'media_assets',
        ]);

        $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/seo/audit')->assertOk();

        $response->assertJsonPath('data.products.indexEligible.distinctProducts', 2)
            ->assertJsonPath('data.products.indexEligible.byLocale.ar', 2)
            ->assertJsonPath('data.products.indexEligible.byLocale.en', 1)
            ->assertJsonPath('data.products.indexEligible.totalProductLocalePages', 3)
            ->assertJsonPath('data.products.customSeoTitleByLocale.ar', 1)
            ->assertJsonPath('data.products.customSeoTitleByLocale.en', 0)
            ->assertJsonPath('data.products.missingUsefulDescriptionByLocale.ar', 0)
            ->assertJsonPath('data.products.missingUsefulDescriptionByLocale.en', 1)
            ->assertJsonPath('data.seoImages.invalidCustomOverrides.byLocale.ar', 1)
            ->assertJsonPath('data.seoImages.invalidCustomOverrides.byLocale.en', 0)
            ->assertJsonPath('data.seoImages.invalidCustomOverrides.unconfiguredOverrideIsNotAnError', true)
            ->assertJsonPath('data.sitemap.eligibleUrlsByType.product.total', 3)
            ->assertJsonPath('data.sitemap.eligibleUrlsByType.category.total', 2)
            ->assertJsonPath('data.sitemap.eligibleUrlsByType.page.total', 2)
            ->assertJsonPath('data.sitemap.totalEligibleUrls', 7)
            ->assertJsonPath('data.sitemap.xmlPublicationStatus', null)
            ->assertJsonPath('data.sitemap.lastGenerationError', null)
            ->assertJsonPath('data.sitemap.publicationStatusAvailable', false)
            ->assertJsonPath('data.sitemap.countsAreGoogleIndexingMetrics', false)
            ->assertJsonPath('data.activeRedirects', 1)
            ->assertJsonPath('data.products.indexEligible.indexingAllowed', true);

        $this->assertSame($before, $this->snapshotTables([
            'products', 'categories', 'sellable_items', 'content_pages',
            'seo_records', 'seo_settings', 'seo_redirects', 'seo_audits', 'media_assets',
        ]));
        $this->assertSame(0, $secondProduct->fresh()->seoRecords()->count());
    }

    private function setting(string $key, string $locale, mixed $value): SeoSetting
    {
        return SeoSetting::query()->create([
            'key' => $key,
            'locale' => $locale,
            'value_json' => $value,
        ]);
    }

    private function image(string $disk, string $path): MediaAsset
    {
        return MediaAsset::query()->create([
            'disk' => $disk,
            'path' => $path,
            'media_type' => 'image',
            'mime_type' => 'image/webp',
            'size_bytes' => 100,
        ]);
    }

    private function product(Category $category, string $slug, ?string $descriptionAr, ?string $descriptionEn): Product
    {
        $product = Product::query()->create([
            'slug' => $slug,
            'name_ar' => 'منتج '.$slug,
            'name_en' => 'Product '.$slug,
            'description_ar' => $descriptionAr,
            'description_en' => $descriptionEn,
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => 'SEO-AUDIT-'.$product->getKey(),
            'price' => 100,
            'stock_quantity' => 0,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
        ]);

        return $product;
    }

    /** @param list<string> $tables @return array<string, list<array<string, mixed>>> */
    private function snapshotTables(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ])->all();
    }
}
