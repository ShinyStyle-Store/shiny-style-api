<?php

namespace Tests\Feature\Database;

use Tests\Support\DedicatedPostgresDatabase;
use Tests\TestCase;

abstract class PostgreSqlInventoryTestCase extends TestCase
{
    protected function setUp(): void
    {
        DedicatedPostgresDatabase::assertConfiguredAndConnected();

        parent::setUp();
    }
}
