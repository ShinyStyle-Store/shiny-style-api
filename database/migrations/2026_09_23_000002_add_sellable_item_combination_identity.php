<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'sellable_items_product_combination_unique';

    public function up(): void
    {
        Schema::table('sellable_items', function (Blueprint $table): void {
            $table->string('combination_key', 255)->default('')->after('sort_order');
        });

        foreach (DB::table('sellable_items')->select('id')->get() as $item) {
            $ids = DB::table('sellable_item_option_values')
                ->where('sellable_item_id', $item->id)
                ->orderBy('product_option_value_id')
                ->pluck('product_option_value_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            DB::table('sellable_items')->where('id', $item->id)->update([
                'combination_key' => implode(',', $ids),
            ]);
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX "'.self::INDEX.'" ON "sellable_items" ("product_id", "combination_key") WHERE "deleted_at" IS NULL AND "combination_key" <> \'\'');
        } elseif ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX "'.self::INDEX.'" ON "sellable_items" ("product_id", "combination_key") WHERE "deleted_at" IS NULL AND "combination_key" <> \'\'');
        } else {
            throw new RuntimeException('Unsupported database driver for sellable item combination integrity.');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "'.self::INDEX.'"');
        Schema::table('sellable_items', function (Blueprint $table): void {
            $table->dropColumn('combination_key');
        });
    }
};
