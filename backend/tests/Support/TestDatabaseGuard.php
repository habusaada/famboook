<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Refuses to run tests against a persistent database that is not a
 * dedicated, disposable test database — BEFORE RefreshDatabase can migrate
 * (wired in Tests\TestCase::refreshApplication()).
 *
 * The in-memory SQLite suite is always allowed. Any other driver needs a
 * non-empty database name ending in `_test`, and never the development
 * database named in .env. The message carries no credentials.
 */
final class TestDatabaseGuard
{
    public const REFUSAL = 'REFUSING_POSTGRES_TESTS_ON_NON_TEST_DATABASE';

    public static function assertSafe(string $driver, ?string $database, ?string $developmentDatabase): void
    {
        $database = trim((string) $database);
        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }
        if ($database === '' || ! str_ends_with($database, '_test')
            || ($developmentDatabase !== null && $database === $developmentDatabase)) {
            throw new RuntimeException(self::REFUSAL);
        }
    }

    /** DB_DATABASE from the application's .env (the development database), if any. */
    public static function developmentDatabase(string $envFile): ?string
    {
        return self::developmentValue($envFile, 'DB_DATABASE');
    }

    /** A non-secret connection value (name, host, port) from .env; never used for passwords. */
    public static function developmentValue(string $envFile, string $key): ?string
    {
        if (! is_file($envFile) || $key === 'DB_PASSWORD') {
            return null;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*"?([^"#\s]*)"?/', $line, $m)) {
                return $m[1] === '' ? null : $m[1];
            }
        }

        return null;
    }
}
