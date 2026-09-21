<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'media_attachments_primary_owner_role_unique';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Primary media constraints require PostgreSQL or SQLite.');
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON media_attachments (mediable_type, mediable_id, role) WHERE is_primary = TRUE AND role IN ('product_image', 'product_video', 'variant_image', 'variant_video')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
