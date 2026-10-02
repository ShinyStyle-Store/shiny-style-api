<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_correction_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_correction_id')->constrained('return_corrections')->restrictOnDelete();
            $table->foreignId('return_receipt_item_id')->constrained('return_receipt_items')->restrictOnDelete();
            $table->unsignedInteger('previous_received_quantity');
            $table->unsignedInteger('previous_restockable_quantity');
            $table->string('previous_reason');
            $table->unsignedInteger('new_received_quantity');
            $table->unsignedInteger('new_restockable_quantity');
            $table->string('new_reason');
            $table->text('new_note')->nullable();
            $table->timestamps();

            $table->unique(['return_correction_id', 'return_receipt_item_id']);
        });

        // Blueprint::check() is not available in this project's Laravel version.
        // PostgreSQL gets database-level checks; SQLite relies on the service
        // validation and unsigned integer columns used by the test database.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "return_correction_items" ADD CONSTRAINT "return_correction_items_previous_restockable_valid" CHECK ("previous_restockable_quantity" <= "previous_received_quantity")');
            DB::statement('ALTER TABLE "return_correction_items" ADD CONSTRAINT "return_correction_items_new_restockable_valid" CHECK ("new_restockable_quantity" <= "new_received_quantity")');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_correction_items');
    }
};
