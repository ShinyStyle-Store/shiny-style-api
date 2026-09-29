<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('base_unit_price', 10, 2)->nullable()->after('unit_price');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('base_unit_price');
            $table->foreignId('offer_id')->nullable()->after('discount_amount')->constrained('offers')->nullOnDelete();
            $table->decimal('offer_discount_percentage', 5, 2)->nullable()->after('offer_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['offer_id']);
            $table->dropColumn([
                'base_unit_price',
                'discount_amount',
                'offer_id',
                'offer_discount_percentage',
            ]);
        });
    }
};
