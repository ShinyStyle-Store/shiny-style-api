<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_options', function (Blueprint $table): void {
            $table->string('name_ar_normalized')->nullable()->after('name_ar');
            $table->string('name_en_normalized')->nullable()->after('name_en');
            $table->softDeletes();
            $table->index(['product_id', 'sort_order', 'id']);
        });

        Schema::table('product_option_values', function (Blueprint $table): void {
            $table->string('value_ar_normalized')->nullable()->after('value_ar');
            $table->string('value_en_normalized')->nullable()->after('value_en');
            $table->softDeletes();
            $table->index(['product_option_id', 'sort_order', 'id']);
        });

        foreach (DB::table('product_options')->select('id', 'name_ar', 'name_en')->get() as $option) {
            DB::table('product_options')->where('id', $option->id)->update([
                'name_ar_normalized' => trim((string) $option->name_ar),
                'name_en_normalized' => mb_strtolower(trim((string) $option->name_en)),
            ]);
        }

        foreach (DB::table('product_option_values')->select('id', 'value_ar', 'value_en')->get() as $value) {
            DB::table('product_option_values')->where('id', $value->id)->update([
                'value_ar_normalized' => trim((string) $value->value_ar),
                'value_en_normalized' => mb_strtolower(trim((string) $value->value_en)),
            ]);
        }

        Schema::table('product_options', function (Blueprint $table): void {
            $table->unique(['product_id', 'name_ar_normalized'], 'product_options_product_name_ar_unique');
            $table->unique(['product_id', 'name_en_normalized'], 'product_options_product_name_en_unique');
        });

        Schema::table('product_option_values', function (Blueprint $table): void {
            $table->unique(['product_option_id', 'value_ar_normalized'], 'product_option_values_option_value_ar_unique');
            $table->unique(['product_option_id', 'value_en_normalized'], 'product_option_values_option_value_en_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_values', function (Blueprint $table): void {
            $table->dropUnique('product_option_values_option_value_ar_unique');
            $table->dropUnique('product_option_values_option_value_en_unique');
            $table->dropIndex(['product_option_id', 'sort_order', 'id']);
            $table->dropSoftDeletes();
            $table->dropColumn(['value_ar_normalized', 'value_en_normalized']);
        });

        Schema::table('product_options', function (Blueprint $table): void {
            $table->dropUnique('product_options_product_name_ar_unique');
            $table->dropUnique('product_options_product_name_en_unique');
            $table->dropIndex(['product_id', 'sort_order', 'id']);
            $table->dropSoftDeletes();
            $table->dropColumn(['name_ar_normalized', 'name_en_normalized']);
        });
    }
};
