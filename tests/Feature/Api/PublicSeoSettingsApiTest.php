<?php

namespace Tests\Feature\Api;

use App\Models\AdminMembership;
use App\Models\MediaAsset;
use App\Models\SeoSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublicSeoSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $admin->getKey()]);
        $this->adminToken = $admin->createToken('public-seo-settings-admin', ['admin-access'])->plainTextToken;
    }

    public function test_public_endpoint_resolves_fixed_canonicals_and_localized_fallbacks_without_authentication(): void
    {
        config(['seo.indexing_allowed' => true]);
        $this->setting('default_seo_title', 'ar', 'عنوان المتجر الافتراضي');
        $this->setting('default_seo_title', 'en', 'Default store title');
        $this->setting('default_meta_description', 'ar', 'وصف المتجر الافتراضي');
        $this->setting('default_meta_description', 'en', 'Default store description');
        $this->setting('home_seo_title', 'ar', 'عنوان الصفحة الرئيسية');

        $arabic = $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.pages.home.title', 'عنوان الصفحة الرئيسية')
            ->assertJsonPath('data.pages.shop.title', 'عنوان المتجر الافتراضي')
            ->assertJsonPath('data.pages.contact.description', 'وصف المتجر الافتراضي')
            ->assertJsonPath('data.pages.home.canonicalPath', '/')
            ->assertJsonPath('data.pages.shop.canonicalPath', '/shop')
            ->assertJsonPath('data.pages.contact.canonicalPath', '/contact')
            ->assertJsonStructure(['data' => ['locale', 'pages' => [
                'home' => ['title', 'description', 'canonicalPath', 'robots', 'openGraph', 'indexable', 'availableLocales', 'lastSignificantModifiedAt'],
                'shop' => ['title', 'description', 'canonicalPath', 'robots', 'openGraph', 'indexable', 'availableLocales', 'lastSignificantModifiedAt'],
                'contact' => ['title', 'description', 'canonicalPath', 'robots', 'openGraph', 'indexable', 'availableLocales', 'lastSignificantModifiedAt'],
            ]]]);

        $english = $this->withHeader('Accept-Language', 'en-US')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.pages.home.title', 'Default store title')
            ->assertJsonPath('data.pages.home.description', 'Default store description')
            ->assertJsonPath('data.pages.home.openGraph.title', 'Default store title')
            ->assertJsonPath('data.pages.home.openGraph.description', 'Default store description')
            ->assertJsonPath('data.pages.home.availableLocales', ['ar', 'en'])
            ->assertJsonPath('data.pages.home.indexable', true)
            ->assertJsonMissingPath('data.pages.home.id')
            ->assertJsonMissingPath('data.pages.home.key')
            ->assertJsonMissingPath('data.pages.home.value')
            ->assertJsonMissingPath('data.adminSettings')
            ->assertJsonMissingPath('data.audit');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            (string) $english->json('data.pages.home.lastSignificantModifiedAt'),
        );
        $this->assertSame('/', $arabic->json('data.pages.home.canonicalPath'));
    }

    public function test_admin_page_overrides_round_trip_and_null_clears_back_to_global_fallback(): void
    {
        config(['seo.indexing_allowed' => true]);
        $this->setting('default_seo_title', 'ar', 'عنوان افتراضي');
        $this->setting('default_seo_title', 'en', 'Default title');
        $this->setting('default_meta_description', 'ar', 'وصف افتراضي');
        $this->setting('default_meta_description', 'en', 'Default description');

        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/settings', ['settings' => [
            ['key' => 'homeSeoTitle', 'locale' => 'ar', 'value' => 'عنوان الرئيسية المخصص'],
            ['key' => 'homeMetaDescription', 'locale' => 'ar', 'value' => 'وصف الرئيسية المخصص'],
            ['key' => 'homeRobotsPolicy', 'value' => ['index' => false, 'follow' => true]],
        ]])->assertOk();

        $storedTitle = SeoSetting::query()
            ->where('key', 'home_seo_title')
            ->where('locale', 'ar')
            ->firstOrFail();
        $this->assertSame('عنوان الرئيسية المخصص', $storedTitle->value_json);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.pages.home.title', 'عنوان الرئيسية المخصص')
            ->assertJsonPath('data.pages.home.description', 'وصف الرئيسية المخصص')
            ->assertJsonPath('data.pages.home.robots.index', false)
            ->assertJsonPath('data.pages.home.robots.follow', true)
            ->assertJsonPath('data.pages.home.indexable', false);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/settings', ['settings' => [
            ['key' => 'homeSeoTitle', 'locale' => 'ar', 'value' => null],
            ['key' => 'homeMetaDescription', 'locale' => 'ar', 'value' => null],
        ]])->assertOk();

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertJsonPath('data.pages.home.title', 'عنوان افتراضي')
            ->assertJsonPath('data.pages.home.description', 'وصف افتراضي');
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertJsonPath('data.pages.home.title', 'Default title')
            ->assertJsonPath('data.pages.home.description', 'Default description');

        $this->assertDatabaseMissing('seo_settings', ['key' => 'home_seo_title', 'locale' => 'ar']);
        $this->assertDatabaseMissing('seo_settings', ['key' => 'home_meta_description', 'locale' => 'ar']);
    }

    public function test_missing_language_values_do_not_fall_back_across_locales_and_unknown_locale_uses_api_default(): void
    {
        $this->setting('contact_seo_title', 'ar', 'عنوان عربي فقط');

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.pages.contact.title', null)
            ->assertJsonPath('data.pages.contact.description', null);

        $this->withHeader('Accept-Language', 'fr-FR')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.locale', 'ar')
            ->assertJsonPath('data.pages.contact.title', 'عنوان عربي فقط');

        $this->getJson('/api/v1/seo/settings?unexpected=value')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unexpected');
    }

    public function test_page_image_uses_shared_public_validation_and_falls_back_for_private_invalid_or_deleted_assets(): void
    {
        $this->configureImageDisks();
        $defaultImage = $this->image('seo-test-public', 'default-og.webp');
        $pageImage = $this->image('seo-test-public', 'home-og.webp');
        $privateImage = $this->image('seo-test-private', 'private-og.webp');
        $invalidUrlImage = $this->image('seo-test-invalid', 'invalid-og.webp');
        $deletedImage = $this->image('seo-test-public', 'deleted-og.webp');
        $this->setting('default_og_image_id', '', $defaultImage->getKey());
        $pageSetting = $this->setting('home_og_image_id', '', $pageImage->getKey());

        $this->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertJsonPath('data.pages.home.openGraph.image', 'https://cdn.example.test/media/home-og.webp');

        foreach ([$privateImage, $invalidUrlImage] as $unavailableImage) {
            $pageSetting->value_json = $unavailableImage->getKey();
            $pageSetting->save();

            $this->getJson('/api/v1/seo/settings')
                ->assertOk()
                ->assertJsonPath('data.pages.home.openGraph.image', 'https://cdn.example.test/media/default-og.webp');
        }

        $deletedImage->delete();
        $pageSetting->value_json = $deletedImage->getKey();
        $pageSetting->save();
        $this->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertJsonPath('data.pages.home.openGraph.image', 'https://cdn.example.test/media/default-og.webp');

        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/settings', ['settings' => [
            ['key' => 'shopOgImageId', 'value' => $privateImage->getKey()],
        ]])->assertUnprocessable()->assertJsonValidationErrors('settings.0.value');
    }

    public function test_public_reads_do_not_write_settings_or_audits_and_noop_admin_updates_do_not_revalidate(): void
    {
        config([
            'seo_revalidation.enabled' => true,
            'seo_revalidation.queue_connection' => 'database',
            'queue.connections.database.driver' => 'database',
        ]);
        Queue::fake();
        $this->setting('shop_seo_title', 'en', 'Shop title');
        $before = $this->snapshotSettingsAndAudits();

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/seo/settings')->assertOk();

        $this->assertSame($before, $this->snapshotSettingsAndAudits());
        $auditsBeforeNoop = DB::table('seo_audits')->count();
        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/settings', ['settings' => [
            ['key' => 'shopSeoTitle', 'locale' => 'en', 'value' => 'Shop title'],
        ]])->assertOk();

        $this->assertSame($auditsBeforeNoop, DB::table('seo_audits')->count());
        Queue::assertNothingPushed();
    }

    public function test_admin_settings_endpoint_stays_protected(): void
    {
        $this->getJson('/api/v1/admin/seo/settings')->assertUnauthorized();
    }

    private function setting(string $key, string $locale, mixed $value): SeoSetting
    {
        return SeoSetting::query()->create([
            'key' => $key,
            'locale' => $locale,
            'value_json' => $value,
        ]);
    }

    private function configureImageDisks(): void
    {
        config([
            'filesystems.disks.seo-test-public' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/seo-settings-public'),
                'url' => 'https://cdn.example.test/media',
                'visibility' => 'public',
            ],
            'filesystems.disks.seo-test-private' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/seo-settings-private'),
                'url' => 'https://cdn.example.test/private',
                'visibility' => 'private',
            ],
            'filesystems.disks.seo-test-invalid' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/seo-settings-invalid'),
                'url' => 'ftp://cdn.example.test/media',
                'visibility' => 'public',
            ],
        ]);
    }

    private function image(string $disk, string $path): MediaAsset
    {
        return MediaAsset::query()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => basename($path),
            'media_type' => 'image',
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'size_bytes' => 100,
        ]);
    }

    /** @return array{settings: list<array<string, mixed>>, audits: list<array<string, mixed>>} */
    private function snapshotSettingsAndAudits(): array
    {
        return [
            'settings' => DB::table('seo_settings')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'audits' => DB::table('seo_audits')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }
}
