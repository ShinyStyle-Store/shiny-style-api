<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\MediaRole;
use App\Models\AdminMembership;
use App\Models\Banner;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\User;
use App\Services\BannerService;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

class BannerApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['media.disk' => 'banner-test']);
        Storage::fake('banner-test');
        $admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        AdminMembership::factory()->create([
            'user_id' => $admin->getKey(),
            'status' => AdminMembershipStatus::Active,
            'activated_at' => now(),
        ]);
        $this->adminToken = $admin->createToken('banner-admin', ['admin-access'])->plainTextToken;
    }

    public function test_public_list_is_localized_ordered_and_hides_invalid_banners(): void
    {
        $first = $this->createBanner(['sort_order' => 1, 'title_ar' => 'الأول', 'title_en' => 'First']);
        $second = $this->createBanner(['sort_order' => 1, 'title_ar' => null, 'title_en' => 'Second']);
        $inactive = $this->createBanner(['is_active' => false]);
        $deleted = $this->createBanner();
        $deleted->delete();
        $missing = Banner::query()->create(['title_ar' => 'Missing', 'is_active' => true]);

        $response = $this->withHeader('Accept-Language', 'en-US')->getJson('/api/v1/banners')
            ->assertOk()->assertHeader('Content-Language', 'en')->assertJsonStructure(['data']);
        $this->assertSame([$first->id, $second->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame('First', $response->json('data.0.title'));
        $this->assertSame('Second', $response->json('data.1.title'));
        $this->assertNotNull($first->id);
        $this->assertDatabaseHas('banners', ['id' => $inactive->id]);
        $this->assertDatabaseHas('banners', ['id' => $missing->id]);
        $this->assertStringContainsString('Accept-Language', (string) $response->headers->get('Vary'));
        $this->assertArrayNotHasKey('is_active', $response->json('data.0'));
        $this->assertArrayNotHasKey('sort_order', $response->json('data.0'));
    }

    public function test_public_empty_state_and_independent_translation_fallback(): void
    {
        $banner = $this->createBanner([
            'title_ar' => 'عنوان', 'title_en' => null,
            'description_ar' => null, 'description_en' => 'Description',
            'cta_text_ar' => null, 'cta_text_en' => 'Shop',
            'cta_type' => 'url', 'cta_target' => '/sale',
        ]);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/banners')
            ->assertOk()->assertJsonPath('data.0.title', 'عنوان')
            ->assertJsonPath('data.0.description', 'Description')
            ->assertJsonPath('data.0.cta.text', 'Shop')
            ->assertJsonPath('data.0.image.alt', 'عنوان');
        $banner->delete();
        $this->getJson('/api/v1/banners')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_public_list_eager_loads_media_without_per_banner_database_queries(): void
    {
        $this->createBanner();
        $this->createBanner(['sort_order' => 1]);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries += ((str_contains(strtolower($query->sql), 'banners')
                || str_contains(strtolower($query->sql), 'media_attachments')
                || str_contains(strtolower($query->sql), 'media_assets')) ? 1 : 0);
        });
        $this->getJson('/api/v1/banners')->assertOk();
        $this->assertLessThanOrEqual(4, $queries);
    }

    public function test_admin_authentication_and_create_contract(): void
    {
        $payload = ['title_ar' => 'عنوان', 'is_active' => 'true', 'image' => $this->image()];
        $this->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/banners', $payload, ['Content-Type' => 'multipart/form-data; boundary=TestBoundary'])
            ->assertUnauthorized();
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $this->withToken($customer->createToken('customer', ['customer-access'])->plainTextToken)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/banners', $payload, ['Content-Type' => 'multipart/form-data; boundary=TestBoundary'])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withoutHeader('Authorization')->withToken($this->adminToken);
        $response = $this->multipart('/api/v1/admin/banners', $payload)->assertCreated();
        $response->assertJsonPath('data.title_ar', 'عنوان')->assertJsonPath('data.is_active', true);
        $this->assertDatabaseCount('banners', 1);
        $this->assertDatabaseCount('media_attachments', 1);
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertSame(1, DB::table('media_attachments')->where('role', MediaRole::BANNER_IMAGE)->where('is_primary', true)->count());
    }

    public function test_creation_validation_and_cta_rules(): void
    {
        foreach ([
            [],
            ['title_ar' => '', 'title_en' => ''],
            ['title_ar' => 'Title', 'sort_order' => -1],
            ['title_ar' => 'Title', 'unknown' => 'x'],
        ] as $fields) {
            $this->multipart('/api/v1/admin/banners', [...$fields, 'image' => $this->image()])->assertUnprocessable();
        }
        foreach ([
            ['cta_text_en' => 'Go'],
            ['cta_type' => 'url', 'cta_target' => '/go'],
            ['cta_text_en' => 'Go', 'cta_type' => 'url'],
            ['cta_text_en' => 'Go', 'cta_type' => 'url', 'cta_target' => 'javascript:alert(1)'],
            ['cta_text_en' => 'Go', 'cta_type' => 'url', 'cta_target' => '//evil.example'],
            ['cta_text_en' => 'Go', 'cta_type' => 'url', 'cta_target' => 'http://evil.example'],
        ] as $fields) {
            $this->multipart('/api/v1/admin/banners', ['title_en' => 'Title', ...$fields, 'image' => $this->image()])->assertUnprocessable();
        }
    }

    public function test_cta_product_category_and_https_targets_are_validated(): void
    {
        $product = Product::query()->create(['slug' => 'banner-product', 'name_ar' => 'منتج', 'name_en' => 'Product']);
        $category = Category::query()->create(['slug' => 'banner-category', 'name_ar' => 'تصنيف', 'name_en' => 'Category']);
        foreach ([
            ['cta_type' => 'product', 'cta_target' => $product->slug],
            ['cta_type' => 'category', 'cta_target' => $category->slug],
            ['cta_type' => 'url', 'cta_target' => 'https://example.com/campaign'],
        ] as $cta) {
            $this->multipart('/api/v1/admin/banners', [
                'title_en' => 'Title', 'cta_text_en' => 'Go', ...$cta, 'image' => $this->image(),
            ])->assertCreated();
        }
    }

    public function test_invalid_images_leave_no_database_or_storage_orphans(): void
    {
        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', 'not an image'),
            UploadedFile::fake()->createWithContent('video.mp4', 'not a video'),
        ] as $image) {
            $this->multipart('/api/v1/admin/banners', ['title_en' => 'Title', 'image' => $image])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('image')
                ->assertJsonMissingPath('errors.file');
        }
        $this->assertDatabaseCount('banners', 0);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('media_attachments', 0);
        $this->assertSame([], Storage::disk('banner-test')->allFiles());
    }

    public function test_png_webp_oversized_and_corrupt_images_follow_media_validation(): void
    {
        foreach (['png', 'webp'] as $format) {
            $this->multipart('/api/v1/admin/banners', [
                'title_en' => $format, 'image' => $this->image($format),
            ])->assertCreated();
        }
        $limit = (int) config('media.images.max_image_size_bytes');
        $large = $this->imageContents('jpeg').str_repeat("\0", $limit + 1 - strlen($this->imageContents('jpeg')));
        $this->multipart('/api/v1/admin/banners', [
            'title_en' => 'large', 'image' => UploadedFile::fake()->createWithContent('large.jpg', $large),
        ])->assertUnprocessable()->assertJsonValidationErrors('image')->assertJsonMissingPath('errors.file');
        $corrupt = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $this->multipart('/api/v1/admin/banners', [
            'title_en' => 'corrupt', 'image' => UploadedFile::fake()->createWithContent('corrupt.jpg', $corrupt),
        ])->assertUnprocessable()->assertJsonValidationErrors('image')->assertJsonMissingPath('errors.file');
    }

    public function test_admin_update_validates_final_state_and_show_hides_deleted_banners(): void
    {
        $banner = $this->createBanner(['title_ar' => 'Arabic', 'title_en' => 'English']);
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/banners/'.$banner->id, [
            'title_ar' => null, 'title_en' => null,
        ])->assertUnprocessable();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/banners/'.$banner->id, [
            'cta_type' => 'url', 'cta_target' => '/target',
        ])->assertUnprocessable();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/banners/'.$banner->id, [
            'title_en' => 'Updated', 'is_active' => true, 'sort_order' => 4,
        ])->assertOk()->assertJsonPath('data.title_en', 'Updated');
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/banners/'.$banner->id)->assertOk();
        $banner->delete();
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/banners/'.$banner->id)->assertNotFound();
    }

    public function test_admin_list_includes_inactive_banners_and_invalid_media_is_safe(): void
    {
        $active = $this->createBanner(['is_active' => true]);
        $inactive = $this->createBanner(['is_active' => false]);
        $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/banners')->assertOk();
        $this->assertSame([$active->id, $inactive->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertArrayHasKey('is_active', $response->json('data.0'));
    }

    public function test_replacement_is_atomic_and_deletion_detaches_orphan_but_preserves_shared_assets(): void
    {
        $banner = $this->createBanner();
        $oldAssetId = $banner->bannerImageAttachment->media_asset_id;
        $this->withToken($this->adminToken)->post('/api/v1/admin/banners/'.$banner->id.'/image', [
            'image' => $this->image('png'),
        ])->assertOk();
        $this->assertDatabaseMissing('media_assets', ['id' => $oldAssetId]);
        $this->assertSame(1, $banner->fresh()->mediaAttachments()->count());

        $shared = $this->createBanner();
        $sharedAsset = $shared->bannerImageAttachment->mediaAsset;
        $other = Banner::query()->create(['title_en' => 'Other']);
        app(MediaService::class)->attach($sharedAsset, $other, MediaRole::BANNER_IMAGE, ['is_primary' => true]);
        $this->withToken($this->adminToken)->deleteJson('/api/v1/admin/banners/'.$shared->id)->assertNoContent();
        $this->assertDatabaseHas('media_assets', ['id' => $sharedAsset->id]);
        $this->assertDatabaseCount('media_attachments', 2);
    }

    public function test_invalid_replacement_preserves_the_existing_image(): void
    {
        $banner = $this->createBanner();
        $oldAsset = $banner->bannerImageAttachment->media_asset_id;
        $this->withToken($this->adminToken)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/banners/'.$banner->id.'/image', [
                'image' => UploadedFile::fake()->createWithContent('invalid.jpg', 'not image'),
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('image')
            ->assertJsonMissingPath('errors.file');
        $this->assertDatabaseHas('media_attachments', ['media_asset_id' => $oldAsset, 'is_primary' => true]);
        $this->assertDatabaseCount('media_assets', 1);
    }

    public function test_banner_media_role_rejects_other_owners_and_video_assets(): void
    {
        $banner = Banner::query()->create(['title_en' => 'Banner']);
        $product = Product::query()->create(['slug' => 'role-product', 'name_ar' => 'P', 'name_en' => 'P']);
        $videoPath = 'video/'.uniqid().'.mp4';
        Storage::disk('banner-test')->put($videoPath, 'video bytes');
        $video = MediaAsset::query()->create([
            'disk' => 'banner-test', 'path' => $videoPath, 'media_type' => 'video',
            'mime_type' => 'video/mp4', 'extension' => 'mp4', 'size_bytes' => 10,
        ]);
        try {
            app(MediaService::class)->attach($video, $banner, MediaRole::BANNER_IMAGE, ['is_primary' => true]);
            $this->fail('Video asset was accepted as a banner image.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $image = app(MediaService::class)->uploadImage($this->image());
        $this->expectException(InvalidArgumentException::class);
        app(MediaService::class)->attach($image, $product, MediaRole::BANNER_IMAGE, ['is_primary' => true]);
    }

    private function createBanner(array $attributes = []): Banner
    {
        $data = array_merge([
            'title_ar' => 'عنوان', 'title_en' => 'Title', 'is_active' => true, 'sort_order' => 0,
        ], $attributes);

        return app(BannerService::class)->create($data, $this->image(), User::query()->first());
    }

    private function multipart(string $uri, array $data): TestResponse
    {
        return $this->withToken($this->adminToken)->withHeader('Accept', 'application/json')->post(
            $uri,
            $data,
            ['Content-Type' => 'multipart/form-data; boundary=TestBoundary'],
        );
    }

    private function image(string $format = 'jpeg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $format.'.'.($format === 'jpeg' ? 'jpg' : $format),
            $this->imageContents($format),
        );
    }

    private function imageContents(string $format): string
    {
        return base64_decode(match ($format) {
            'jpeg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAADAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaWjqc3R1dnd3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDzuiiivGPpT//Z',
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=',
            default => 'UklGRjgAAABXRUJQVlA4ICwAAACwAQCdASoCAAMAAUAmJaACdLoABDAAAP73WS/8Buvi2mX/zNH6TfSbuYAAAA==',
        }, true);
    }
}
