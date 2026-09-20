<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->morphs('mediable');
            $table->string('role', 64);
            $table->string('locale', 12)->nullable();
            $table->string('device', 32)->nullable();
            $table->string('alt_ar')->nullable();
            $table->string('alt_en')->nullable();
            $table->text('caption_ar')->nullable();
            $table->text('caption_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('media_asset_id', 'media_attachments_media_asset_id_index');
            $table->index(
                ['mediable_type', 'mediable_id', 'role'],
                'media_attachments_mediable_role_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
