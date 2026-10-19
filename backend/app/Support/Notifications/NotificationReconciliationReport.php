<?php

namespace App\Support\Notifications;

/**
 * The counters of one reconciliation run (FB-NOTIFY-01B.2b.5d). Counts only:
 * never an id, a title, a body, a person or an exception text.
 */
final class NotificationReconciliationReport
{
    /** Candidates examined under their row lock. */
    public int $scanned = 0;

    /** QUEUED messages handed to the worker again (no state change). */
    public int $orphanedRedispatched = 0;

    /** Stale PUBLISHING claims turned into PUBLISH_FAILED STALE_PUBLISHING. */
    public int $staleRecovered = 0;

    /** Transient failures requeued automatically (REQUEUED by SYSTEM). */
    public int $automaticRequeued = 0;

    /** Actions withheld by a gate or readiness (mode, PILOT, SYSTEM, infrastructure). */
    public int $paused = 0;

    /** Candidates left alone: locked by another session, no longer eligible, or refused by the retry policy. */
    public int $skipped = 0;

    /** Candidates whose transaction failed (rolled back; retried on a later run). */
    public int $errors = 0;

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'orphaned_redispatched' => $this->orphanedRedispatched,
            'stale_recovered' => $this->staleRecovered,
            'automatic_requeued' => $this->automaticRequeued,
            'paused' => $this->paused,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
