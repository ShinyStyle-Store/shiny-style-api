<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_conversions', function (Blueprint $table): void {
            $table->json('return_restock_baseline')->nullable();
        });

        Schema::create('return_inventory_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('return_receipt_id')->constrained('return_receipts')->restrictOnDelete();
            $table->foreignId('return_receipt_item_id')->constrained('return_receipt_items')->restrictOnDelete();
            $table->foreignId('source_sellable_item_id')->constrained('sellable_items')->restrictOnDelete();
            $table->foreignId('target_sellable_item_id')->constrained('sellable_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->text('reason');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 36)->unique();
            $table->string('request_fingerprint', 64);
            $table->unsignedBigInteger('source_stock_before');
            $table->unsignedBigInteger('source_stock_after');
            $table->unsignedBigInteger('target_stock_before');
            $table->unsignedBigInteger('target_stock_after');
            $table->json('response_snapshot')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'return_receipt_id']);
        });

        Schema::create('return_inventory_transfer_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_inventory_transfer_id')->unique()
                ->constrained('return_inventory_transfers')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 36)->unique();
            $table->string('request_fingerprint', 64);
            $table->unsignedBigInteger('source_stock_before');
            $table->unsignedBigInteger('source_stock_after');
            $table->unsignedBigInteger('target_stock_before');
            $table->unsignedBigInteger('target_stock_after');
            $table->json('response_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_inventory_transfer_reversals');
        Schema::dropIfExists('return_inventory_transfers');

        Schema::table('product_conversions', function (Blueprint $table): void {
            $table->dropColumn('return_restock_baseline');
        });
    }
};
