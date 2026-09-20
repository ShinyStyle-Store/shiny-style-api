<?php

namespace Tests\Feature\Api;

use App\Enums\AdminMembershipStatus;
use App\Enums\MediaRole;
use App\Models\AdminMembership;
use App\Models\Category;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CategoryMediaApiTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['media.disk' => 'media-test']);
        Storage::fake('media-test');

        $admin = User::factory()->create(['is_active' => true]);
        AdminMembership::factory()->create([
            'user_id' => $admin->getKey(),
            'status' => AdminMembershipStatus::Active,
        ]);
        $this->adminToken = $admin->createToken('admin', ['admin-access'])->plainTextToken;
    }

    public function test_multipart_create_accepts_jpeg_png_and_webp_and_returns_safe_cover_data(): void
    {
        foreach (['jpeg', 'png', 'webp'] as $format) {
            $slug = 'media-create-'.$format;
            $response = $this->multipartPost('/api/v1/admin/categories', [
                ...$this->payload($slug),
                'cover_image' => $this->image($format),
            ]);

            $response->assertCreated()
                ->assertJsonPath('data.slug', $slug)
                ->assertJsonPath('data.coverImage.width', 2)
                ->assertJsonPath('data.coverImage.height', 3)
                ->assertJsonPath('data.coverImage.mimeType', $this->mime($format))
                ->assertJsonPath('data.coverImage.altAr', 'Arabic '.$slug)
                ->assertJsonPath('data.coverImage.altEn', 'English '.$slug);
            $cover = $response->json('data.coverImage');
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $cover['id']);
            $this->assertArrayHasKey('sizeBytes', $cover);
            foreach (['disk', 'path', 'checksum', 'original_name', 'created_by'] as $hidden) {
                $this->assertArrayNotHasKey($hidden, $cover);
            }
            $this->assertArrayNotHasKey('coverImage'.'Url', $response->json('data'));
        }
    }

    public function test_create_without_a_file_and_unknown_media_fields_follow_existing_contract(): void
    {
        $this->withToken($this->adminToken)->postJson('/api/v1/admin/categories', $this->payload('json-no-image'))
            ->assertCreated()->assertJsonPath('data.coverImage', null);
        $this->app['auth']->forgetGuards();
        $this->multipartPost('/api/v1/admin/categories', $this->payload('multipart-no-image'))
            ->assertCreated()->assertJsonPath('data.coverImage', null);
        $this->getJson('/api/v1/categories/multipart-no-image')
            ->assertOk()->assertJsonPath('data.coverImage', null);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->post('/api/v1/admin/categories', [
            ...$this->payload('remove-on-create'), 'remove_cover_image' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('remove_cover_image');

        foreach (['role', 'disk', 'path', 'mediable_type', 'media_asset_id', 'media_id', 'cover_image'.'_url'] as $field) {
            $this->app['auth']->forgetGuards();
            $this->withToken($this->adminToken)->post('/api/v1/admin/categories', [
                ...$this->payload('unknown-'.$field), $field => 'unexpected',
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_invalid_or_oversized_upload_does_not_create_category(): void
    {
        foreach ([
            UploadedFile::fake()->createWithContent('bad.jpg', 'not an image'),
            $this->oversizedValidJpeg(),
        ] as $index => $image) {
            $this->app['auth']->forgetGuards();
            $this->multipartPost('/api/v1/admin/categories', [
                ...$this->payload('invalid-image-'.$index), 'cover_image' => $image,
            ])->assertUnprocessable()->assertJsonValidationErrors('cover_image');
        }

        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_category_create_accepts_an_image_at_the_configured_byte_limit(): void
    {
        $limit = (int) config('media.images.max_image_size_bytes');
        $contents = $this->imageContents('jpeg');
        $contents .= str_repeat("\0", $limit - strlen($contents));

        $response = $this->multipartPost('/api/v1/admin/categories', [
            ...$this->payload('image-at-limit'),
            'cover_image' => UploadedFile::fake()->createWithContent('at-limit.jpg', $contents),
        ])->assertCreated();

        $this->assertSame($limit, $response->json('data.coverImage.sizeBytes'));
    }

    public function test_multipart_spoofed_update_can_replace_cover_and_cleans_only_orphaned_assets(): void
    {
        $category = $this->category('replace-cover');
        $oldAsset = app(MediaService::class)->uploadImage($this->image('png'));
        app(MediaService::class)->attach($oldAsset, $category, MediaRole::CATEGORY_COVER);
        $sharedAsset = app(MediaService::class)->uploadImage($this->image('jpeg'));
        app(MediaService::class)->attach($sharedAsset, $category, MediaRole::CATEGORY_COVER);
        $otherCategory = $this->category('shared-cover');
        app(MediaService::class)->attach($sharedAsset, $otherCategory, MediaRole::CATEGORY_COVER);

        $response = $this->multipartPost('/api/v1/admin/categories/'.$category->id, [
            '_method' => 'PATCH',
            'name_en' => 'Replaced name',
            'cover_image' => $this->image('webp'),
        ])->assertOk()->assertJsonPath('data.nameEn', 'Replaced name');

        $this->assertDatabaseMissing('media_attachments', ['media_asset_id' => $oldAsset->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $oldAsset->id]);
        Storage::disk('media-test')->assertMissing($oldAsset->path);
        $this->assertDatabaseHas('media_assets', ['id' => $sharedAsset->id]);
        Storage::disk('media-test')->assertExists($sharedAsset->path);
        $this->assertSame(1, $category->mediaAttachments()->where('role', MediaRole::CATEGORY_COVER)->count());
        $this->assertNotSame($sharedAsset->public_id, $response->json('data.coverImage.id'));
        $this->assertArrayNotHasKey('_method', $category->fresh()->getAttributes());
    }

    public function test_invalid_replacement_preserves_the_old_cover_and_category_fields(): void
    {
        $category = $this->category('invalid-replacement');
        $oldAsset = app(MediaService::class)->uploadImage($this->image('png'));
        app(MediaService::class)->attach($oldAsset, $category, MediaRole::CATEGORY_COVER);

        $this->multipartPost('/api/v1/admin/categories/'.$category->id, [
            '_method' => 'PATCH',
            'name_en' => 'Must not be saved',
            'cover_image' => UploadedFile::fake()->createWithContent('fake.jpg', 'not image bytes'),
        ])->assertUnprocessable()->assertJsonValidationErrors('cover_image');

        $this->assertSame('English invalid-replacement', $category->fresh()->name_en);
        $this->assertDatabaseHas('media_attachments', ['media_asset_id' => $oldAsset->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $oldAsset->id]);
        Storage::disk('media-test')->assertExists($oldAsset->path);
    }

    public function test_update_image_only_remove_idempotence_false_flag_and_conflicts(): void
    {
        $category = $this->category('cover-operations');
        $cover = app(MediaService::class)->uploadImage($this->image('png'));
        app(MediaService::class)->attach($cover, $category, MediaRole::CATEGORY_COVER);

        $this->withToken($this->adminToken)->patch('/api/v1/admin/categories/'.$category->id, [
            'remove_cover_image' => false,
        ])->assertOk()->assertJsonPath('data.coverImage.id', $cover->public_id);
        $this->app['auth']->forgetGuards();
        $this->multipartPost('/api/v1/admin/categories/'.$category->id, [
            '_method' => 'PATCH',
            'cover_image' => $this->image('webp'),
            'remove_cover_image' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['cover_image', 'remove_cover_image']);

        $this->app['auth']->forgetGuards();
        $this->multipartPost('/api/v1/admin/categories/'.$category->id, [
            '_method' => 'patch',
            'cover_image' => $this->image('jpeg'),
        ])->assertOk()->assertJsonPath('data.coverImage.mimeType', 'image/jpeg');
        $this->app['auth']->forgetGuards();
        $this->multipartPost('/api/v1/admin/categories/'.$category->id, [
            '_method' => 'PATCH', 'remove_cover_image' => '1',
        ])->assertOk()->assertJsonPath('data.coverImage', null);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adminToken)->patchJson('/api/v1/admin/categories/'.$category->id, [
            'remove_cover_image' => true,
        ])->assertOk()->assertJsonPath('data.coverImage', null);

        $this->assertDatabaseCount('media_attachments', 0);
        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_method_spoof_is_transport_only_and_invalid_or_unrelated_fields_are_rejected(): void
    {
        $category = $this->category('method-spoof-check');

        foreach (['PUT', 'DELETE'] as $method) {
            $this->multipartPost('/api/v1/admin/categories/'.$category->id, [
                '_method' => $method,
            ])->assertUnprocessable()->assertJsonValidationErrors('_method');
            $this->app['auth']->forgetGuards();
        }

        $this->multipartPost('/api/v1/admin/categories/'.$category->id, [
            '_method' => 'PATCH', 'name_en' => 'Should not persist', 'unrelated' => 'rejected',
        ])->assertUnprocessable()->assertJsonValidationErrors('unrelated');

        $this->assertSame('English method-spoof-check', $category->fresh()->name_en);
        $this->assertArrayNotHasKey('_method', $category->fresh()->getAttributes());
    }

    public function test_public_cover_alt_uses_localized_category_name_and_lists_do_not_issue_per_row_queries(): void
    {
        $category = $this->category('public-cover');
        $asset = app(MediaService::class)->uploadImage($this->image('png'));
        app(MediaService::class)->attach($asset, $category, MediaRole::CATEGORY_COVER);

        $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/categories/'.$category->slug)
            ->assertOk()->assertJsonPath('data.coverImage.id', $asset->public_id)
            ->assertJsonPath('data.coverImage.alt', $category->name_ar);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/categories/'.$category->slug)
            ->assertOk()->assertJsonPath('data.coverImage.alt', $category->name_en);

        $attachmentQueries = 0;
        $assetQueries = 0;
        DB::listen(function ($query) use (&$attachmentQueries, &$assetQueries): void {
            $sql = strtolower($query->sql);
            $attachmentQueries += str_contains($sql, 'media_attachments') ? 1 : 0;
            $assetQueries += str_contains($sql, 'media_assets') ? 1 : 0;
        });
        $this->getJson('/api/v1/categories')->assertOk();
        $this->assertLessThanOrEqual(2, $attachmentQueries);
        $this->assertLessThanOrEqual(2, $assetQueries);

        $this->app['auth']->forgetGuards();
        $attachmentQueries = 0;
        $assetQueries = 0;
        $this->withToken($this->adminToken)->getJson('/api/v1/admin/categories')->assertOk();
        $this->assertLessThanOrEqual(4, $attachmentQueries);
        $this->assertLessThanOrEqual(4, $assetQueries);
    }

    private function multipartPost(string $uri, array $data): TestResponse
    {
        return $this->withToken($this->adminToken)->post(
            $uri,
            $data,
            ['Content-Type' => 'multipart/form-data; boundary=TestBoundary'],
        );
    }

    private function payload(string $slug): array
    {
        return [
            'parent_id' => null,
            'slug' => $slug,
            'name_ar' => 'Arabic '.$slug,
            'name_en' => 'English '.$slug,
            'description_ar' => null,
            'description_en' => null,
            'status' => 'active',
            'sort_order' => 0,
        ];
    }

    private function category(string $slug): Category
    {
        return Category::query()->create($this->payload($slug));
    }

    private function image(string $format): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $format.'.'.($format === 'jpeg' ? 'jpg' : $format),
            $this->imageContents($format),
        );
    }

    private function oversizedValidJpeg(): UploadedFile
    {
        $contents = $this->imageContents('jpeg');
        $limit = (int) config('media.images.max_image_size_bytes');

        return UploadedFile::fake()->createWithContent(
            'large.jpg',
            $contents.str_repeat("\0", $limit + 1 - strlen($contents)),
        );
    }

    private function imageContents(string $format): string
    {
        return base64_decode(match ($format) {
            'jpeg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAADAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDzuiiivGPpT//Z',
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=',
            'webp' => 'UklGRjgAAABXRUJQVlA4ICwAAACwAQCdASoCAAMAAUAmJaACdLoABDAAAP73WS/8Buvi2mX/zNH6TfSbuYAAAA==',
        }, true);
    }

    private function mime(string $format): string
    {
        return match ($format) {
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };
    }
}
