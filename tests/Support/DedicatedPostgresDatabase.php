<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DedicatedPostgresDatabase
{
    public static function assertConfiguredAndConnected(): object
    {
        $connection = config('database.connections.pgsql');
        $expectedDatabase = (string) env('SHINY_STYLE_PG_TEST_DATABASE', '');
        $host = strtolower((string) ($connection['host'] ?? ''));

        if (config('database.default') !== 'pgsql'
            || env('SHINY_STYLE_PG_TESTING') !== '1'
            || $expectedDatabase !== 'shiny_style_pg_test'
            || ($connection['database'] ?? null) !== $expectedDatabase
            || ! in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
            || trim((string) ($connection['url'] ?? '')) !== '') {
            throw new RuntimeException('PostgreSQL tests require the explicit isolated shiny_style_pg_test connection.');
        }

        $identity = DB::connection('pgsql')->selectOne(
            'select current_database() as database_name, current_setting(\'server_version\') as server_version',
        );

        if (($identity->database_name ?? null) !== $expectedDatabase) {
            throw new RuntimeException('The active PostgreSQL database does not match the isolated test database.');
        }

        return $identity;
    }
}
