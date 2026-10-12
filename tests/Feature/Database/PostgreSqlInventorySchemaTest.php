<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

#[Group('postgres-inventory')]
class PostgreSqlInventorySchemaTest extends PostgreSqlInventoryTestCase
{
    use RefreshDatabase;

    public function test_inventory_migrations_run_on_the_isolated_postgresql_server_and_create_expected_indexes(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        $databaseIdentity = DB::selectOne(
            'select current_database() as database_name, current_setting(\'server_version\') as server_version',
        );
        $this->assertSame('shiny_style_pg_test', $databaseIdentity->database_name);
        $this->assertNotSame('', $databaseIdentity->server_version);
        $reportPath = env('SHINY_STYLE_PG_TEST_REPORT_PATH');
        if (is_string($reportPath) && $reportPath !== '') {
            $this->assertNotFalse(file_put_contents($reportPath, json_encode([
                'database' => $databaseIdentity->database_name,
                'server_version' => $databaseIdentity->server_version,
            ], JSON_THROW_ON_ERROR)));
        }

        $indexes = collect(DB::select(
            'select tablename, indexname, indexdef from pg_indexes where schemaname = current_schema()',
        ))->keyBy('indexname');

        foreach ([
            'sellable_items_one_default_per_product_unique',
            'sellable_items_product_combination_unique',
            'product_conversions_product_id_unique',
            'product_conversions_source_sellable_item_id_unique',
            'product_conversions_idempotency_key_unique',
            'return_inventory_transfers_idempotency_key_unique',
            'return_inventory_transfer_reversals_return_inventory_transfer_id_unique',
            'return_inventory_transfer_reversals_idempotency_key_unique',
        ] as $indexName) {
            $this->assertTrue($indexes->has($indexName), "Missing PostgreSQL index {$indexName}.");
            $this->assertStringContainsString('UNIQUE', strtoupper($indexes[$indexName]->indexdef));
        }

        $baselineColumn = DB::selectOne(
            "select is_nullable from information_schema.columns where table_schema = current_schema() and table_name = 'product_conversions' and column_name = 'return_restock_baseline'",
        );
        $this->assertSame('YES', $baselineColumn->is_nullable);

        $orderSnapshot = DB::selectOne(
            "select is_nullable from information_schema.columns where table_schema = current_schema() and table_name = 'order_items' and column_name = 'options_snapshot'",
        );
        $this->assertNotNull($orderSnapshot);
    }
}
