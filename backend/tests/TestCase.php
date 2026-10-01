<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * The database guard runs right after the application boots and before
     * any trait (RefreshDatabase) can touch the database: tests only ever run
     * on in-memory SQLite or a dedicated `_test` database.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $connection = config('database.default');
        TestDatabaseGuard::assertSafe(
            (string) config("database.connections.{$connection}.driver"),
            config("database.connections.{$connection}.database"),
            TestDatabaseGuard::developmentDatabase(base_path('.env')),
        );
    }
}
