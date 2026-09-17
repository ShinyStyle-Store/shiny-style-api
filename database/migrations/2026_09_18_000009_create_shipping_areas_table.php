<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('shipping_areas')
                ->restrictOnDelete();
            $table->string('type');
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('shipping_fee', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_selectable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Non-negative fees and the rule requiring a fee for selectable
            // areas will be enforced through application validation when
            // authenticated admin write endpoints are implemented.
            $table->index('parent_id');
            $table->index(['is_active', 'is_selectable', 'sort_order', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_areas');
    }
};
