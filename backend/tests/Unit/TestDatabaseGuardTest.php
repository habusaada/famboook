<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

/** The test-database guard, as a pure function (no database is touched). */
class TestDatabaseGuardTest extends TestCase
{
    #[DataProvider('refused')]
    public function test_persistent_non_test_databases_are_refused(string $driver, ?string $database, ?string $development): void
    {
        $this->expectExceptionObject(new RuntimeException(TestDatabaseGuard::REFUSAL));
        TestDatabaseGuard::assertSafe($driver, $database, $development);
    }

    public static function refused(): array
    {
        return [
            'development database' => ['pgsql', 'famboook', 'famboook'],
            'no suffix' => ['pgsql', 'famboook_copy', 'famboook'],
            'empty name' => ['pgsql', '', 'famboook'],
            'null name' => ['pgsql', null, 'famboook'],
            'suffix not at the end' => ['pgsql', 'famboook_test_backup', 'famboook'],
            'development db that ends in _test' => ['pgsql', 'shared_test', 'shared_test'],
            'file-based sqlite' => ['sqlite', '/var/data/app.sqlite', null],
        ];
    }

    public function test_in_memory_sqlite_and_dedicated_test_databases_are_allowed(): void
    {
        TestDatabaseGuard::assertSafe('sqlite', ':memory:', 'famboook');
        TestDatabaseGuard::assertSafe('pgsql', 'famboook_test', 'famboook');
        TestDatabaseGuard::assertSafe('pgsql', 'famboook_test', null);
        $this->addToAssertionCount(3);
    }

    public function test_the_development_database_name_is_read_from_env(): void
    {
        $env = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($env, "APP_NAME=x\nDB_CONNECTION=pgsql\nDB_DATABASE=\"famboook\"\nDB_PASSWORD=not-read\n");
        $this->assertSame('famboook', TestDatabaseGuard::developmentDatabase($env));
        file_put_contents($env, "DB_CONNECTION=sqlite\n");
        $this->assertNull(TestDatabaseGuard::developmentDatabase($env));
        unlink($env);
        $this->assertNull(TestDatabaseGuard::developmentDatabase($env));
    }
}
