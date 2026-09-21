<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_product_media_migrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_product_media_id')->unique();
            $table->string('legacy_provider');
            $table->string('legacy_public_id')->nullable();
            $table->text('legacy_secure_url');
            $table->string('legacy_source_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->foreignId('media_attachment_id')->nullable()->constrained('media_attachments')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['legacy_provider', 'status'], 'legacy_media_provider_status_index');
            $table->index(['legacy_provider', 'legacy_source_hash'], 'legacy_media_source_hash_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('legacy_product_media_migrations')
            && DB::table('legacy_product_media_migrations')->exists()) {
            throw new RuntimeException(
                'Legacy ProductMedia migration provenance cannot be dropped after it contains records.'
            );
        }

        Schema::dropIfExists('legacy_product_media_migrations');
    }
};
