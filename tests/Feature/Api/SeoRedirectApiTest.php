<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\Product;
use App\Models\SeoRedirect;
use App\Models\SeoSetting;
use App\Models\SellableItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class SeoRedirectApiTest extends TestCase
{
    use DatabaseMigrations;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->getKey()]);
        $this->adminToken = $admin->createToken('redirect-admin', ['admin-access'])->plainTextToken;
        SeoSetting::query()->create([
            'key' => 'canonical_base_url',
            'locale' => '',
            'value_json' => 'https://shop.example.com',
        ]);
    }

    public function test_public_noindex_product_slug_changes_create_locale_paths_and_flatten_history(): void
    {
        $product = $this->product('active', 'redirect-product-'.Str::lower(Str::random(8)));
        foreach (['ar', 'en'] as $locale) {
            $product->seoRecords()->create(['locale' => $locale, 'robots_index' => false]);
        }
        $oldSlug = $product->slug;
        $firstSlug = $oldSlug.'-first';
        $finalSlug = $oldSlug.'-final';

        $this->resolve('/ar/products/'.$oldSlug)->assertJsonPath('data', null);
        $this->patchProductSlug($product, $firstSlug)->assertOk();

        $this->assertDatabaseCount('seo_redirects', 2);
        $this->assertDatabaseHas('seo_redirects', [
            'domain_scope' => 'shop.example.com',
            'source_path' => '/ar/products/'.$oldSlug,
            'destination_path' => '/ar/products/'.$firstSlug,
            'status_code' => 301,
            'is_active' => true,
            'reason' => 'slug_change',
        ]);
        $this->assertNotNull($product->fresh()->seo_last_significant_modified_at);

        $this->resolve('/ar/products/'.$oldSlug)
            ->assertJsonPath('data.destinationPath', '/ar/products/'.$firstSlug)
            ->assertJsonPath('data.statusCode', 301);
        $this->resolve('/en/products/'.$oldSlug)
            ->assertJsonPath('data.destinationPath', '/en/products/'.$firstSlug);

        $this->patchProductSlug($product->fresh(), $finalSlug)->assertOk();

        $this->assertDatabaseCount('seo_redirects', 4);
        $this->assertDatabaseHas('seo_redirects', [
            'source_path' => '/ar/products/'.$oldSlug,
            'destination_path' => '/ar/products/'.$finalSlug,
        ]);
        $this->assertDatabaseHas('seo_redirects', [
            'source_path' => '/en/products/'.$firstSlug,
            'destination_path' => '/en/products/'.$finalSlug,
        ]);
        $this->resolve('/ar/products/'.$oldSlug)
            ->assertJsonPath('data.destinationPath', '/ar/products/'.$finalSlug);
        $this->assertDatabaseHas('seo_audits', [
            'target_type' => 'product',
            'target_id' => $product->getKey(),
            'action' => 'update',
        ]);
    }

    public function test_draft_slug_changes_do_not_create_redirects_and_repeated_slug_updates_do_not_duplicate_them(): void
    {
        $draft = $this->product('draft', 'redirect-draft-'.Str::lower(Str::random(8)));
        $this->patchProductSlug($draft, $draft->slug.'-new')->assertOk();
        $this->assertDatabaseCount('seo_redirects', 0);

        $public = $this->product('active', 'redirect-stable-'.Str::lower(Str::random(8)));
        $newSlug = $public->slug.'-new';
        $this->patchProductSlug($public, $newSlug)->assertOk();
        $this->patchProductSlug($public->fresh(), $newSlug)->assertOk();

        $this->assertDatabaseCount('seo_redirects', 2);
    }

    public function test_product_cannot_reuse_a_historical_slug_after_its_redirect_is_created(): void
    {
        $product = $this->product('active', 'redirect-reuse-'.Str::lower(Str::random(8)));
        $historicalSlug = $product->slug;
        $currentSlug = $historicalSlug.'-current';

        $this->patchProductSlug($product, $currentSlug)->assertOk();
        $this->patchProductSlug($product->fresh(), $historicalSlug)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->assertDatabaseHas('products', [
            'id' => $product->getKey(),
            'slug' => $currentSlug,
        ]);
        $this->assertDatabaseCount('seo_redirects', 2);
    }

    public function test_existing_manual_redirects_follow_a_renamed_product_without_creating_a_chain(): void
    {
        $product = $this->product('active', 'redirect-target-'.Str::lower(Str::random(8)));
        $sourcePath = '/en/products/redirect-alias-'.Str::lower(Str::random(8));
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/redirects', [
            'sourcePath' => $sourcePath,
            'destinationPath' => '/en/products/'.$product->slug,
            'statusCode' => 301,
        ])->assertCreated();

        $firstSlug = $product->slug.'-first';
        $finalSlug = $product->slug.'-final';
        $this->patchProductSlug($product, $firstSlug)->assertOk();
        $this->patchProductSlug($product->fresh(), $finalSlug)->assertOk();

        $this->assertDatabaseHas('seo_redirects', [
            'source_path' => $sourcePath,
            'destination_path' => '/en/products/'.$finalSlug,
        ]);
        $this->resolve($sourcePath)
            ->assertJsonPath('data.destinationPath', '/en/products/'.$finalSlug);
    }

    public function test_manual_redirect_api_audits_updates_disables_and_invalidates_negative_and_positive_cache_entries(): void
    {
        $destination = $this->product('active', 'redirect-destination-'.Str::lower(Str::random(8)));
        $sourcePath = '/en/products/redirect-source-'.Str::lower(Str::random(8));
        $this->resolve($sourcePath)->assertJsonPath('data', null);

        $created = $this->withToken($this->adminToken)->postJson('/api/v1/admin/redirects', [
            'sourcePath' => $sourcePath,
            'destinationPath' => '/en/products/'.$destination->slug,
            'statusCode' => 308,
        ])->assertCreated()->assertJsonPath('data.statusCode', 308);
        $redirectId = $created->json('data.id');

        $this->resolve($sourcePath)
            ->assertJsonPath('data.destinationPath', '/en/products/'.$destination->slug)
            ->assertJsonPath('data.statusCode', 308);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/redirects?per_page=1')
            ->assertOk()->assertJsonPath('meta.per_page', 1);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/redirects/'.$redirectId, [
            'statusCode' => 301,
        ])->assertOk()->assertJsonPath('data.statusCode', 301);
        $this->resolve($sourcePath)->assertJsonPath('data.statusCode', 301);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->deleteJson('/api/v1/admin/redirects/'.$redirectId)
            ->assertOk()->assertJsonPath('data.isActive', false);
        $this->resolve($sourcePath)->assertJsonPath('data', null);
        $this->assertDatabaseHas('seo_redirects', ['id' => $redirectId, 'is_active' => false]);
        $this->assertDatabaseHas('seo_audits', ['target_type' => 'seo_redirect', 'target_id' => $redirectId, 'action' => 'create']);
        $this->assertDatabaseHas('seo_audits', ['target_type' => 'seo_redirect', 'target_id' => $redirectId, 'action' => 'update']);
        $this->assertDatabaseHas('seo_audits', ['target_type' => 'seo_redirect', 'target_id' => $redirectId, 'action' => 'delete']);
    }

    public function test_manual_redirect_rejects_live_sources_unsafe_paths_loops_and_nonpublic_destinations(): void
    {
        $live = $this->product('active', 'redirect-live-'.Str::lower(Str::random(8)));
        $hidden = $this->product('draft', 'redirect-hidden-'.Str::lower(Str::random(8)));
        $other = $this->product('active', 'redirect-other-'.Str::lower(Str::random(8)));
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/redirects', [
            'sourcePath' => '/en/products/'.$live->slug,
            'destinationPath' => '/en/products/'.$other->slug,
            'statusCode' => 301,
        ])->assertUnprocessable()->assertJsonValidationErrors('sourcePath');

        foreach ([
            ['//attacker.example/path', '/en/products/'.$other->slug],
            ['/en/checkout', '/en/products/'.$other->slug],
            ['/en/products/not-live', 'https://attacker.example/path'],
            ['/en/products/self-loop', '/en/products/self-loop'],
            ['/en/products/hidden-destination', '/en/products/'.$hidden->slug],
        ] as [$source, $destination]) {
            $this->app['auth']->forgetGuards();
            $this->withToken($this->adminToken)->postJson('/api/v1/admin/redirects', [
                'sourcePath' => $source,
                'destinationPath' => $destination,
                'statusCode' => 301,
            ])->assertUnprocessable();
        }
    }

    public function test_product_slug_update_rolls_back_when_redirect_source_conflicts_and_prevents_slug_reuse(): void
    {
        $product = $this->product('active', 'redirect-conflict-'.Str::lower(Str::random(8)));
        $oldSlug = $product->slug;
        $sourcePath = '/ar/products/'.$oldSlug;
        SeoRedirect::query()->create([
            'domain_scope' => 'shop.example.com',
            'source_path' => $sourcePath,
            'source_hash' => hash('sha256', $sourcePath),
            'destination_path' => '/ar/products/elsewhere',
            'status_code' => 301,
            'is_active' => false,
            'reason' => 'manual',
            'entity_type' => 'category',
            'entity_id' => 99,
        ]);

        $this->patchProductSlug($product, $oldSlug.'-new')
            ->assertUnprocessable()->assertJsonValidationErrors('slug');

        $this->assertDatabaseHas('products', ['id' => $product->getKey(), 'slug' => $oldSlug]);
        $this->assertDatabaseCount('seo_redirects', 1);
        $this->assertDatabaseCount('seo_audits', 0);
    }

    public function test_resolver_rechecks_destination_visibility_and_returns_null_when_it_is_hidden(): void
    {
        $destination = $this->product('active', 'redirect-visibility-'.Str::lower(Str::random(8)));
        $sourcePath = '/ar/products/redirect-hidden-target-'.Str::lower(Str::random(8));
        $response = $this->withToken($this->adminToken)->postJson('/api/v1/admin/redirects', [
            'sourcePath' => $sourcePath,
            'destinationPath' => '/ar/products/'.$destination->slug,
            'statusCode' => 301,
        ])->assertCreated();
        $redirectId = $response->json('data.id');
        $this->resolve($sourcePath)->assertJsonPath('data.destinationPath', '/ar/products/'.$destination->slug);

        $destination->delete();

        $this->resolve($sourcePath)->assertJsonPath('data', null);
        $this->assertDatabaseHas('seo_redirects', ['id' => $redirectId, 'is_active' => true]);
    }

    public function test_redirect_admin_requires_admin_access_and_resolver_rejects_unrecognized_path_inputs(): void
    {
        $this->getJson('/api/v1/admin/redirects')->assertUnauthorized();
        $user = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $user->getKey()]);
        $this->withToken($user->createToken('insufficient', ['*'])->plainTextToken)
            ->getJson('/api/v1/admin/redirects')->assertForbidden();

        $this->getJson('/api/v1/seo/redirects/resolve?path='.urlencode('https://attacker.example/path'))
            ->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->getJson('/api/v1/seo/redirects/resolve?path='.urlencode('/en/login'))
            ->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->getJson('/api/v1/seo/redirects/resolve')
            ->assertUnprocessable()->assertJsonValidationErrors('path');
    }

    public function test_redirect_admin_rejects_a_guest_request(): void
    {
        $this->getJson('/api/v1/admin/redirects?per_page=1')->assertUnauthorized();
    }

    private function resolve(string $path): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/seo/redirects/resolve?path='.urlencode($path));
    }

    private function patchProductSlug(Product $product, string $slug): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->adminToken)
            ->patchJson('/api/v1/admin/products/'.$product->getKey(), ['slug' => $slug]);
    }

    private function product(string $status, string $slug): Product
    {
        $category = Category::query()->create([
            'slug' => 'redirect-category-'.Str::lower(Str::random(8)),
            'name_ar' => 'قسم',
            'name_en' => 'Category',
            'status' => 'active',
        ]);
        $product = Product::query()->create([
            'slug' => $slug,
            'name_ar' => 'منتج',
            'name_en' => 'Product',
            'status' => $status,
            'published_at' => $status === 'active' ? now()->subMinute() : null,
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => 'REDIRECT-'.Str::upper(Str::random(10)),
            'price' => 100,
            'stock_quantity' => 2,
            'status' => 'active',
            'is_default' => true,
        ]);

        return $product;
    }
}
