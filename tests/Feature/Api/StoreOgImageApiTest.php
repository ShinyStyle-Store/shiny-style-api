<?php

namespace Tests\Feature\Api;

use App\Enums\MediaRole;
use App\Models\AdminMembership;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\SeoSetting;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class StoreOgImageApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeMediaDisk('seo-og-public', 'public');

        $this->admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create(['user_id' => $this->admin->getKey()]);
        $this->adminToken = $this->admin->createToken('store-og-image-admin', ['admin-access'])->plainTextToken;
    }

    public function test_admin_upload_sets_global_image_and_public_settings_return_the_same_url_for_both_locales(): void
    {
        $response = $this->upload($this->image(), $this->adminToken);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'url']]);

        $assetId = $response->json('data.id');
        $asset = MediaAsset::query()->findOrFail($assetId);
        $this->assertSame('image', $asset->media_type);
        $this->assertSame((int) $this->admin->getKey(), $asset->created_by);
        $this->assertSame(Storage::disk('seo-og-public')->url($asset->path), $response->json('data.url'));
        $this->assertDatabaseHas('seo_settings', [
            'key' => 'default_og_image_id',
            'locale' => '',
            'value_json' => (string) $assetId,
        ]);
        $this->assertDatabaseCount('media_attachments', 0);

        $auditCount = DB::table('seo_audits')->count();
        foreach (['ar', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)->getJson('/api/v1/seo/settings')
                ->assertOk()
                ->assertJsonPath('data.locale', $locale)
                ->assertJsonPath('data.pages.home.openGraph.image', $response->json('data.url'))
                ->assertJsonPath('data.pages.shop.openGraph.image', $response->json('data.url'))
                ->assertJsonPath('data.pages.contact.openGraph.image', $response->json('data.url'));
        }
        $this->assertSame($auditCount, DB::table('seo_audits')->count());
    }

    public function test_upload_requires_admin_authentication_and_membership(): void
    {
        $this->upload($this->image())->assertUnauthorized();

        $customer = User::factory()->create();
        $customerToken = $customer->createToken('customer', ['customer-access'])->plainTextToken;
        $this->upload($this->image(), $customerToken)->assertForbidden();
    }

    public function test_non_image_and_over_limit_images_are_rejected_without_creating_assets(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('not-image.jpg', 'plain text'), $this->adminToken)
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $limit = (int) config('media.images.max_image_size_bytes');
        $contents = $this->pngContents();
        $contents .= str_repeat("\0", $limit + 1 - strlen($contents));
        $this->app['auth']->forgetGuards();
        $this->upload(UploadedFile::fake()->createWithContent('too-large.png', $contents), $this->adminToken)
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('seo_settings', 0);
    }

    public function test_replacement_keeps_the_old_media_asset_and_updates_only_the_global_setting(): void
    {
        $first = $this->upload($this->image('first.png'), $this->adminToken)->assertCreated();
        $oldAssetId = $first->json('data.id');
        $this->app['auth']->forgetGuards();

        $second = $this->upload($this->image('second.png'), $this->adminToken)->assertCreated();
        $newAssetId = $second->json('data.id');

        $this->assertNotSame($oldAssetId, $newAssetId);
        $this->assertDatabaseHas('media_assets', ['id' => $oldAssetId, 'deleted_at' => null]);
        $this->assertDatabaseHas('seo_settings', [
            'key' => 'default_og_image_id',
            'locale' => '',
            'value_json' => (string) $newAssetId,
        ]);
        Storage::disk('seo-og-public')->assertExists(MediaAsset::query()->findOrFail($oldAssetId)->path);
    }

    public function test_store_upload_does_not_change_existing_product_media_attachments(): void
    {
        $product = Product::query()->create([
            'slug' => 'store-og-image-product',
            'name_ar' => 'منتج',
            'name_en' => 'Product',
        ]);
        $productImage = $this->uploadOldImage();
        $attachment = app(MediaService::class)->attach($productImage, $product, MediaRole::PRODUCT_IMAGE, [
            'is_primary' => true,
        ]);

        $response = $this->upload($this->image('store.png'), $this->adminToken)->assertCreated();

        $this->assertDatabaseHas('media_attachments', [
            'id' => $attachment->getKey(),
            'media_asset_id' => $productImage->getKey(),
            'mediable_type' => $product->getMorphClass(),
            'mediable_id' => $product->getKey(),
            'role' => MediaRole::PRODUCT_IMAGE,
        ]);
        $this->assertDatabaseMissing('media_attachments', [
            'media_asset_id' => $response->json('data.id'),
        ]);
    }

    public function test_private_media_disk_is_rejected_and_existing_setting_is_preserved(): void
    {
        $oldAsset = $this->uploadOldImage();
        $this->setting((int) $oldAsset->getKey());
        $this->fakeMediaDisk('seo-og-private', 'private');

        $this->upload($this->image('private.png'), $this->adminToken)
            ->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->assertDatabaseHas('seo_settings', [
            'key' => 'default_og_image_id', 'locale' => '', 'value_json' => (string) $oldAsset->getKey(),
        ]);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_setting_save_failure_cleans_up_the_new_upload_and_preserves_the_old_image(): void
    {
        $oldAsset = $this->uploadOldImage();
        $this->setting((int) $oldAsset->getKey());
        SeoSetting::updating(function (SeoSetting $setting): void {
            if ($setting->key === 'default_og_image_id') {
                throw new RuntimeException('setting write failed');
            }
        });

        $this->upload($this->image('failed-save.png'), $this->adminToken)->assertInternalServerError();

        $this->assertDatabaseHas('seo_settings', [
            'key' => 'default_og_image_id', 'locale' => '', 'value_json' => (string) $oldAsset->getKey(),
        ]);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertSame([$oldAsset->path], Storage::disk('seo-og-public')->allFiles());
    }

    public function test_null_setting_clears_public_image_without_deleting_media_and_reads_do_not_write(): void
    {
        $asset = $this->uploadOldImage();
        $this->setting((int) $asset->getKey());
        $settingsBefore = DB::table('seo_settings')->count();
        $auditsBefore = DB::table('seo_audits')->count();

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertJsonPath('data.pages.home.openGraph.image', 'https://cdn.example.test/media/'.$asset->path);
        $this->assertSame($settingsBefore, DB::table('seo_settings')->count());
        $this->assertSame($auditsBefore, DB::table('seo_audits')->count());

        $this->withToken($this->adminToken)->putJson('/api/v1/admin/seo/settings', ['settings' => [
            ['key' => 'defaultOgImageId', 'value' => null],
        ]])->assertOk();

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/seo/settings')
            ->assertOk()
            ->assertJsonPath('data.pages.home.openGraph.image', null);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->getKey(), 'deleted_at' => null]);
        Storage::disk('seo-og-public')->assertExists($asset->path);
    }

    private function fakeMediaDisk(string $name, string $visibility): void
    {
        config([
            'media.disk' => $name,
            "filesystems.disks.{$name}.driver" => 'local',
            "filesystems.disks.{$name}.root" => storage_path('framework/testing/disks/'.$name),
            "filesystems.disks.{$name}.url" => 'https://cdn.example.test/media',
            "filesystems.disks.{$name}.visibility" => $visibility,
        ]);
        Storage::fake($name, [
            'url' => 'https://cdn.example.test/media',
            'visibility' => $visibility,
        ]);
    }

    private function image(string $name = 'store.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngContents());
    }

    private function upload(UploadedFile $image, ?string $token = null): TestResponse
    {
        $request = $token === null ? $this : $this->withToken($token);

        return $request->post('/api/v1/admin/seo/default-og-image', ['image' => $image], [
            'Content-Type' => 'multipart/form-data; boundary=TestBoundary',
            'Accept' => 'application/json',
        ]);
    }

    private function pngContents(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=',
            true,
        ) ?: '';
    }

    private function uploadOldImage(): MediaAsset
    {
        return app(MediaService::class)->uploadImage($this->image('old.png'), $this->admin);
    }

    private function setting(int $assetId): SeoSetting
    {
        return SeoSetting::query()->create([
            'key' => 'default_og_image_id',
            'locale' => '',
            'value_json' => $assetId,
        ]);
    }
}
