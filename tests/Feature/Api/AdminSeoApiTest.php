<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoAudit;
use App\Models\SeoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSeoApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['name' => 'SEO Admin', 'is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $this->admin->getKey()]);
        $this->adminToken = $this->admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_record_updates_are_partial_locale_specific_and_audited_only_when_changed(): void
    {
        $product = $this->product();
        $url = '/api/v1/admin/seo/product/'.$product->id;

        $this->withHeader('Accept-Language', 'ar')->withToken($this->adminToken)
            ->putJson($url, ['locale' => 'en', 'metaTitle' => 'English title'])
            ->assertOk()->assertJsonPath('data.locale', 'en')->assertJsonPath('data.metaTitle', 'English title');
        $this->app['auth']->forgetGuards();

        $this->withToken($this->adminToken)->putJson($url, [
            'locale' => 'en', 'robots' => ['index' => false],
        ])->assertOk()->assertJsonPath('data.metaTitle', 'English title')
            ->assertJsonPath('data.robots.index', false)->assertJsonPath('data.robots.follow', null);
        $this->assertDatabaseCount('seo_audits', 2);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson($url, [
            'locale' => 'en', 'robots' => ['index' => false],
        ])->assertOk();
        $this->assertDatabaseCount('seo_audits', 2);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson($url, [
            'locale' => 'ar', 'metaTitle' => 'Arabic title',
        ])->assertOk()->assertJsonPath('data.locale', 'ar');

        $this->assertDatabaseCount('seo_records', 2);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson($url)->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_clearing_last_custom_value_deletes_the_row_and_preserves_history(): void
    {
        $product = $this->product();
        $url = '/api/v1/admin/seo/product/'.$product->id;

        $this->withToken($this->adminToken)->putJson($url, ['locale' => 'ar', 'metaTitle' => 'Title'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson($url, ['locale' => 'ar', 'metaTitle' => null])
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('seo_records', 0);
        $this->assertDatabaseCount('seo_audits', 2);
        $this->assertDatabaseHas('seo_audits', ['target_type' => 'product', 'action' => 'delete']);
    }

    public function test_targets_use_fixed_aliases_and_canonical_paths_must_match_entity(): void
    {
        $category = Category::query()->create([
            'slug' => 'seo-category', 'name_ar' => 'تصنيف', 'name_en' => 'Category', 'status' => 'active',
        ]);
        $page = ContentPage::query()->create([
            'slug' => 'about-us', 'title_ar' => 'عن المتجر', 'title_en' => 'About',
            'body_ar' => 'نص', 'body_en' => 'Text',
        ]);

        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/category/'.$category->id, [
            'locale' => 'ar', 'canonicalPath' => '/category/seo-category',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/content_page/'.$page->id, [
            'locale' => 'en', 'canonicalPath' => '/about',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/'.rawurlencode(Product::class).'/1', [
            'locale' => 'en', 'metaTitle' => 'Rejected',
        ])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/content_page/'.$page->id, [
            'locale' => 'en', 'canonicalPath' => '//external.example/about-us',
        ])->assertUnprocessable()->assertJsonValidationErrors('canonicalPath');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/content_page/'.$page->id, [
            'locale' => 'en', 'canonicalPath' => '/about-us',
        ])->assertUnprocessable()->assertJsonValidationErrors('canonicalPath');
        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'category', 'seoable_id' => $category->id, 'canonical_path' => '/category/seo-category',
        ]);
        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'content_page', 'seoable_id' => $page->id, 'canonical_path' => '/about',
        ]);
    }

    public function test_content_page_seo_is_locale_specific_and_independent_from_content_saves(): void
    {
        $page = ContentPage::query()->create([
            'slug' => 'about-us',
            'title_ar' => 'عنوان الصفحة',
            'title_en' => 'Page title',
            'body_ar' => 'محتوى الصفحة',
            'body_en' => 'Page content',
        ]);
        $seoUrl = '/api/v1/admin/seo/content_page/'.$page->getKey();

        $this->withToken($this->adminToken)->putJson($seoUrl, [
            'locale' => 'ar',
            'metaTitle' => 'عنوان SEO عربي',
        ])->assertOk()->assertJsonPath('data.locale', 'ar');
        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'content_page',
            'seoable_id' => $page->getKey(),
            'locale' => 'ar',
            'meta_title' => 'عنوان SEO عربي',
        ]);
        $this->assertDatabaseMissing('seo_records', [
            'seoable_type' => 'content_page',
            'seoable_id' => $page->getKey(),
            'locale' => 'en',
        ]);
        $this->assertDatabaseHas('content_pages', [
            'id' => $page->getKey(),
            'title_ar' => 'عنوان الصفحة',
            'title_en' => 'Page title',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson($seoUrl, [
            'locale' => 'en',
            'metaTitle' => 'English SEO title',
        ])->assertOk()->assertJsonPath('data.locale', 'en');
        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'content_page',
            'seoable_id' => $page->getKey(),
            'locale' => 'ar',
            'meta_title' => 'عنوان SEO عربي',
        ]);
        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'content_page',
            'seoable_id' => $page->getKey(),
            'locale' => 'en',
            'meta_title' => 'English SEO title',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/content-pages/about-us', [
            'titleAr' => 'عنوان محتوى جديد',
            'titleEn' => 'New content title',
            'bodyAr' => 'محتوى عربي جديد',
            'bodyEn' => 'New English content',
        ])->assertOk()->assertJsonPath('data.id', $page->getKey());

        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'content_page',
            'seoable_id' => $page->getKey(),
            'locale' => 'ar',
            'meta_title' => 'عنوان SEO عربي',
        ]);
        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'content_page',
            'seoable_id' => $page->getKey(),
            'locale' => 'en',
            'meta_title' => 'English SEO title',
        ]);
        $this->assertDatabaseHas('content_pages', [
            'id' => $page->getKey(),
            'title_ar' => 'عنوان محتوى جديد',
            'title_en' => 'New content title',
        ]);
    }

    public function test_settings_are_fixed_typed_partial_and_global_values_have_one_row(): void
    {
        $url = '/api/v1/admin/seo/settings';
        $this->withToken($this->adminToken)->putJson($url, ['settings' => [
            ['key' => 'defaultSeoTitle', 'locale' => 'ar', 'value' => 'عنوان'],
            ['key' => 'canonicalBaseUrl', 'value' => 'https://shop.example'],
            ['key' => 'defaultRobotsPolicy', 'value' => ['index' => false, 'follow' => true]],
        ]])->assertOk();
        $this->assertDatabaseCount('seo_settings', 3);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson($url, ['settings' => [
            ['key' => 'canonicalBaseUrl', 'value' => 'https://shop.example'],
        ]])->assertOk();
        $this->assertDatabaseCount('seo_settings', 3);
        $this->assertDatabaseCount('seo_audits', 3);
        $this->assertDatabaseHas('seo_audits', [
            'target_type' => 'seo_setting',
            'locale' => 'ar',
        ]);

        $this->app['auth']->forgetGuards();
        $history = $this->withToken($this->adminToken)->getJson('/api/v1/admin/seo/history')->assertOk();
        foreach ($history->json('data') as $entry) {
            $this->assertContains($entry['locale'], ['ar', 'en']);
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson($url, ['settings' => [
            ['key' => 'unknown', 'value' => 'bad'],
        ]])->assertUnprocessable();
        $this->assertSame(3, SeoSetting::query()->count());
    }

    public function test_seo_admin_routes_require_an_admin_token_and_history_is_bounded(): void
    {
        $product = $this->product();
        $this->getJson('/api/v1/admin/seo/product/'.$product->id)->assertUnauthorized();
        $this->getJson('/api/v1/admin/seo/history')->assertUnauthorized();
        $this->getJson('/api/v1/admin/seo/history?per_page=101')->assertUnauthorized();

        $nonAdmin = User::factory()->create(['is_active' => true]);
        $nonAdminToken = $nonAdmin->createToken('non-admin', ['admin-access'])->plainTextToken;
        $this->withToken($nonAdminToken)->getJson('/api/v1/admin/seo/history')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/seo/history?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/seo/history?unexpected=1')
            ->assertUnprocessable()->assertJsonValidationErrors('unexpected');
    }

    public function test_history_returns_stable_localized_rows_with_actor_identity_and_iso_timestamps(): void
    {
        $product = $this->product();
        $this->travelTo('2026-10-18 04:00:00');

        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/product/'.$product->id, [
            'locale' => 'ar',
            'metaTitle' => 'Arabic SEO title',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/product/'.$product->id, [
            'locale' => 'en',
            'metaTitle' => 'English SEO title',
        ])->assertOk();

        $arabicAuditId = (string) SeoAudit::query()->where('locale', 'ar')->value('id');
        $englishAuditId = (string) SeoAudit::query()->where('locale', 'en')->value('id');
        $history = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/seo/history?per_page=1')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'record_type', 'record_id', 'locale', 'changed_at', 'changed_by',
                ]],
                'links',
                'meta',
            ])
            ->assertJsonPath('data.0.id', $englishAuditId)
            ->assertJsonPath('data.0.record_type', 'product')
            ->assertJsonPath('data.0.record_id', (string) $product->getKey())
            ->assertJsonPath('data.0.locale', 'en')
            ->assertJsonPath('data.0.changed_at', '2026-10-18T04:00:00.000000Z')
            ->assertJsonPath('data.0.changed_by', 'SEO Admin')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1);

        $changedAt = $history->json('data.0.changed_at');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $changedAt);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s.u\\Z', $changedAt));
        $history->assertJsonMissingPath('data.0.before')
            ->assertJsonMissingPath('data.0.after')
            ->assertJsonMissingPath('data.0.actorId');

        $this->app['auth']->forgetGuards();
        $arabicHistory = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/seo/history?page=2&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $arabicAuditId)
            ->assertJsonPath('data.0.record_type', 'product')
            ->assertJsonPath('data.0.record_id', (string) $product->getKey())
            ->assertJsonPath('data.0.locale', 'ar')
            ->assertJsonPath('data.0.changed_by', 'SEO Admin');

        $this->app['auth']->forgetGuards();
        $repeatedHistory = $this->withToken($this->adminToken)
            ->getJson('/api/v1/admin/seo/history?per_page=1')
            ->assertOk();
        $this->assertSame($englishAuditId, $repeatedHistory->json('data.0.id'));
        $this->assertSame($arabicAuditId, $arabicHistory->json('data.0.id'));
    }

    public function test_seo_images_must_be_public_when_saved(): void
    {
        config(['filesystems.disks.test-private-seo' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/private-seo-admin'),
            'url' => 'https://cdn.example.com/private-seo',
            'visibility' => 'private',
        ]]);
        $product = $this->product();
        $privateImage = MediaAsset::query()->create([
            'disk' => 'test-private-seo',
            'path' => 'private.webp',
            'media_type' => 'image',
            'mime_type' => 'image/webp',
            'size_bytes' => 10,
        ]);

        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/product/'.$product->id, [
            'locale' => 'en', 'openGraph' => ['imageId' => $privateImage->getKey()],
        ])->assertUnprocessable()->assertJsonValidationErrors('openGraph.imageId');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/settings', ['settings' => [
            ['key' => 'storeLogoId', 'value' => $privateImage->getKey()],
        ]])->assertUnprocessable()->assertJsonValidationErrors('settings.0.value');

        $this->assertDatabaseCount('seo_records', 0);
        $this->assertDatabaseCount('seo_settings', 0);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'slug' => 'seo-product-'.uniqid(), 'name_ar' => 'منتج', 'name_en' => 'Product',
        ]);
    }
}
