<?php

namespace Tests\Feature\Database;

use App\Enums\MediaRole;
use App\Exceptions\MediaOperationException;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class MediaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_asset_casts_and_creator_relationship_follow_project_conventions(): void
    {
        $creator = User::factory()->create();
        $asset = $this->asset([
            'created_by' => $creator->id,
            'size_bytes' => '1234',
            'width' => '2',
            'height' => '3',
            'duration_seconds' => '1.250',
            'metadata' => ['source' => 'test'],
        ]);

        $this->assertIsInt($asset->size_bytes);
        $this->assertSame(1234, $asset->size_bytes);
        $this->assertSame(2, $asset->width);
        $this->assertSame(3, $asset->height);
        $this->assertSame('1.250', $asset->duration_seconds);
        $this->assertSame(['source' => 'test'], $asset->metadata);
        $this->assertTrue($asset->createdBy->is($creator));
        $this->assertInstanceOf(Carbon::class, $asset->created_at);

        $creator->delete();
        $this->assertNull($asset->refresh()->created_by);
        $this->assertNull($asset->createdBy);
    }

    public function test_attachments_resolve_relationships_and_store_stable_morph_aliases(): void
    {
        $asset = $this->asset();
        $category = $this->category('morph-category');
        $otherCategory = $this->category('morph-other-category');
        $product = $this->product('morph-product');
        $service = app(MediaService::class);

        $categoryAttachment = $service->attach($asset, $category, MediaRole::CATEGORY_COVER, [
            'alt_ar' => 'ØºØ·Ø§Ø¡', 'sort_order' => 4, 'is_primary' => true,
        ]);
        $otherAttachment = $service->attach($asset, $otherCategory, MediaRole::CATEGORY_COVER);
        try {
            $service->attach($asset, $product, MediaRole::CATEGORY_COVER);
            $this->fail('A product cannot use the category cover role.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The media role is not supported for this owner type.', $exception->getMessage());
        }
        $productAttachment = $service->attach($asset, $product, MediaRole::PRODUCT_IMAGE);

        $this->assertSame('category', $categoryAttachment->mediable_type);
        $this->assertSame('product', $productAttachment->mediable_type);
        $this->assertTrue($categoryAttachment->mediable->is($category));
        $this->assertTrue($categoryAttachment->mediaAsset->is($asset));
        $this->assertCount(3, $asset->attachments);
        $this->assertTrue($category->mediaAttachments->first()->is($categoryAttachment));
        $this->assertTrue($product->mediaAttachments->first()->is($productAttachment));
        $this->assertSame(4, $categoryAttachment->sort_order);
        $this->assertTrue($categoryAttachment->is_primary);
        $this->assertDatabaseHas('media_attachments', [
            'id' => $categoryAttachment->id,
            'mediable_type' => 'category',
            'mediable_id' => $category->id,
        ]);
        $this->assertDatabaseHas('media_attachments', [
            'id' => $productAttachment->id,
            'mediable_type' => 'product',
            'mediable_id' => $product->id,
        ]);

        $secondAsset = $this->asset(['path' => 'assets/second.png']);
        $secondAttachment = $service->attach($secondAsset, $category, MediaRole::CATEGORY_COVER);
        $this->assertSame(2, $category->mediaAttachments()->where('role', MediaRole::CATEGORY_COVER)->count());
        $this->assertTrue($asset->is($categoryAttachment->mediaAsset));
        $this->assertSame(MediaRole::CATEGORY_COVER, $otherAttachment->role);
        $this->assertSame(MediaRole::PRODUCT_IMAGE, $productAttachment->role);
        $this->assertTrue($secondAttachment->mediaAsset->is($secondAsset));
    }

    public function test_asset_remains_until_its_last_attachment_is_detached(): void
    {
        $this->fakeMediaDisk();
        $service = app(MediaService::class);
        $asset = $service->uploadImage($this->image('reused.jpg', 'jpeg'));
        $first = $this->category('first-attachment');
        $second = $this->category('second-attachment');
        $firstAttachment = $service->attach($asset, $first, MediaRole::CATEGORY_COVER);
        $secondAttachment = $service->attach($asset, $second, MediaRole::CATEGORY_COVER);

        $this->assertFalse($service->isOrphaned($asset));
        $this->assertFalse($service->deleteOrphanedAsset($asset));
        Storage::disk('media-test')->assertExists($asset->path);

        $this->assertTrue($service->detach($firstAttachment));
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
        Storage::disk('media-test')->assertExists($asset->path);

        $this->assertTrue($service->detach($secondAttachment));
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('media-test')->assertMissing($asset->path);
    }

    public function test_orphaned_asset_deletion_removes_storage_file_and_is_idempotent(): void
    {
        $this->fakeMediaDisk();
        $service = app(MediaService::class);
        $asset = $service->uploadImage($this->image('orphan.png', 'png'));

        $this->assertTrue($service->isOrphaned($asset));
        Storage::disk('media-test')->assertExists($asset->path);
        $this->assertTrue($service->deleteOrphanedAsset($asset));
        Storage::disk('media-test')->assertMissing($asset->path);
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        $this->assertTrue($service->deleteOrphanedAsset((int) $asset->id));
    }

    public function test_permanently_deleting_an_asset_cascades_to_attachments(): void
    {
        $asset = $this->asset();
        $category = $this->category('cascade-category');
        $attachment = app(MediaService::class)->attach($asset, $category, MediaRole::CATEGORY_COVER);

        $asset->forceDelete();

        $this->assertDatabaseMissing('media_attachments', ['id' => $attachment->id]);
    }

    public function test_storage_failure_does_not_create_an_asset_record(): void
    {
        config(['media.disk' => 'media-test']);
        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->twice()->with('media-test')->andReturn($disk);

        try {
            app(MediaService::class)->uploadImage($this->image('valid.jpg', 'jpeg'));
            $this->fail('Expected a media storage failure.');
        } catch (MediaOperationException $exception) {
            $this->assertSame('The media file could not be stored.', $exception->getMessage());
        }

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_database_failure_after_storage_attempts_file_cleanup(): void
    {
        $this->fakeMediaDisk();
        $invalidCreator = new User;
        $invalidCreator->setAttribute('id', 999999);

        try {
            app(MediaService::class)->uploadImage($this->image('database-failure.jpg', 'jpeg'), $invalidCreator);
            $this->fail('Expected a database failure.');
        } catch (MediaOperationException $exception) {
            $this->assertSame('The media asset could not be recorded.', $exception->getMessage());
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media-test')->allFiles());
    }

    public function test_invalid_owner_role_is_rejected_before_upload_storage(): void
    {
        $this->fakeMediaDisk();
        $product = $this->product('invalid-owner-role');

        try {
            app(MediaService::class)->uploadAndAttach(
                $this->image('invalid-role.jpg', 'jpeg'),
                $product,
                MediaRole::CATEGORY_COVER,
            );
            $this->fail('Expected an incompatible owner and role to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The media role is not supported for this owner type.', $exception->getMessage());
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media-test')->allFiles());
    }

    public function test_jpeg_png_and_webp_are_validated_from_content_and_metadata_is_recorded(): void
    {
        $this->fakeMediaDisk();
        $jpeg = $this->bytes('jpeg');
        $cases = [
            ['jpeg.jpg', 'jpeg', 'image/jpeg', 'jpg'],
            ['png.png', 'png', 'image/png', 'png'],
            ['webp.webp', 'webp', 'image/webp', 'webp'],
        ];

        foreach ($cases as [$name, $fixture, $mimeType, $extension]) {
            $file = $this->image($name, $fixture);
            if ($fixture === 'jpeg') {
                $file->mimeType('image/png');
            }
            $asset = app(MediaService::class)->uploadImage($file);
            $contents = $this->bytes($fixture);

            $this->assertSame('image', $asset->media_type);
            $this->assertSame($mimeType, $asset->mime_type);
            $this->assertSame($extension, $asset->extension);
            $this->assertSame(strlen($contents), $asset->size_bytes);
            $this->assertSame(2, $asset->width);
            $this->assertSame(3, $asset->height);
            $this->assertSame(hash('sha256', $contents), $asset->checksum);
            Storage::disk('media-test')->assertExists($asset->path);
        }
    }

    public function test_video_upload_records_video_metadata_without_image_dimensions(): void
    {
        $this->fakeMediaDisk();
        $videoBytes = base64_decode('AAAAGGZ0eXBpc29tAAAAAGlzb20=', true);
        $this->assertIsString($videoBytes);

        $asset = app(MediaService::class)->uploadVideo(
            UploadedFile::fake()->createWithContent('client-name.bin', $videoBytes),
        );

        $this->assertSame('video', $asset->media_type);
        $this->assertSame('video/mp4', $asset->mime_type);
        $this->assertSame('mp4', $asset->extension);
        $this->assertSame(strlen($videoBytes), $asset->size_bytes);
        $this->assertNull($asset->width);
        $this->assertNull($asset->height);
        $this->assertNull($asset->duration_seconds);
        $this->assertSame(hash('sha256', $videoBytes), $asset->checksum);
        $this->assertStringEndsWith('.mp4', $asset->path);
        Storage::disk('media-test')->assertExists($asset->path);
    }

    public function test_configured_image_size_limit_is_bytes_and_accepts_the_exact_boundary(): void
    {
        $this->fakeMediaDisk();
        $limit = (int) config('media.images.max_image_size_bytes');
        $contents = $this->bytes('jpeg');
        $contents .= str_repeat("\0", $limit - strlen($contents));
        $asset = app(MediaService::class)->uploadImage(
            UploadedFile::fake()->createWithContent('boundary.jpg', $contents),
        );

        $this->assertSame($limit, $asset->size_bytes);
        $this->assertSame($limit, Storage::disk('media-test')->size($asset->path));

        $tooLarge = $contents."\0";
        try {
            app(MediaService::class)->uploadImage(
                UploadedFile::fake()->createWithContent('over-limit.jpg', $tooLarge),
            );
            $this->fail('Expected an image one byte over the configured limit to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_unsupported_image_formats_and_non_images_are_rejected(): void
    {
        $this->fakeMediaDisk();
        $unsupported = [
            ['vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            ['animation.gif', base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=', true)],
            ['clip.mp4', base64_decode('AAAAGGZ0eXBpc29tAAAAAGlzb20=', true)],
            ['bitmap.bmp', 'BM'.str_repeat("\0", 32)],
            ['text.jpg', 'plain text, not an image'],
        ];

        foreach ($unsupported as [$name, $contents]) {
            try {
                app(MediaService::class)->uploadImage(UploadedFile::fake()->createWithContent($name, $contents));
                $this->fail("Expected {$name} to be rejected.");
            } catch (ValidationException) {
                $this->assertDatabaseCount('media_assets', 0);
            }
        }
    }

    public function test_images_larger_than_five_megabytes_are_rejected(): void
    {
        $file = UploadedFile::fake()->create('large.jpg', 5121, 'image/jpeg');

        $this->expectException(ValidationException::class);
        app(MediaService::class)->uploadImage($file);
    }

    public function test_random_storage_path_ignores_unsafe_original_filename(): void
    {
        $this->fakeMediaDisk();
        $file = $this->image('../../unsafe original name.jpg', 'jpeg');
        $asset = app(MediaService::class)->uploadImage($file);

        $this->assertStringNotContainsString('unsafe', $asset->path);
        $this->assertStringNotContainsString('..', $asset->path);
        $this->assertMatchesRegularExpression(
            '~^media/images/\d{4}/\d{2}/[0-9A-HJKMNP-TV-Z]{26}\.jpg$~',
            $asset->path,
        );
    }

    private function fakeMediaDisk(): void
    {
        config(['media.disk' => 'media-test']);
        Storage::fake('media-test');
    }

    private function image(string $name, string $fixture): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->bytes($fixture));
    }

    private function bytes(string $fixture): string
    {
        $images = [
            'jpeg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAADAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDzuiiivGPpT//Z',
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=',
            'webp' => 'UklGRjgAAABXRUJQVlA4ICwAAACwAQCdASoCAAMAAUAmJaACdLoABDAAAP73WS/8Buvi2mX/zNH6TfSbuYAAAA==',
        ];

        $contents = base64_decode($images[$fixture], true);
        $this->assertIsString($contents);

        return $contents;
    }

    private function asset(array $attributes = []): MediaAsset
    {
        return MediaAsset::query()->create(array_merge([
            'disk' => 'media-test',
            'path' => 'assets/'.uniqid().'.jpg',
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 10,
        ], $attributes));
    }

    private function category(string $slug): Category
    {
        return Category::query()->create([
            'slug' => $slug,
            'name_ar' => 'ØªØµÙ†ÙŠÙ',
            'name_en' => $slug,
        ]);
    }

    private function product(string $slug): Product
    {
        return Product::query()->create([
            'slug' => $slug,
            'name_ar' => 'Ù…Ù†ØªØ¬',
            'name_en' => $slug,
        ]);
    }
}
