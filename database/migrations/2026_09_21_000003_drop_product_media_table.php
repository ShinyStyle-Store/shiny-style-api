<?php

use App\Services\ProductMediaMigrationVerificationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $result = app(ProductMediaMigrationVerificationService::class)->verify();
        if ($result['issues'] !== []) {
            throw new RuntimeException(
                'The product_media table cannot be dropped until media:verify-product-media-migration succeeds.'
            );
        }

        Schema::drop('product_media');
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_media')) {
            // This restores the schema only. Rows removed by up() cannot be reconstructed here.
            $legacyMigration = require __DIR__.'/2026_09_15_000008_create_product_media_table.php';
            $legacyMigration->up();
        }
    }
};
