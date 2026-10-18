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
use App\Services\SeoAuditReportService;
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

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/seo/audit')
            ->assertOk()
            ->assertJsonStructure(['data' => ['total', 'missing_title', 'missing_description', 'non_indexable']])
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.missing_title', 0)
            ->assertJsonPath('data.missing_description', 0)
            ->assertJsonPath('data.non_indexable', 0);

        $counts = $response->json('data');
        foreach (['total', 'missing_title', 'missing_description', 'non_indexable'] as $key) {
            $this->assertSame(0, $counts[$key]);
        }
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

        $counts = $response->json('data');
        $this->assertSame(
            ['total', 'missing_title', 'missing_description', 'non_indexable'],
            array_keys($counts),
        );
        foreach ($counts as $count) {
            $this->assertIsInt($count);
            $this->assertGreaterThanOrEqual(0, $count);
        }

        $response->assertJsonPath('data.total', 8)
            ->assertJsonPath('data.missing_title', 7)
            ->assertJsonPath('data.missing_description', 2)
            ->assertJsonPath('data.non_indexable', 1);

        $report = app(SeoAuditReportService::class)->report();
        $this->assertSame(2, $report['products']['indexEligible']['distinctProducts']);
        $this->assertSame(1, $report['seoImages']['invalidCustomOverrides']['byLocale']['ar']);
        $this->assertSame(7, $report['sitemap']['totalEligibleUrls']);
        $this->assertSame(1, $report['activeRedirects']);

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
