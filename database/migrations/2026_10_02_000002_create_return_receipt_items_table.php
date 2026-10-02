<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_receipt_id')->constrained('return_receipts')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('sellable_item_id')->nullable()->constrained('sellable_items')->nullOnDelete();
            $table->unsignedInteger('original_quantity');
            $table->unsignedInteger('initial_received_quantity');
            $table->unsignedInteger('initial_restockable_quantity');
            $table->string('initial_reason');
            $table->unsignedInteger('effective_received_quantity');
            $table->unsignedInteger('effective_restockable_quantity');
            $table->string('effective_reason');
            $table->text('initial_note')->nullable();
            $table->text('effective_note')->nullable();
            $table->timestamps();

            $table->unique(['return_receipt_id', 'order_item_id']);
            $table->index(['order_item_id', 'effective_received_quantity']);
        });

        // Blueprint::check() is not available in this project's Laravel version.
        // PostgreSQL gets database-level checks; SQLite relies on the service
        // validation and unsigned integer columns used by the test database.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "return_receipt_items" ADD CONSTRAINT "return_receipt_items_initial_received_positive" CHECK ("initial_received_quantity" > 0)');
            DB::statement('ALTER TABLE "return_receipt_items" ADD CONSTRAINT "return_receipt_items_initial_restockable_valid" CHECK ("initial_restockable_quantity" <= "initial_received_quantity")');
            DB::statement('ALTER TABLE "return_receipt_items" ADD CONSTRAINT "return_receipt_items_effective_restockable_valid" CHECK ("effective_restockable_quantity" <= "effective_received_quantity")');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_receipt_items');
    }
};
