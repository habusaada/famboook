<?php

namespace App\Support\Import\Apply;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL session advisory lock per batch: pg_try_advisory_lock(namespace,
 * batch id) never waits. Session-level on purpose — it spans the chunk's many
 * row transactions; PostgreSQL also releases it if the connection ends.
 * $connection names the database connection (session); null = default.
 */
final class PostgresApplyRunnerLock implements ApplyRunnerLock
{
    /** Lock namespace ("FAMB" as int32) separating these keys from other advisory locks. */
    public const NAMESPACE = 1178684738;

    public function __construct(private readonly ?string $connection = null) {}

    public function acquire(int $batchId): bool
    {
        return (bool) DB::connection($this->connection)->selectOne('SELECT pg_try_advisory_lock(?, ?) AS locked', [self::NAMESPACE, $batchId])->locked;
    }

    public function release(int $batchId): void
    {
        DB::connection($this->connection)->selectOne('SELECT pg_advisory_unlock(?, ?) AS unlocked', [self::NAMESPACE, $batchId]);
    }
}
