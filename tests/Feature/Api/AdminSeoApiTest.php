<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\ContentPage;
use App\Models\Product;
use App\Models\SeoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSeoApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->getKey()]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
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
            'locale' => 'en', 'metaTitle' => 'About us',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/'.rawurlencode(Product::class).'/1', [
            'locale' => 'en', 'metaTitle' => 'Rejected',
        ])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/content_page/'.$page->id, [
            'locale' => 'en', 'canonicalPath' => '//external.example/about-us',
        ])->assertUnprocessable()->assertJsonValidationErrors('canonicalPath');
        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'category', 'seoable_id' => $category->id, 'canonical_path' => '/category/seo-category',
        ]);
        $this->assertDatabaseHas('seo_records', [
            'seoable_type' => 'content_page', 'seoable_id' => $page->id,
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
        $this->getJson('/api/v1/admin/seo/history?per_page=101')->assertUnauthorized();

        $this->withToken($this->adminToken)->getJson('/api/v1/admin/seo/history?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/seo/history?unexpected=1')
            ->assertUnprocessable()->assertJsonValidationErrors('unexpected');
    }

    private function product(): Product
    {
        return Product::query()->create([
            'slug' => 'seo-product-'.uniqid(), 'name_ar' => 'منتج', 'name_en' => 'Product',
        ]);
    }
}
