<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellable_item_option_values', function (Blueprint $table) {
            $table->foreignId('sellable_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->constrained()->cascadeOnDelete();

            $table->primary(['sellable_item_id', 'product_option_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellable_item_option_values');
    }
};
