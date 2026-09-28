<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('discount_percentage', 5, 2);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();

            $table->index(['is_enabled', 'starts_at', 'ends_at']);
        });

        Schema::create('offer_product', function (Blueprint $table): void {
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['offer_id', 'product_id']);
            $table->index('product_id');
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "offers" ADD CONSTRAINT "offers_discount_percentage_check" CHECK ("discount_percentage" > 0 AND "discount_percentage" < 100)');
            DB::statement('ALTER TABLE "offers" ADD CONSTRAINT "offers_time_window_check" CHECK ("starts_at" < "ends_at")');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('CREATE TRIGGER "offers_integrity_insert" BEFORE INSERT ON "offers" FOR EACH ROW WHEN NOT (NEW.discount_percentage > 0 AND NEW.discount_percentage < 100 AND NEW.starts_at < NEW.ends_at) BEGIN SELECT RAISE(ABORT, \'offers integrity constraint failed\'); END');
            DB::statement('CREATE TRIGGER "offers_integrity_update" BEFORE UPDATE ON "offers" FOR EACH ROW WHEN NOT (NEW.discount_percentage > 0 AND NEW.discount_percentage < 100 AND NEW.starts_at < NEW.ends_at) BEGIN SELECT RAISE(ABORT, \'offers integrity constraint failed\'); END');

            return;
        }

        throw new RuntimeException("Offers migration does not support the [{$driver}] database driver.");
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "offers" DROP CONSTRAINT IF EXISTS "offers_discount_percentage_check"');
            DB::statement('ALTER TABLE "offers" DROP CONSTRAINT IF EXISTS "offers_time_window_check"');
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS "offers_integrity_insert"');
            DB::statement('DROP TRIGGER IF EXISTS "offers_integrity_update"');
        }

        Schema::dropIfExists('offer_product');
        Schema::dropIfExists('offers');
    }
};
