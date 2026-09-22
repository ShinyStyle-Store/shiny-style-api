<?php

namespace Tests\Feature\Console;

use App\Enums\MediaRole;
use App\Exceptions\MediaOperationException;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\SellableItem;
use App\Services\LegacyCloudinaryUrlPolicy;
use App\Services\MediaService;
use App\Services\ProductMediaMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Concerns\RecreatesLegacyProductMediaTable;
use Tests\TestCase;

class ProductMediaMigrationTest extends TestCase
{
    use RecreatesLegacyProductMediaTable;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recreateLegacyProductMediaTable();

        config(['media.disk' => 'migration-test']);
        config(['media.legacy_migration.cloudinary_hosts' => ['res.cloudinary.com']]);
        Storage::fake('migration-test');
        $this->usePublicDnsResolver();
        Http::preventStrayRequests();
    }

    public function test_storage_ready_uses_a_cloudinary_compatible_png_probe_and_cleans_it(): void
    {
        $service = $this->app->make(ProductMediaMigrationService::class);

        $this->assertNull($service->assertStorageReady());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_storage_ready_reports_upload_failure(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->withArgs(function (string $path, string $contents): bool {
            return str_starts_with($path, 'images/') && $contents === $this->pngBytes();
        })->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('migration-test')->andReturn($disk);

        $this->assertSame(
            'configured media disk is not writable',
            $this->app->make(ProductMediaMigrationService::class)->assertStorageReady(),
        );
    }

    public function test_storage_ready_cleans_probe_when_existence_check_fails(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturnTrue();
        $disk->shouldReceive('exists')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->once()->with('migration-test')->andReturn($disk);

        $this->assertSame(
            'configured media disk is not writable',
            $this->app->make(ProductMediaMigrationService::class)->assertStorageReady(),
        );
    }

    public function test_storage_ready_cleans_probe_when_delete_fails(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andReturnTrue();
        $disk->shouldReceive('exists')->once()->andReturnTrue();
        $disk->shouldReceive('delete')->twice()->andThrow(new RuntimeException('cleanup failure'));
        Storage::shouldReceive('disk')->once()->with('migration-test')->andReturn($disk);

        $this->assertSame(
            'configured media disk is not writable',
            $this->app->make(ProductMediaMigrationService::class)->assertStorageReady(),
        );
    }

    public function test_product_image_migrates_with_provenance_and_reruns_idempotently(): void
    {
        $product = $this->product();
        $legacy = $this->legacy($product, null, [
            'type' => 'image',
            'secure_url' => 'https://res.cloudinary.com/demo/image/upload/photo.mp4?signature=do-not-log',
            'alt_text_ar' => 'Alt Arabic',
            'alt_text_en' => 'Alt English',
            'sort_order' => 7,
            'is_primary' => true,
        ]);
        $this->fakePng();

        [$exit, $output] = $this->runMigration();

        $this->assertSame(0, $exit);
        $mapping = $this->migrationMapping($legacy->getKey());
        $this->assertSame('migrated', $mapping->status);
        $this->assertSame('cloudinary', $mapping->legacy_provider);
        $this->assertSame($legacy->public_id, $mapping->legacy_public_id);
        $this->assertSame($legacy->secure_url, $mapping->legacy_secure_url);
        $this->assertSame(1, DB::table('media_assets')->count());
        $this->assertSame(1, DB::table('media_attachments')->count());

        $asset = MediaAsset::findOrFail($mapping->media_asset_id);
        $attachment = $asset->attachments()->findOrFail($mapping->media_attachment_id);
        $this->assertSame('image', $asset->media_type);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame(hash('sha256', $this->pngBytes()), $asset->checksum);
        $this->assertSame('product', $attachment->mediable_type);
        $this->assertSame($product->getKey(), $attachment->mediable_id);
        $this->assertSame(MediaRole::PRODUCT_IMAGE, $attachment->role);
        $this->assertSame('Alt Arabic', $attachment->alt_ar);
        $this->assertSame('Alt English', $attachment->alt_en);
        $this->assertSame(7, $attachment->sort_order);
        $this->assertTrue($attachment->is_primary);
        Storage::disk('migration-test')->assertExists($asset->path);
        $this->assertNotSame('', Storage::disk('migration-test')->url($asset->path));
        $this->assertStringNotContainsString('do-not-log', $output);

        [$rerunExit] = $this->runMigration();
        $this->assertSame(0, $rerunExit);
        $this->assertSame(1, DB::table('media_assets')->count());
        $this->assertSame(1, DB::table('media_attachments')->count());
        Http::assertSentCount(1);
    }

    public function test_variant_video_uses_sellable_item_role_and_actual_downloaded_kind(): void
    {
        $product = $this->product();
        $variant = $this->variant($product);
        $legacy = $this->legacy($product, $variant, [
            'type' => 'video',
            'secure_url' => 'https://res.cloudinary.com/demo/video/upload/video.jpg',
            'is_primary' => true,
        ]);
        $this->fakeVideo();

        [$exit] = $this->runMigration();

        $this->assertSame(0, $exit);
        $mapping = $this->migrationMapping($legacy->getKey());
        $asset = MediaAsset::findOrFail($mapping->media_asset_id);
        $attachment = $asset->attachments()->findOrFail($mapping->media_attachment_id);
        $this->assertSame('video', $asset->media_type);
        $this->assertSame('video/mp4', $asset->mime_type);
        $this->assertSame(hash('sha256', $this->videoBytes()), $asset->checksum);
        $this->assertSame('sellable_item', $attachment->mediable_type);
        $this->assertSame($variant->getKey(), $attachment->mediable_id);
        $this->assertSame(MediaRole::VARIANT_VIDEO, $attachment->role);
    }

    public function test_storage_file_is_cleaned_if_attachment_creation_fails(): void
    {
        $this->legacy($this->product());
        $this->fakePng();
        $media = Mockery::mock(MediaService::class)->makePartial();
        $media->shouldReceive('attach')
            ->once()
            ->andThrow(new RuntimeException('simulated attachment failure'));
        $this->app->instance(MediaService::class, $media);

        [$exit] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame(0, DB::table('media_attachments')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_dry_run_has_no_database_or_storage_writes_and_reports_primary_conflicts(): void
    {
        $product = $this->product();
        $first = $this->legacy($product, null, ['is_primary' => true]);
        $second = $this->legacy($product, null, ['is_primary' => true]);
        $this->fakePng();
        Log::shouldReceive('warning')->never();

        [$exit, $output] = $this->runMigration(['--dry-run' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('duplicate legacy primary', $output);
        $this->assertStringContainsString((string) $first->getKey(), $output);
        $this->assertStringContainsString((string) $second->getKey(), $output);
        $this->assertSame(0, DB::table('legacy_product_media_migrations')->count());
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
        Http::assertNothingSent();
    }

    public function test_dry_run_reports_candidate_without_http_or_filesystem_writes(): void
    {
        $legacy = $this->legacy($this->product());

        [$exit, $output] = $this->runMigration(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('would migrate', $output);
        $this->assertSame(0, DB::table('legacy_product_media_migrations')->count());
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
        Http::assertNothingSent();
        $this->assertDatabaseHas('product_media', ['id' => $legacy->getKey()]);
    }

    public function test_repeated_source_url_reuses_asset_and_creates_separate_owner_attachments(): void
    {
        $firstProduct = $this->product();
        $secondProduct = $this->product();
        $url = 'https://res.cloudinary.com/demo/shared.png';
        $first = $this->legacy($firstProduct, null, ['secure_url' => $url]);
        $second = $this->legacy($secondProduct, null, ['secure_url' => $url]);
        $this->fakePng();

        [$exit] = $this->runMigration();

        $this->assertSame(0, $exit);
        $firstMap = $this->migrationMapping($first->getKey());
        $secondMap = $this->migrationMapping($second->getKey());
        $this->assertSame($firstMap->media_asset_id, $secondMap->media_asset_id);
        $this->assertNotSame($firstMap->media_attachment_id, $secondMap->media_attachment_id);
        $this->assertSame(1, DB::table('media_assets')->count());
        $this->assertSame(2, DB::table('media_attachments')->count());
        Http::assertSentCount(1);
    }

    public function test_failed_record_does_not_rollback_an_earlier_success_and_can_be_retried(): void
    {
        $product = $this->product();
        $good = $this->legacy($product);
        $bad = $this->legacy($this->product(), null, [
            'secure_url' => 'http://res.cloudinary.com/demo/not-https.png',
        ]);
        $pngBytes = $this->pngBytes();
        $pngResponse = fn (...$arguments) => Http::response(
            $pngBytes,
            200,
            ['Content-Length' => (string) strlen($pngBytes)],
        );
        Http::fake([
            'https://res.cloudinary.com/demo/image/upload/photo.png' => $pngResponse,
            'https://res.cloudinary.com/demo/retry.png' => $pngResponse,
        ]);

        [$exit] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertSame('migrated', DB::table('legacy_product_media_migrations')->where('legacy_product_media_id', $good->getKey())->value('status'));
        $this->assertSame('failed', DB::table('legacy_product_media_migrations')->where('legacy_product_media_id', $bad->getKey())->value('status'));
        $this->assertSame(1, DB::table('media_assets')->count());
        $this->assertSame(1, DB::table('media_attachments')->count());
        $badMapping = $this->migrationMapping($bad->getKey());
        $badMappingId = $badMapping->id;
        $goodMapping = $this->migrationMapping($good->getKey());
        $goodAttachmentId = $goodMapping->media_attachment_id;
        $this->assertNotNull($badMapping->failure_reason);

        $bad->secure_url = 'https://res.cloudinary.com/demo/retry.png';
        $bad->save();
        [$retryExit, $retryOutput] = $this->runMigration();
        Http::assertSent(fn ($request): bool => $request->url() === 'https://res.cloudinary.com/demo/retry.png');
        $retriedMapping = $this->migrationMapping($bad->getKey());
        $retryLedger = DB::table('legacy_product_media_migrations')
            ->where('legacy_product_media_id', $bad->getKey())
            ->first(['id', 'status', 'legacy_secure_url', 'legacy_source_hash', 'media_asset_id', 'media_attachment_id', 'failure_reason']);
        $this->assertSame(0, $retryExit, "Retry command output:\n{$retryOutput}\nRetry ledger row:\n".json_encode($retryLedger));
        $this->assertMatchesRegularExpression('/\|\s*migrated\s*\|\s*1\s*\|/', $retryOutput);
        $this->assertMatchesRegularExpression('/\|\s*already migrated\s*\|\s*1\s*\|/', $retryOutput);
        $this->assertMatchesRegularExpression('/\|\s*scanned\s*\|\s*2\s*\|/', $retryOutput);
        $this->assertMatchesRegularExpression('/\|\s*skipped\s*\|\s*0\s*\|/', $retryOutput);
        $this->assertMatchesRegularExpression('/\|\s*failed\s*\|\s*0\s*\|/', $retryOutput);
        $this->assertSame($badMappingId, $retriedMapping->id);
        $this->assertSame('migrated', $retriedMapping->status);
        $this->assertSame($bad->secure_url, $retriedMapping->legacy_secure_url);
        $this->assertSame(hash('sha256', 'cloudinary'."\0".$bad->secure_url), $retriedMapping->legacy_source_hash);
        $this->assertNull($retriedMapping->failure_reason);
        $this->assertSame($goodAttachmentId, $this->migrationMapping($good->getKey())->media_attachment_id);
        $this->assertSame(2, DB::table('media_assets')->count());
        $this->assertSame(2, DB::table('media_attachments')->count());

        $migratedUrl = $retriedMapping->legacy_secure_url;
        $bad->secure_url = 'https://res.cloudinary.com/demo/changed-after-migration.png';
        $bad->save();
        [$verifiedExit] = $this->runMigration();

        $this->assertSame(0, $verifiedExit);
        $verifiedMapping = $this->migrationMapping($bad->getKey());
        $this->assertSame($migratedUrl, $verifiedMapping->legacy_secure_url);
        $this->assertSame($retriedMapping->media_asset_id, $verifiedMapping->media_asset_id);
        $this->assertSame($retriedMapping->media_attachment_id, $verifiedMapping->media_attachment_id);
        $this->assertSame(2, DB::table('media_assets')->count());
        $this->assertSame(2, DB::table('media_attachments')->count());
    }

    public function test_soft_deleted_legacy_rows_and_soft_deleted_owners_are_skipped(): void
    {
        $deletedLegacy = $this->legacy($this->product());
        $deletedLegacy->delete();
        $deletedOwner = $this->product();
        $ownerLegacy = $this->legacy($deletedOwner);
        $deletedOwner->delete();

        [$exit, $output] = $this->runMigration();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('skipped', $output);
        $this->assertSame(0, DB::table('legacy_product_media_migrations')->count());
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertDatabaseHas('product_media', ['id' => $deletedLegacy->getKey()]);
        $this->assertDatabaseHas('product_media', ['id' => $ownerLegacy->getKey()]);
    }

    public function test_missing_product_and_sellable_item_owners_are_reported_as_failures(): void
    {
        $product = $this->product();
        $missingProduct = new ProductMedia([
            'product_id' => PHP_INT_MAX,
            'provider' => 'cloudinary',
            'type' => 'image',
            'public_id' => 'missing-product',
            'secure_url' => 'https://res.cloudinary.com/demo/missing-product.png',
        ]);
        $missingProduct->setAttribute('id', 900001);
        $missingVariant = new ProductMedia([
            'product_id' => $product->getKey(),
            'sellable_item_id' => PHP_INT_MAX,
            'provider' => 'cloudinary',
            'type' => 'video',
            'public_id' => 'missing-variant',
            'secure_url' => 'https://res.cloudinary.com/demo/missing-variant.mp4',
        ]);
        $missingVariant->setAttribute('id', 900002);
        $migration = app(ProductMediaMigrationService::class);

        $productResult = $migration->inspect($missingProduct);
        $variantResult = $migration->inspect($missingVariant);

        $this->assertSame('failed', $productResult['status']);
        $this->assertSame('Product owner is missing', $productResult['reason']);
        $this->assertSame('failed', $variantResult['status']);
        $this->assertSame('SellableItem owner is missing', $variantResult['reason']);
        $this->assertSame(0, DB::table('legacy_product_media_migrations')->count());
    }

    public function test_existing_unified_primary_blocks_migration_before_any_writes(): void
    {
        $product = $this->product();
        $asset = $this->imageAsset();
        app(MediaService::class)->attach($asset, $product, MediaRole::PRODUCT_IMAGE, ['is_primary' => true]);
        $legacy = $this->legacy($product, null, ['is_primary' => true]);

        [$exit, $output] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('existing unified primary', $output);
        $this->assertSame(0, DB::table('legacy_product_media_migrations')->count());
        $this->assertSame(1, DB::table('media_assets')->count());
        $this->assertSame(1, DB::table('media_attachments')->count());
        $this->assertDatabaseHas('product_media', ['id' => $legacy->getKey()]);
    }

    public function test_fake_image_bytes_are_rejected_without_creating_assets(): void
    {
        $legacy = $this->legacy($this->product(), null, [
            'secure_url' => 'https://res.cloudinary.com/demo/fake.png?credential=source-secret',
        ]);
        Http::fake(['*' => Http::response('sensitive-response-body', 200)]);
        [$fakeExit, $output] = $this->runMigration();

        $this->assertSame(1, $fakeExit);
        $this->assertStringContainsString('unsupported downloaded MIME type', $output);
        $this->assertStringNotContainsString('source-secret', $output);
        $this->assertStringNotContainsString('sensitive-response-body', $output);
        $this->assertDatabaseHas('legacy_product_media_migrations', [
            'legacy_product_media_id' => $legacy->getKey(),
            'status' => 'failed',
            'failure_reason' => 'unsupported downloaded MIME type',
        ]);
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_legacy_type_must_match_inspected_downloaded_mime(): void
    {
        Log::spy();
        $legacy = $this->legacy($this->product(), null, [
            'type' => 'image',
            'secure_url' => 'https://res.cloudinary.com/demo/photo.png?credential=mime-secret',
        ]);
        $this->fakeVideo();

        [$exit, $output] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('downloaded MIME does not match legacy media type', $output);
        $this->assertStringNotContainsString('mime-secret', $output);
        $this->assertDatabaseHas('legacy_product_media_migrations', [
            'legacy_product_media_id' => $legacy->getKey(),
            'status' => 'failed',
            'failure_reason' => 'downloaded MIME does not match legacy media type',
        ]);
        Log::shouldHaveReceived('debug')->once()->with(
            'Legacy ProductMedia migration validation diagnostic.',
            Mockery::on(function (array $diagnostic): bool {
                $serialized = json_encode($diagnostic);

                return $diagnostic['exception_classes'] === [ValidationException::class]
                    && $diagnostic['validation_fields'] === ['file']
                    && $diagnostic['failed_rules'] === []
                    && $diagnostic['detected_mime'] === 'video/mp4'
                    && $diagnostic['expected_media_kind'] === 'image'
                    && $diagnostic['file_size_bytes'] === strlen($this->videoBytes())
                    && ! str_contains((string) $serialized, 'mime-secret')
                    && ! str_contains((string) $serialized, 'https://')
                    && ! str_contains((string) $serialized, sys_get_temp_dir());
            }),
        );
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame(0, DB::table('media_attachments')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_nested_media_validation_exception_is_classified_and_cleaned_up(): void
    {
        Log::spy();
        $legacy = $this->legacy($this->product(), null, [
            'secure_url' => 'https://res.cloudinary.com/demo/nested.png?credential=nested-secret',
        ]);
        $this->fakePng();
        MediaAsset::creating(static function (MediaAsset $asset): void {
            throw ValidationException::withMessages(['file' => 'The uploaded file is invalid.']);
        });

        [$exit, $output] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('temporary file could not be processed', $output);
        $this->assertStringNotContainsString('nested-secret', $output);
        $this->assertDatabaseHas('legacy_product_media_migrations', [
            'legacy_product_media_id' => $legacy->getKey(),
            'status' => 'failed',
            'failure_reason' => 'temporary file could not be processed',
        ]);
        Log::shouldHaveReceived('debug')->once()->with(
            'Legacy ProductMedia migration validation diagnostic.',
            Mockery::on(function (array $diagnostic): bool {
                return $diagnostic['exception_classes'] === [MediaOperationException::class, ValidationException::class]
                    && $diagnostic['validation_fields'] === ['file']
                    && $diagnostic['failed_rules'] === []
                    && $diagnostic['detected_mime'] === 'image/png'
                    && $diagnostic['expected_media_kind'] === 'image'
                    && $diagnostic['file_size_bytes'] === strlen($this->pngBytes());
            }),
        );
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame(0, DB::table('media_attachments')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_oversized_response_is_rejected_and_temp_file_is_removed(): void
    {
        $large = $this->legacy($this->product(), null, ['secure_url' => 'https://res.cloudinary.com/demo/large.png']);
        config(['media.images.max_image_size_bytes' => strlen($this->pngBytes()) - 1]);
        Http::fake(['*' => Http::response($this->pngBytes(), 200)]);
        [$largeExit, $output] = $this->runMigration();
        $this->assertSame(1, $largeExit);
        $this->assertStringContainsString('downloaded file exceeds configured limit', $output);
        $this->assertDatabaseHas('legacy_product_media_migrations', [
            'legacy_product_media_id' => $large->getKey(),
            'status' => 'failed',
            'failure_reason' => 'downloaded file exceeds configured limit',
        ]);
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_empty_download_is_recorded_with_a_safe_reason(): void
    {
        $legacy = $this->legacy($this->product(), null, [
            'secure_url' => 'https://res.cloudinary.com/demo/empty.png?credential=empty-secret',
        ]);
        Http::fake(['*' => Http::response('', 200)]);

        [$exit, $output] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('downloaded file is empty', $output);
        $this->assertStringNotContainsString('empty-secret', $output);
        $this->assertDatabaseHas('legacy_product_media_migrations', [
            'legacy_product_media_id' => $legacy->getKey(),
            'status' => 'failed',
            'failure_reason' => 'downloaded file is empty',
        ]);
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_undecodable_downloaded_image_has_a_safe_validation_reason(): void
    {
        $legacy = $this->legacy($this->product(), null, [
            'secure_url' => 'https://res.cloudinary.com/demo/broken.png?credential=image-secret',
        ]);
        $truncatedJpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($truncatedJpeg));
        $this->assertFalse(@getimagesizefromstring($truncatedJpeg));
        Http::fake(['*' => Http::response($truncatedJpeg, 200)]);

        [$exit, $output] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('downloaded image is not decodable', $output);
        $this->assertStringNotContainsString('image-secret', $output);
        $this->assertDatabaseHas('legacy_product_media_migrations', [
            'legacy_product_media_id' => $legacy->getKey(),
            'status' => 'failed',
            'failure_reason' => 'downloaded image is not decodable',
        ]);
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame(0, DB::table('media_attachments')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_redirects_are_rejected_without_following_them(): void
    {
        $redirect = $this->legacy($this->product(), null, ['secure_url' => 'https://res.cloudinary.com/demo/redirect.png']);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal?token=private-token'])]);
        [$redirectExit, $output] = $this->runMigration();
        $this->assertSame(1, $redirectExit);
        $this->assertStringContainsString('redirect rejected', $output);
        $this->assertStringNotContainsString('127.0.0.1', $output);
        $this->assertStringNotContainsString('private-token', $output);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('product_media', ['id' => $redirect->getKey()]);
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
    }

    public function test_private_dns_and_unsupported_hosts_are_rejected_without_requests(): void
    {
        $private = $this->legacy($this->product(), null, ['secure_url' => 'https://res.cloudinary.com/demo/private.png']);
        $this->app->instance(LegacyCloudinaryUrlPolicy::class, new LegacyCloudinaryUrlPolicy(
            fn (string $host): array => ['127.0.0.1'],
        ));

        [$privateExit, $privateOutput] = $this->runMigration();

        $this->assertSame(1, $privateExit);
        $this->assertStringContainsString('private or reserved', $privateOutput);
        Http::assertNothingSent();
        $this->usePublicDnsResolver();
        $unsupported = $this->legacy($this->product(), null, ['secure_url' => 'https://example.test/media.png']);

        [$unsupportedExit, $unsupportedOutput] = $this->runMigration();

        $this->assertSame(1, $unsupportedExit);
        $this->assertStringContainsString('host is not approved', $unsupportedOutput);
        $this->assertDatabaseHas('product_media', ['id' => $private->getKey()]);
        $this->assertDatabaseHas('product_media', ['id' => $unsupported->getKey()]);
        $this->assertSame(0, DB::table('media_assets')->count());
    }

    public function test_unsupported_provider_and_malformed_url_fail_without_logging_query_values(): void
    {
        $unsupportedProvider = $this->legacy($this->product(), null, ['provider' => 'other']);
        $malformedUrl = $this->legacy($this->product(), null, [
            'secure_url' => 'not-a-url?credential=secret-value',
        ]);

        [$exit, $output] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unsupported legacy provider', $output);
        $this->assertStringContainsString('Malformed legacy media URL', $output);
        $this->assertStringNotContainsString('secret-value', $output);
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
        $this->assertDatabaseHas('product_media', ['id' => $unsupportedProvider->getKey()]);
        $this->assertDatabaseHas('product_media', ['id' => $malformedUrl->getKey()]);
    }

    public function test_http_errors_and_timeouts_are_reported_without_response_or_url_secrets(): void
    {
        $httpFailure = $this->legacy($this->product(), null, [
            'secure_url' => 'https://res.cloudinary.com/demo/server-error.png?credential=url-secret',
        ]);
        $timeout = $this->legacy($this->product(), null, [
            'secure_url' => 'https://res.cloudinary.com/demo/timeout.png?credential=timeout-secret',
        ]);
        Http::fake([
            '*server-error.png*' => Http::response('private-response-body', 503),
            '*timeout.png*' => Http::failedConnection('request failed for timeout-secret'),
        ]);

        [$exit, $output] = $this->runMigration();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('unsuccessful response', $output);
        $this->assertStringContainsString('timed out or failed', $output);
        $this->assertStringNotContainsString('private-response-body', $output);
        $this->assertStringNotContainsString('url-secret', $output);
        $this->assertStringNotContainsString('timeout-secret', $output);
        $this->assertSame(0, DB::table('media_assets')->count());
        $this->assertSame([], Storage::disk('migration-test')->allFiles());
        $this->assertDatabaseHas('legacy_product_media_migrations', [
            'legacy_product_media_id' => $httpFailure->getKey(),
            'status' => 'failed',
            'failure_reason' => 'unsuccessful response',
        ]);
        $this->assertDatabaseHas('legacy_product_media_migrations', [
            'legacy_product_media_id' => $timeout->getKey(),
            'status' => 'failed',
            'failure_reason' => 'timed out or failed',
        ]);
    }

    private function runMigration(array $options = []): array
    {
        $exitCode = Artisan::call('media:migrate-product-media', $options);

        return [$exitCode, Artisan::output()];
    }

    private function migrationMapping(int $legacyId): object
    {
        $mapping = DB::table('legacy_product_media_migrations')
            ->where('legacy_product_media_id', $legacyId)
            ->first();
        $this->assertNotNull($mapping);

        return $mapping;
    }

    private function product(): Product
    {
        return Product::query()->create([
            'slug' => 'migration-product-'.uniqid(),
            'name_ar' => 'Migration Product',
            'name_en' => 'Migration Product',
        ]);
    }

    private function variant(Product $product): SellableItem
    {
        return SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => 'migration-sku-'.uniqid(),
            'price' => 10,
            'stock_quantity' => 1,
            'status' => 'active',
        ]);
    }

    private function legacy(Product $product, ?SellableItem $variant = null, array $attributes = []): ProductMedia
    {
        return ProductMedia::query()->create(array_merge([
            'product_id' => $product->getKey(),
            'sellable_item_id' => $variant?->getKey(),
            'provider' => 'cloudinary',
            'type' => 'image',
            'public_id' => 'legacy-'.uniqid(),
            'secure_url' => 'https://res.cloudinary.com/demo/image/upload/photo.png',
            'sort_order' => 0,
            'is_primary' => false,
        ], $attributes));
    }

    private function fakePng(): void
    {
        $bytes = $this->pngBytes();
        Http::fake(['*' => Http::response($bytes, 200, ['Content-Length' => (string) strlen($bytes)])]);
    }

    private function fakeVideo(): void
    {
        $bytes = $this->videoBytes();
        Http::fake(['*' => Http::response($bytes, 200, ['Content-Length' => (string) strlen($bytes)])]);
    }

    private function usePublicDnsResolver(): void
    {
        $this->app->instance(LegacyCloudinaryUrlPolicy::class, new LegacyCloudinaryUrlPolicy(
            fn (string $host): array => ['93.184.216.34'],
        ));
    }

    private function imageAsset(): MediaAsset
    {
        Storage::disk('migration-test')->put('unified/primary.png', $this->pngBytes());

        return MediaAsset::query()->create([
            'disk' => 'migration-test',
            'path' => 'unified/primary.png',
            'media_type' => 'image',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => strlen($this->pngBytes()),
            'width' => 2,
            'height' => 3,
            'checksum' => hash('sha256', $this->pngBytes()),
        ]);
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAFElEQVR4nGOs0OBiYGBgYgADKAUADWAAsJHFWX0AAAAASUVORK5CYII=', true);
    }

    private function videoBytes(): string
    {
        return base64_decode('AAAAGGZ0eXBpc29tAAAAAGlzb20=', true);
    }
}
