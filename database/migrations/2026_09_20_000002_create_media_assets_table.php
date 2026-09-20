<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique('media_assets_public_id_unique');
            $table->string('disk', 32);
            $table->string('path', 255);
            $table->string('original_name')->nullable();
            $table->string('media_type', 16);
            $table->string('mime_type', 127);
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration_seconds', 10, 3)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['disk', 'path'], 'media_assets_disk_path_unique');
            $table->index('created_by', 'media_assets_created_by_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
