<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sellable_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('cloudinary');
            $table->string('type')->default('image');
            $table->string('public_id');
            $table->text('secure_url');
            $table->string('alt_text_ar')->nullable();
            $table->string('alt_text_en')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'public_id']);
            $table->index(['product_id', 'sort_order']);
            $table->index('sellable_item_id');
            $table->index(['product_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
