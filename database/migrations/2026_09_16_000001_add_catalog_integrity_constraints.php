<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PRICE_CHECK = 'sellable_items_price_nonnegative_check';

    private const ORIGINAL_PRICE_CHECK = 'sellable_items_original_price_nonnegative_check';

    private const STOCK_CHECK = 'sellable_items_stock_nonnegative_check';

    private const DEFAULT_INDEX = 'sellable_items_one_default_per_product_unique';

    private const PRIMARY_CATEGORY_INDEX = 'category_product_one_primary_per_product_unique';

    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Catalog integrity migration does not support the [{$driver}] database driver.");
        }

        $this->assertNoViolations();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "sellable_items" ADD CONSTRAINT "'.self::PRICE_CHECK.'" CHECK ("price" >= 0)');
            DB::statement('ALTER TABLE "sellable_items" ADD CONSTRAINT "'.self::ORIGINAL_PRICE_CHECK.'" CHECK ("original_price" IS NULL OR "original_price" >= 0)');
            DB::statement('ALTER TABLE "sellable_items" ADD CONSTRAINT "'.self::STOCK_CHECK.'" CHECK ("stock_quantity" >= 0)');
            DB::statement('CREATE UNIQUE INDEX "'.self::DEFAULT_INDEX.'" ON "sellable_items" ("product_id") WHERE "is_default" = TRUE AND "deleted_at" IS NULL');
            DB::statement('CREATE UNIQUE INDEX "'.self::PRIMARY_CATEGORY_INDEX.'" ON "category_product" ("product_id") WHERE "is_primary" = TRUE');

            return;
        }

        DB::statement('CREATE UNIQUE INDEX "'.self::DEFAULT_INDEX.'" ON "sellable_items" ("product_id") WHERE "is_default" = 1 AND "deleted_at" IS NULL');
        DB::statement('CREATE UNIQUE INDEX "'.self::PRIMARY_CATEGORY_INDEX.'" ON "category_product" ("product_id") WHERE "is_primary" = 1');
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Catalog integrity migration does not support the [{$driver}] database driver.");
        }

        DB::statement('DROP INDEX IF EXISTS "'.self::DEFAULT_INDEX.'"');
        DB::statement('DROP INDEX IF EXISTS "'.self::PRIMARY_CATEGORY_INDEX.'"');

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "sellable_items" DROP CONSTRAINT IF EXISTS "'.self::PRICE_CHECK.'"');
            DB::statement('ALTER TABLE "sellable_items" DROP CONSTRAINT IF EXISTS "'.self::ORIGINAL_PRICE_CHECK.'"');
            DB::statement('ALTER TABLE "sellable_items" DROP CONSTRAINT IF EXISTS "'.self::STOCK_CHECK.'"');
        }
    }

    private function assertNoViolations(): void
    {
        $violations = [];

        if (DB::table('sellable_items')->where('price', '<', 0)->exists()) {
            $violations[] = 'negative sellable item price';
        }

        if (DB::table('sellable_items')->whereNotNull('original_price')->where('original_price', '<', 0)->exists()) {
            $violations[] = 'negative sellable item original price';
        }

        if (DB::table('sellable_items')->where('stock_quantity', '<', 0)->exists()) {
            $violations[] = 'negative sellable item stock quantity';
        }

        if ($this->hasDuplicateDefaults()) {
            $violations[] = 'multiple non-deleted default variants for a product';
        }

        if ($this->hasDuplicatePrimaryCategories()) {
            $violations[] = 'multiple primary categories for a product';
        }

        if ($violations !== []) {
            throw new RuntimeException(
                'Catalog integrity preflight failed: '.implode('; ', $violations).'.',
            );
        }
    }

    private function hasDuplicateDefaults(): bool
    {
        return DB::table('sellable_items')
            ->select('product_id')
            ->where('is_default', true)
            ->whereNull('deleted_at')
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function hasDuplicatePrimaryCategories(): bool
    {
        return DB::table('category_product')
            ->select('product_id')
            ->where('is_primary', true)
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }
};
