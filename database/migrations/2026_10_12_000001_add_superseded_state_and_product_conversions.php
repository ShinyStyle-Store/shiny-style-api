<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellable_items', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('deleted_at');
        });

        Schema::create('product_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_sellable_item_id')->constrained('sellable_items')->restrictOnDelete();
            $table->unique('product_id');
            $table->unique('source_sellable_item_id');
            $table->string('idempotency_key', 36)->unique();
            $table->string('request_fingerprint', 64);
            $table->string('context_token', 64);
            $table->unsignedBigInteger('old_stock');
            $table->unsignedBigInteger('allocated_stock');
            $table->bigInteger('stock_difference');
            $table->text('stock_difference_reason')->nullable();
            $table->json('allocation');
            $table->json('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_conversions');

        Schema::table('sellable_items', function (Blueprint $table): void {
            $table->dropColumn('superseded_at');
        });
    }
};
