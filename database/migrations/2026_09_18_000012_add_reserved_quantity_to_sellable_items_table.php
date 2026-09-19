<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellable_items', function (Blueprint $table): void {
            $table->unsignedInteger('reserved_quantity')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sellable_items', function (Blueprint $table): void {
            $table->dropColumn('reserved_quantity');
        });
    }
};
