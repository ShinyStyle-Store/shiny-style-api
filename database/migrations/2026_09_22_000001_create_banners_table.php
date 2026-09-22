<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SQLITE_INSERT_TRIGGER = 'banners_integrity_insert';

    private const SQLITE_UPDATE_TRIGGER = 'banners_integrity_update';

    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('cta_text_ar')->nullable();
            $table->string('cta_text_en')->nullable();
            $table->string('cta_type')->nullable();
            $table->text('cta_target')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'deleted_at', 'sort_order', 'id'], 'banners_public_order_index');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "banners" ADD CONSTRAINT "banners_title_present_check" CHECK (("title_ar" IS NOT NULL AND length(trim("title_ar")) > 0) OR ("title_en" IS NOT NULL AND length(trim("title_en")) > 0))');
            DB::statement('ALTER TABLE "banners" ADD CONSTRAINT "banners_cta_type_check" CHECK ("cta_type" IS NULL OR "cta_type" IN (\'product\', \'category\', \'url\'))');
            DB::statement('ALTER TABLE "banners" ADD CONSTRAINT "banners_cta_complete_check" CHECK (((NULLIF(trim(COALESCE("cta_text_ar", \'\')), \'\') IS NULL AND NULLIF(trim(COALESCE("cta_text_en", \'\')), \'\') IS NULL) AND "cta_type" IS NULL AND NULLIF(trim(COALESCE("cta_target", \'\')), \'\') IS NULL) OR ((NULLIF(trim(COALESCE("cta_text_ar", \'\')), \'\') IS NOT NULL OR NULLIF(trim(COALESCE("cta_text_en", \'\')), \'\') IS NOT NULL) AND "cta_type" IS NOT NULL AND NULLIF(trim(COALESCE("cta_target", \'\')), \'\') IS NOT NULL))');
            DB::statement('ALTER TABLE "banners" ADD CONSTRAINT "banners_sort_order_nonnegative_check" CHECK ("sort_order" >= 0)');

            return;
        }

        if ($driver === 'sqlite') {
            $valid = "((NEW.title_ar IS NOT NULL AND length(trim(NEW.title_ar)) > 0) OR (NEW.title_en IS NOT NULL AND length(trim(NEW.title_en)) > 0)) AND (NEW.cta_type IS NULL OR NEW.cta_type IN ('product', 'category', 'url')) AND (((NULLIF(trim(COALESCE(NEW.cta_text_ar, '')), '') IS NULL AND NULLIF(trim(COALESCE(NEW.cta_text_en, '')), '') IS NULL) AND NEW.cta_type IS NULL AND NULLIF(trim(COALESCE(NEW.cta_target, '')), '') IS NULL) OR ((NULLIF(trim(COALESCE(NEW.cta_text_ar, '')), '') IS NOT NULL OR NULLIF(trim(COALESCE(NEW.cta_text_en, '')), '') IS NOT NULL) AND NEW.cta_type IS NOT NULL AND NULLIF(trim(COALESCE(NEW.cta_target, '')), '') IS NOT NULL)) AND NEW.sort_order >= 0";
            DB::statement('CREATE TRIGGER "'.self::SQLITE_INSERT_TRIGGER.'" BEFORE INSERT ON "banners" FOR EACH ROW WHEN NOT ('.$valid.') BEGIN SELECT RAISE(ABORT, \'banners integrity constraint failed\'); END');
            DB::statement('CREATE TRIGGER "'.self::SQLITE_UPDATE_TRIGGER.'" BEFORE UPDATE ON "banners" FOR EACH ROW WHEN NOT ('.$valid.') BEGIN SELECT RAISE(ABORT, \'banners integrity constraint failed\'); END');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS "'.self::SQLITE_INSERT_TRIGGER.'"');
            DB::statement('DROP TRIGGER IF EXISTS "'.self::SQLITE_UPDATE_TRIGGER.'"');
        }

        Schema::dropIfExists('banners');
    }
};
