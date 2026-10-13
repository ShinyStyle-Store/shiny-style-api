<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_records', function (Blueprint $table): void {
            $table->id();
            $table->string('seoable_type', 40);
            $table->unsignedBigInteger('seoable_id');
            $table->string('locale', 5);
            $table->string('meta_title', 180)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_path', 2048)->nullable();
            $table->boolean('robots_index')->nullable();
            $table->boolean('robots_follow')->nullable();
            $table->string('og_title', 180)->nullable();
            $table->text('og_description')->nullable();
            $table->foreignId('og_image_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['seoable_type', 'seoable_id', 'locale'],
                'seo_records_target_locale_unique',
            );
            $table->index(['seoable_type', 'seoable_id'], 'seo_records_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_records');
    }
};
