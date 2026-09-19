<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'order_items_order_id_read_index';

    public function up(): void
    {
        $hasEquivalentIndex = collect(Schema::getIndexes('order_items'))
            ->contains(fn (array $index): bool => $index['columns'] === ['order_id']);

        if (! $hasEquivalentIndex) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->index('order_id', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('order_items', self::INDEX)) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
