<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'cover_image_url')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropColumn('cover_image_url');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('categories', 'cover_image_url')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->text('cover_image_url')->nullable()->after('description_en');
            });
        }
    }
};
