<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Schema;

trait RecreatesLegacyProductMediaTable
{
    protected function recreateLegacyProductMediaTable(): void
    {
        if (Schema::hasTable('product_media')) {
            return;
        }

        $migration = require base_path('database/migrations/2026_09_15_000008_create_product_media_table.php');
        $migration->up();
    }
}
