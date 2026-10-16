<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\ContentPage;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoSetting;
use App\Models\SellableItem;
use App\Models\SocialLink;
use App\Models\StoreContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreStructuredDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_store_profile_reuses_localized_identity_contact_social_links_currency_and_policy_routes(): void
    {
        config([
            'filesystems.disks.test-public' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/store-profile'),
                'url' => 'https://cdn.example.com/media',
                'visibility' => 'public',
            ],
        ]);
        $logo = $this->image('test-public', 'logos/store.webp');
        $this->setting('store_name', 'ar', 'Ø³ØªØ§ÙŠÙ„ ÙˆØ´Ø§ÙŠÙ†');
        $this->setting('store_name', 'en', 'Style & Shine');
        $this->setting('canonical_base_url', '', 'https://merchant.example.com/');
        $this->setting('store_logo_id', '', $logo->getKey());
        StoreContact::query()->create([
            'support_phone' => '+201111111111',
            'support_whatsapp' => '+201222222222',
            'support_email' => 'support@example.test',
            'address_ar' => 'Ø¹Ù†ÙˆØ§Ù† Ø§Ù„Ù…ØªØ¬Ø±',
            'address_en' => 'Store address',
            'google_maps_url' => 'https://maps.google.com/?q=store',
        ]);
        SocialLink::query()->create([
            'platform_code' => 'facebook',
            'url' => 'https://www.facebook.com/shinystyle',
            'is_enabled' => true,
            'sort_order' => 1,
        ]);
        SocialLink::query()->create([
            'platform_code' => 'instagram',
            'url' => 'https://www.instagram.com/shinystyle',
            'is_enabled' => false,
            'sort_order' => 2,
        ]);
        SocialLink::query()->create([
            'platform_code' => 'whatsapp',
            'url' => 'https://wa.me/201222222222',
            'is_enabled' => true,
            'sort_order' => 3,
        ]);
        SocialLink::query()->create([
            'platform_code' => 'tiktok',
            'url' => 'https://attacker.example.test/shinystyle',
            'is_enabled' => true,
            'sort_order' => 4,
        ]);
        ContentPage::query()->create(['slug' => 'returns-policy']);
        ContentPage::query()->create(['slug' => 'shipping-policy']);
        $snapshotBefore = $this->snapshotTables([
            'seo_settings', 'store_contacts', 'social_links', 'content_pages', 'seo_audits',
        ]);

        $english = $this->withHeader('Accept-Language', 'en-US')->getJson('/api/v1/store/structured-data')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.organization.organizationId', 'https://merchant.example.com/#organization')
            ->assertJsonPath('data.organization.name', 'Style & Shine')
            ->assertJsonPath('data.organization.url', 'https://merchant.example.com')
            ->assertJsonPath('data.organization.logo', 'https://cdn.example.com/media/logos/store.webp')
            ->assertJsonPath('data.organization.contact.supportPhone', '+201111111111')
            ->assertJsonPath('data.organization.contact.supportWhatsapp', '+201222222222')
            ->assertJsonPath('data.organization.contact.supportEmail', 'support@example.test')
            ->assertJsonPath('data.organization.contact.address', 'Store address')
            ->assertJsonPath('data.organization.sameAs', ['https://www.facebook.com/shinystyle'])
            ->assertJsonPath('data.website.websiteId', 'https://merchant.example.com/#website')
            ->assertJsonPath('data.website.inLanguage', 'en')
            ->assertJsonPath('data.website.publisherId', 'https://merchant.example.com/#organization')
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.policies.returns.contentPageSlug', 'returns-policy')
            ->assertJsonPath('data.policies.returns.path', '/return-policy')
            ->assertJsonPath('data.policies.returns.url', 'https://merchant.example.com/return-policy')
            ->assertJsonPath('data.policies.shipping.contentPageSlug', 'shipping-policy')
            ->assertJsonPath('data.policies.shipping.path', '/shipping-policy')
            ->assertJsonPath('data.policies.shipping.url', 'https://merchant.example.com/shipping-policy')
            ->assertJsonMissingPath('data.organization.isEnabled')
            ->assertJsonMissingPath('data.organization.sortOrder')
            ->assertJsonMissingPath('data.adminSettings');

        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/store/structured-data')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.organization.name', 'Ø³ØªØ§ÙŠÙ„ ÙˆØ´Ø§ÙŠÙ†')
            ->assertJsonPath('data.organization.contact.address', 'Ø¹Ù†ÙˆØ§Ù† Ø§Ù„Ù…ØªØ¬Ø±')
            ->assertJsonPath('data.website.inLanguage', 'ar');

        $this->assertSame($snapshotBefore, $this->snapshotTables([
            'seo_settings', 'store_contacts', 'social_links', 'content_pages', 'seo_audits',
        ]));
        $this->assertSame('https://merchant.example.com/#organization', $english->json('data.organization.organizationId'));
        $this->assertSame($logo->getKey(), (int) $this->settingValue('store_logo_id'));

        StoreContact::query()->firstOrFail()->forceFill([
            'google_maps_url' => 'https://attacker.example.com/collect',
        ])->save();
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/store/structured-data')
            ->assertOk()
            ->assertJsonPath('data.organization.contact.googleMapsUrl', null);
    }

    public function test_missing_or_deleted_store_sources_return_null_without_falling_back_to_app_url_or_default_og_image(): void
    {
        config([
            'app.url' => 'https://not-the-canonical-domain.example.test',
            'filesystems.disks.test-public' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/deleted-logo'),
                'url' => 'https://cdn.example.com/media',
                'visibility' => 'public',
            ],
        ]);
        $activeDefaultImage = $this->image('test-public', 'default-og.webp');
        $deletedLogo = $this->image('test-public', 'deleted-logo.webp');
        $deletedLogo->delete();
        $this->setting('default_og_image_id', '', $activeDefaultImage->getKey());
        $logoSetting = $this->setting('store_logo_id', '', $deletedLogo->getKey());
        $this->setting('store_name', 'en', 'English only');
        $this->setting('canonical_base_url', '', 'https://127.0.0.1:8443');

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/store/structured-data')
            ->assertOk()
            ->assertJsonPath('data.organization.organizationId', null)
            ->assertJsonPath('data.organization.name', null)
            ->assertJsonPath('data.organization.url', null)
            ->assertJsonPath('data.organization.logo', null)
            ->assertJsonPath('data.organization.sameAs', [])
            ->assertJsonPath('data.website.websiteId', null)
            ->assertJsonPath('data.website.publisherId', null)
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.policies.returns', null)
            ->assertJsonPath('data.policies.shipping', null);

        config(['filesystems.disks.test-private' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/private-logo'),
            'url' => 'https://private.example.com/media',
            'visibility' => 'private',
        ]]);
        $privateLogo = $this->image('test-private', 'logo.webp');
        $logoSetting->value_json = $privateLogo->getKey();
        $logoSetting->save();

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/store/structured-data')
            ->assertOk()
            ->assertJsonPath('data.organization.logo', null);

        config(['filesystems.disks.private-local-serve' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/private-local-serve'),
            'serve' => true,
            'url' => 'https://merchant.example.com/private',
        ]]);
        $privateLocalLogo = $this->image('private-local-serve', 'logo.webp');
        $logoSetting->value_json = $privateLocalLogo->getKey();
        $logoSetting->save();

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/store/structured-data')
            ->assertOk()
            ->assertJsonPath('data.organization.logo', null);

        $product = $this->product();
        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/products/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.structuredData.product.seller', null);

        config(['filesystems.disks.test-invalid-url' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/invalid-logo-url'),
            'url' => 'ftp://cdn.example.com/media',
            'visibility' => 'public',
        ]]);
        $invalidUrlLogo = $this->image('test-invalid-url', 'logo.webp');
        $logoSetting->value_json = $invalidUrlLogo->getKey();
        $logoSetting->save();

        $this->assertSame($invalidUrlLogo->getKey(), $this->settingValue('store_logo_id'));
        $this->assertSame('test-invalid-url', $invalidUrlLogo->fresh()->disk);
        $this->assertNull($invalidUrlLogo->fresh()->deleted_at);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/store/structured-data')
            ->assertOk()
            ->assertJsonPath('data.organization.logo', null);
    }

    public function test_public_disk_accepts_a_local_url_when_its_visibility_is_explicitly_public(): void
    {
        config(['filesystems.disks.test-public-local' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/public-local-url'),
            'url' => 'http://127.0.0.1:8000/media',
            'visibility' => 'public',
        ]]);
        $logo = $this->image('test-public-local', 'logos/store.webp');
        $this->setting('store_name', 'en', 'Local public store');
        $this->setting('canonical_base_url', '', 'https://merchant.example.com');
        $this->setting('store_logo_id', '', $logo->getKey());

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/store/structured-data')
            ->assertOk()
            ->assertJsonPath('data.organization.logo', Storage::disk('test-public-local')->url($logo->path));
    }

    public function test_product_seller_uses_the_same_identity_as_the_public_store_profile(): void
    {
        $this->setting('store_name', 'en', 'Style & Shine');
        $this->setting('canonical_base_url', '', 'https://merchant.example.com');
        $product = $this->product();

        $profile = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/store/structured-data')->assertOk();
        $detail = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/products/'.$product->slug)->assertOk();

        $this->assertSame(
            [
                'organizationId' => $profile->json('data.organization.organizationId'),
                'name' => $profile->json('data.organization.name'),
                'url' => $profile->json('data.organization.url'),
                'logo' => $profile->json('data.organization.logo'),
            ],
            $detail->json('data.structuredData.product.seller'),
        );
    }

    private function setting(string $key, string $locale, mixed $value): SeoSetting
    {
        return SeoSetting::query()->create([
            'key' => $key,
            'locale' => $locale,
            'value_json' => $value,
        ]);
    }

    private function settingValue(string $key): mixed
    {
        return SeoSetting::query()->where('key', $key)->value('value_json');
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

    private function product(): Product
    {
        $category = Category::query()->create([
            'slug' => 'store-profile-category',
            'name_ar' => 'Ù‚Ø³Ù…',
            'name_en' => 'Category',
            'status' => 'active',
        ]);
        $product = Product::query()->create([
            'slug' => 'store-profile-product',
            'name_ar' => 'Ù…Ù†ØªØ¬',
            'name_en' => 'Product',
            'description_ar' => 'ÙˆØµÙ',
            'description_en' => 'Description',
            'status' => 'active',
            'published_at' => now()->subMinute(),
        ]);
        $product->categories()->attach($category, ['is_primary' => true]);
        SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => 'STORE-PROFILE-SKU',
            'price' => '10.00',
            'stock_quantity' => 1,
            'reserved_quantity' => 0,
            'status' => 'active',
            'is_default' => true,
        ]);

        return $product;
    }
}

