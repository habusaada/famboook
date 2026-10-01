<?php

namespace App\Support\Import\Apply;

/**
 * In-process runner lock for non-PostgreSQL drivers (the SQLite test suite):
 * the same non-blocking acquire / release contract, held for the lifetime of
 * this (singleton) instance. It does NOT coordinate separate processes and is
 * never bound for PostgreSQL.
 */
final class ProcessApplyRunnerLock implements ApplyRunnerLock
{
    /** @var array<int, true> */
    private array $held = [];

    public function acquire(int $batchId): bool
    {
        if (isset($this->held[$batchId])) {
            return false;
        }
        $this->held[$batchId] = true;

        return true;
    }

    public function release(int $batchId): void
    {
        unset($this->held[$batchId]);
    }
}
