<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['products', 'categories', 'content_pages', 'sellable_items'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->timestamp('seo_last_significant_modified_at')->nullable();
                if ($tableName !== 'sellable_items') {
                    $table->timestamp('seo_last_significant_modified_at_ar')->nullable();
                    $table->timestamp('seo_last_significant_modified_at_en')->nullable();
                }
            });

            DB::table($tableName)->update([
                'seo_last_significant_modified_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
            if ($tableName !== 'sellable_items') {
                DB::table($tableName)->update([
                    'seo_last_significant_modified_at_ar' => DB::raw('COALESCE(updated_at, created_at)'),
                    'seo_last_significant_modified_at_en' => DB::raw('COALESCE(updated_at, created_at)'),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $columns = ['seo_last_significant_modified_at'];
                if ($tableName !== 'sellable_items') {
                    $columns[] = 'seo_last_significant_modified_at_ar';
                    $columns[] = 'seo_last_significant_modified_at_en';
                }
                $table->dropColumn($columns);
            });
        }
    }
};
