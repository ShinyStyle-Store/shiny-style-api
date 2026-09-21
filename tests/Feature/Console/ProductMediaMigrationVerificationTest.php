<?php

namespace Tests\Feature\Console;

use App\Enums\MediaRole;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\SellableItem;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\RecreatesLegacyProductMediaTable;
use Tests\TestCase;

class ProductMediaMigrationVerificationTest extends TestCase
{
    use RecreatesLegacyProductMediaTable;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recreateLegacyProductMediaTable();
        Storage::fake('verification-media');
    }

    public function test_preflight_fails_when_a_non_deleted_row_has_no_mapping(): void
    {
        $this->legacyProductMedia();

        [$exit, $output] = $this->runVerification();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mapping is missing', $output);
    }

    public function test_preflight_fails_for_a_failed_ledger_row(): void
    {
        $legacy = $this->legacyProductMedia();
        $this->mapLegacy($legacy, status: 'failed');

        [$exit, $output] = $this->runVerification();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mapping is not successfully migrated', $output);
    }

    public function test_preflight_fails_when_the_mapped_asset_is_soft_deleted(): void
    {
        [$legacy, $asset] = $this->mappedLegacy();
        $asset->delete();

        [$exit, $output] = $this->runVerification();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mapped asset is missing or deleted', $output);
    }

    public function test_preflight_fails_when_the_mapped_attachment_is_missing(): void
    {
        [$legacy, , $attachment] = $this->mappedLegacy();
        $attachment->delete();

        [$exit, $output] = $this->runVerification();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mapped attachment reference is missing', $output);
    }

    public function test_preflight_fails_for_an_incorrect_attachment_owner(): void
    {
        [$wrongOwnerLegacy, , , $otherProduct] = $this->mappedLegacy(variant: true);
        $wrongOwnerAsset = $this->asset();
        $wrongOwnerAttachment = app(MediaService::class)->attach($wrongOwnerAsset, $otherProduct, MediaRole::PRODUCT_IMAGE);
        $this->setMappingAttachment($wrongOwnerLegacy, $wrongOwnerAttachment);

        [$ownerExit, $ownerOutput] = $this->runVerification();
        $this->assertSame(1, $ownerExit);
        $this->assertStringContainsString('mapped attachment owner is incorrect', $ownerOutput);
    }

    public function test_preflight_fails_for_an_incorrect_attachment_role(): void
    {
        [$wrongRoleLegacy, , $wrongRoleAttachment] = $this->mappedLegacy();
        DB::table('media_attachments')->where('id', $wrongRoleAttachment->getKey())
            ->update(['role' => MediaRole::PRODUCT_VIDEO]);
        $this->setMappingAttachment($wrongRoleLegacy, $wrongRoleAttachment);

        [$roleExit, $roleOutput] = $this->runVerification();
        $this->assertSame(1, $roleExit);
        $this->assertStringContainsString('mapped attachment role is incorrect', $roleOutput);
    }

    public function test_preflight_passes_for_correct_product_and_sellable_item_mappings(): void
    {
        $this->mappedLegacy();
        $this->mappedLegacy(variant: true);

        [$exit, $output] = $this->runVerification();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('verified', $output);
        $this->assertStringContainsString('2', $output);
    }

    public function test_drop_migration_refuses_to_run_when_preflight_finds_incomplete_mapping(): void
    {
        $this->legacyProductMedia();
        $migration = require base_path('database/migrations/2026_09_21_000003_drop_product_media_table.php');

        try {
            $migration->up();
            $this->fail('The guarded migration dropped product_media with an unmapped row.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The product_media table cannot be dropped until media:verify-product-media-migration succeeds.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasTable('product_media'));
    }

    public function test_drop_migration_runs_after_successful_preflight(): void
    {
        $this->mappedLegacy();
        $migration = require base_path('database/migrations/2026_09_21_000003_drop_product_media_table.php');

        $migration->up();

        $this->assertFalse(Schema::hasTable('product_media'));
        $this->assertSame(1, DB::table('legacy_product_media_migrations')->count());
    }

    private function runVerification(): array
    {
        $exit = Artisan::call('media:verify-product-media-migration');

        return [$exit, Artisan::output()];
    }

    private function mappedLegacy(bool $variant = false): array
    {
        $legacy = $this->legacyProductMedia($variant);
        $owner = $variant ? SellableItem::query()->findOrFail($legacy->sellable_item_id) : Product::query()->findOrFail($legacy->product_id);
        $asset = $this->asset();
        $role = $variant ? MediaRole::VARIANT_IMAGE : MediaRole::PRODUCT_IMAGE;
        $attachment = app(MediaService::class)->attach($asset, $owner, $role);
        $otherProduct = $this->product();
        $this->saveMapping($legacy, $asset, $attachment);

        return [$legacy, $asset, $attachment, $otherProduct];
    }

    private function legacyProductMedia(bool $variant = false): ProductMedia
    {
        $product = $this->product();
        $sellableItem = $variant ? SellableItem::query()->create([
            'product_id' => $product->getKey(),
            'sku' => 'verification-'.uniqid(),
            'price' => 10,
            'stock_quantity' => 1,
            'status' => 'active',
        ]) : null;

        return ProductMedia::query()->create([
            'product_id' => $product->getKey(),
            'sellable_item_id' => $sellableItem?->getKey(),
            'provider' => 'cloudinary',
            'type' => 'image',
            'public_id' => 'verification-'.uniqid(),
            'secure_url' => 'https://res.cloudinary.com/demo/verification.png',
            'sort_order' => 0,
            'is_primary' => false,
        ]);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'slug' => 'verification-'.uniqid(),
            'name_ar' => 'Verification',
            'name_en' => 'Verification',
        ]);
    }

    private function asset(): MediaAsset
    {
        $path = 'verification/'.uniqid().'.jpg';
        Storage::disk('verification-media')->put($path, 'valid-migrated-file');

        return MediaAsset::query()->create([
            'disk' => 'verification-media',
            'path' => $path,
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 10,
            'width' => 1,
            'height' => 1,
            'checksum' => hash('sha256', uniqid()),
        ]);
    }

    private function saveMapping(ProductMedia $legacy, MediaAsset $asset, MediaAttachment $attachment, string $status = 'migrated'): void
    {
        DB::table('legacy_product_media_migrations')->insert([
            'legacy_product_media_id' => $legacy->getKey(),
            'legacy_provider' => 'cloudinary',
            'legacy_public_id' => $legacy->public_id,
            'legacy_secure_url' => $legacy->secure_url,
            'legacy_source_hash' => hash('sha256', $legacy->secure_url),
            'status' => $status,
            'media_asset_id' => $asset->getKey(),
            'media_attachment_id' => $attachment->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mapLegacy(ProductMedia $legacy, string $status): void
    {
        $asset = $this->asset();
        $owner = Product::query()->findOrFail($legacy->product_id);
        $attachment = app(MediaService::class)->attach($asset, $owner, MediaRole::PRODUCT_IMAGE);
        $this->saveMapping($legacy, $asset, $attachment, $status);
    }

    private function setMappingAttachment(ProductMedia $legacy, MediaAttachment $attachment): void
    {
        DB::table('legacy_product_media_migrations')
            ->where('legacy_product_media_id', $legacy->getKey())
            ->update([
                'media_asset_id' => $attachment->media_asset_id,
                'media_attachment_id' => $attachment->getKey(),
            ]);
    }
}
