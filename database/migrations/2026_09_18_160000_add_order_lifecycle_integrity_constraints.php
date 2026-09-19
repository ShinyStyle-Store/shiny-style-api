<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RESERVED_CHECK = 'sellable_items_reserved_nonnegative_check';

    private const STOCK_CHECK = 'sellable_items_stock_nonnegative_check';

    private const QUANTITY_CHECK = 'order_items_quantity_positive_check';

    private const ITEM_INDEX = 'order_items_sellable_item_id_lifecycle_index';

    public function up(): void
    {
        $this->assertNoViolations();

        if (! $this->hasEquivalentIndex('order_items', ['sellable_item_id'])) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->index('sellable_item_id', self::ITEM_INDEX);
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            // SQLite's portable ALTER TABLE support cannot add CHECK constraints here.
            // OrderLifecycleService enforces the same invariants during test execution.
            return;
        }

        if (! $this->hasPostgresConstraint(self::RESERVED_CHECK)) {
            DB::statement('ALTER TABLE "sellable_items" ADD CONSTRAINT "'.self::RESERVED_CHECK.'" CHECK ("reserved_quantity" >= 0)');
        }
        if (! $this->hasPostgresConstraint(self::STOCK_CHECK)) {
            DB::statement('ALTER TABLE "sellable_items" ADD CONSTRAINT "'.self::STOCK_CHECK.'" CHECK ("stock_quantity" >= 0)');
        }
        if (! $this->hasPostgresConstraint(self::QUANTITY_CHECK)) {
            DB::statement('ALTER TABLE "order_items" ADD CONSTRAINT "'.self::QUANTITY_CHECK.'" CHECK ("quantity" > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "sellable_items" DROP CONSTRAINT IF EXISTS "'.self::RESERVED_CHECK.'"');
            DB::statement('ALTER TABLE "sellable_items" DROP CONSTRAINT IF EXISTS "'.self::STOCK_CHECK.'"');
            DB::statement('ALTER TABLE "order_items" DROP CONSTRAINT IF EXISTS "'.self::QUANTITY_CHECK.'"');
        }

        if (Schema::hasIndex('order_items', self::ITEM_INDEX)) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropIndex(self::ITEM_INDEX);
            });
        }
    }

    private function assertNoViolations(): void
    {
        $violations = [
            'negative reserved quantity' => DB::table('sellable_items')->where('reserved_quantity', '<', 0)->count(),
            'negative stock quantity' => DB::table('sellable_items')->where('stock_quantity', '<', 0)->count(),
            'non-positive order item quantity' => DB::table('order_items')->where('quantity', '<=', 0)->count(),
        ];

        $violations = array_filter($violations);
        if ($violations !== []) {
            throw new RuntimeException(
                'Order lifecycle integrity preflight failed: '.implode(', ', array_map(
                    static fn (int $count, string $name): string => "{$name} ({$count})",
                    $violations,
                    array_keys($violations),
                )).'.',
            );
        }
    }

    private function hasEquivalentIndex(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    private function hasPostgresConstraint(string $name): bool
    {
        return DB::table('pg_constraint')
            ->where('conname', $name)
            ->exists();
    }
};
