<?php

namespace App\Support\Import\Apply;

/**
 * At most ONE Apply runner per batch at a time (docs/03 §96b). Non-blocking:
 * acquire() answers immediately; a caller that does not get the lock
 * reports APPLY_IN_PROGRESS instead of waiting. Always released in finally.
 *
 * Bound per database driver (AppServiceProvider): PostgreSQL uses a real
 * session advisory lock; other drivers (the SQLite test suite) use an
 * in-process lock with the same observable behaviour. SQLite tests prove
 * the runner's handling of the lock, not PostgreSQL's locking itself.
 */
interface ApplyRunnerLock
{
    public function acquire(int $batchId): bool;

    public function release(int $batchId): void;
}
