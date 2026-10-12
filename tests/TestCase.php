<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\DedicatedPostgresDatabase;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits(): array
    {
        if (config('database.default') === 'pgsql') {
            DedicatedPostgresDatabase::assertConfiguredAndConnected();
        }

        return parent::setUpTraits();
    }
}
