<?php

namespace Tests\Feature\Database;

use App\Models\Banner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BannerMediaIntegrityMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->markTestSkipped('Banner media integrity migrations support PostgreSQL and SQLite only.');
        }

        $this->migration = require database_path('migrations/2026_09_22_000002_add_banner_media_integrity.php');
    }

    public function test_existing_schema_upgrade_adds_banner_role_without_losing_legacy_roles(): void
    {
        $this->migration->down();
        $this->assertStringNotContainsString('banner_image', $this->primaryIndexDefinition());

        $this->migration->up();
        $definition = $this->primaryIndexDefinition();

        $this->assertStringContainsString('banner_image', $definition);
        $this->assertStringContainsString('product_image', $definition);
        $this->assertStringContainsString('variant_image', $definition);
    }

    public function test_fresh_migration_schema_has_banner_and_existing_primary_rules(): void
    {
        $definition = $this->primaryIndexDefinition();

        $this->assertStringContainsString('banner_image', $definition);
        $this->assertStringContainsString('product_image', $definition);
        $this->assertStringContainsString('product_video', $definition);
        $this->assertStringContainsString('variant_image', $definition);
        $this->assertStringContainsString('variant_video', $definition);
    }

    public function test_duplicate_banner_primary_is_rejected(): void
    {
        $banner = Banner::create(['title_en' => 'Banner']);
        $this->insertAttachment('banner', $banner->getKey(), 'banner_image', true);

        $this->expectException(QueryException::class);
        $this->insertAttachment('banner', $banner->getKey(), 'banner_image', true);
    }

    public function test_banner_role_constraint_rejects_non_banner_owners(): void
    {
        $productId = DB::table('products')->insertGetId([
            'slug' => 'migration-role-product-'.Str::lower(Str::random(8)),
            'name_ar' => 'منتج',
            'name_en' => 'Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->insertAttachment('product', $productId, 'banner_image', true);
    }

    public function test_product_and_variant_primary_uniqueness_remains_intact(): void
    {
        $productId = DB::table('products')->insertGetId([
            'slug' => 'migration-product-'.Str::lower(Str::random(8)),
            'name_ar' => 'منتج',
            'name_en' => 'Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertAttachment('product', $productId, 'product_image', true);
        $this->expectException(QueryException::class);
        $this->insertAttachment('product', $productId, 'product_image', true);
    }

    public function test_variant_primary_uniqueness_remains_intact(): void
    {
        $productId = DB::table('products')->insertGetId([
            'slug' => 'migration-variant-product-'.Str::lower(Str::random(8)),
            'name_ar' => 'منتج',
            'name_en' => 'Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $variantId = DB::table('sellable_items')->insertGetId([
            'product_id' => $productId,
            'sku' => 'MIG-'.Str::upper(Str::random(8)),
            'price' => 10,
            'stock_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertAttachment('sellable_item', $variantId, 'variant_image', true);

        $this->expectException(QueryException::class);
        $this->insertAttachment('sellable_item', $variantId, 'variant_image', true);
    }

    public function test_rollback_restores_previous_primary_rules(): void
    {
        $this->migration->down();
        $definition = $this->primaryIndexDefinition();

        $this->assertStringNotContainsString('banner_image', $definition);
        $this->assertStringContainsString('product_image', $definition);
        $this->assertStringContainsString('variant_video', $definition);

        $this->migration->up();
    }

    private function insertAttachment(string $type, int $ownerId, string $role, bool $primary): void
    {
        $assetId = DB::table('media_assets')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'disk' => 'local',
            'path' => 'migration-tests/'.Str::random(16).'.jpg',
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('media_attachments')->insert([
            'media_asset_id' => $assetId,
            'mediable_type' => $type,
            'mediable_id' => $ownerId,
            'role' => $role,
            'sort_order' => 0,
            'is_primary' => $primary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function primaryIndexDefinition(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return (string) (DB::selectOne(
                'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
                ['index', 'media_attachments_primary_owner_role_unique'],
            )->sql ?? '');
        }

        return (string) (DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE indexname = ?',
            ['media_attachments_primary_owner_role_unique'],
        )->indexdef ?? '');
    }
}
