<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'media_attachments_primary_owner_role_unique';

    private const OWNER_ROLE_CHECK = 'media_attachments_banner_role_owner_check';

    private const SQLITE_INSERT_TRIGGER = 'media_attachments_banner_role_insert';

    private const SQLITE_UPDATE_TRIGGER = 'media_attachments_banner_role_update';

    public function up(): void
    {
        $this->assertSupportedDriver();
        DB::statement('DROP INDEX IF EXISTS "'.self::INDEX.'"');
        DB::statement('CREATE UNIQUE INDEX "'.self::INDEX.'" ON "media_attachments" ("mediable_type", "mediable_id", "role") WHERE "is_primary" = TRUE AND "role" IN (\'product_image\', \'product_video\', \'variant_image\', \'variant_video\', \'banner_image\')');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "media_attachments" ADD CONSTRAINT "'.self::OWNER_ROLE_CHECK.'" CHECK (("mediable_type" <> \'banner\' OR "role" = \'banner_image\') AND ("role" <> \'banner_image\' OR ("mediable_type" = \'banner\' AND "is_primary" = TRUE)))');

            return;
        }

        $invalid = "(NEW.mediable_type = 'banner' AND NEW.role <> 'banner_image') OR (NEW.role = 'banner_image' AND (NEW.mediable_type <> 'banner' OR NEW.is_primary <> 1))";
        DB::statement('CREATE TRIGGER "'.self::SQLITE_INSERT_TRIGGER.'" BEFORE INSERT ON "media_attachments" FOR EACH ROW WHEN '.$invalid.' BEGIN SELECT RAISE(ABORT, \'invalid banner media owner or role\'); END');
        DB::statement('CREATE TRIGGER "'.self::SQLITE_UPDATE_TRIGGER.'" BEFORE UPDATE OF "mediable_type", "role", "is_primary" ON "media_attachments" FOR EACH ROW WHEN '.$invalid.' BEGIN SELECT RAISE(ABORT, \'invalid banner media owner or role\'); END');
    }

    public function down(): void
    {
        $this->assertSupportedDriver();
        DB::statement('DROP INDEX IF EXISTS "'.self::INDEX.'"');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "media_attachments" DROP CONSTRAINT IF EXISTS "'.self::OWNER_ROLE_CHECK.'"');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS "'.self::SQLITE_INSERT_TRIGGER.'"');
            DB::statement('DROP TRIGGER IF EXISTS "'.self::SQLITE_UPDATE_TRIGGER.'"');
        }

        DB::statement('CREATE UNIQUE INDEX "'.self::INDEX.'" ON "media_attachments" ("mediable_type", "mediable_id", "role") WHERE "is_primary" = TRUE AND "role" IN (\'product_image\', \'product_video\', \'variant_image\', \'variant_video\')');
    }

    private function assertSupportedDriver(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Banner media constraints require PostgreSQL or SQLite.');
        }
    }
};
