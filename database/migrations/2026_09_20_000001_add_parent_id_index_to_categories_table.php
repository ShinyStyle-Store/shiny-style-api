<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'categories_parent_id_index';

    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        foreach (Schema::getIndexes('categories') as $index) {
            if (($index['columns'][0] ?? null) === 'parent_id') {
                return;
            }
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->index('parent_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        $hasIndex = collect(Schema::getIndexes('categories'))
            ->contains(fn (array $index): bool => $index['name'] === self::INDEX);

        if ($hasIndex) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
