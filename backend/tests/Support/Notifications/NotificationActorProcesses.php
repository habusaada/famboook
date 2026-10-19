<?php

namespace Tests\Support\Notifications;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * FB-NOTIFY-01B.2b.5c / 5d PostgreSQL concurrency harness: runs
 * publication-actor.php steps in their OWN PHP process and database session
 * (the existing concurrent-actor convention). Each actor prints READY, waits
 * for one STDIN line "<milliseconds>", waits that long and proceeds.
 *
 * Only against a reachable PostgreSQL *_test database; the actor refuses
 * anything else too.
 */
trait NotificationActorProcesses
{
    /** @var list<array{process: resource, pipes: array<int, resource>}> */
    private array $children = [];

    private static function pgsqlTestDatabaseIsReachable(): bool
    {
        $env = static fn (string $key): ?string => ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) ?: null;
        $database = (string) $env('DB_DATABASE');
        if ($env('DB_CONNECTION') !== 'pgsql' || ! str_ends_with($database, '_test')) {
            return false;
        }
        try {
            new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST') ?? '127.0.0.1', $env('DB_PORT') ?? '5432', $database),
                $env('DB_USERNAME'),
                $env('DB_PASSWORD'),
            );

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** The children connect to the SAME isolated test database, with an array cache. */
    private function exportActorEnvironment(): void
    {
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'), 'DB_URL' => '', 'APP_ENV' => 'testing', 'CACHE_STORE' => 'array'] as $key => $value) {
            putenv("{$key}={$value}");
        }
    }

    private function stopActors(): void
    {
        foreach ($this->children as $child) {
            proc_terminate($child['process']);
            proc_close($child['process']);
        }
        $this->children = [];
    }

    /** Starts an actor and waits for its READY line. */
    private function spawn(string ...$args): int
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/publication-actor.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 30);
        $this->children[] = ['process' => $process, 'pipes' => $pipes];
        $ready = $this->lineOf(count($this->children) - 1);
        if ($ready !== 'READY') {
            $this->fail("actor did not start ({$ready}): ".stream_get_contents($pipes[2]));
        }

        return count($this->children) - 1;
    }

    /** Lets a READY actor proceed after $milliseconds (a holder then commits); also resumes a paused actor. */
    private function go(int $child, int $milliseconds = 0): void
    {
        fwrite($this->children[$child]['pipes'][0], $milliseconds.PHP_EOL);
        fflush($this->children[$child]['pipes'][0]);
    }

    private function lineOf(int $child): string
    {
        return trim((string) fgets($this->children[$child]['pipes'][1]));
    }

    /** Resets the session lock_timeout and truncates (DatabaseTruncation). */
    private function resetConcurrencyDatabase(): void
    {
        DB::statement('RESET lock_timeout');
        $this->truncateTablesForAllConnections();
    }
}
