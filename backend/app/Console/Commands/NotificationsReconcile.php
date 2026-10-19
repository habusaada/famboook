<?php

namespace App\Console\Commands;

use App\Support\Notifications\NotificationPublicationReconciler;
use Illuminate\Console\Command;

/**
 * One notification publication reconciliation run (FB-NOTIFY-01B.2b.5d):
 * recovers stale PUBLISHING claims, requeues transient failures within the
 * retry policy and hands orphaned QUEUED messages to the worker again —
 * NotificationPublicationReconciler. Scheduled every minute
 * (routes/console.php); idempotent, so a repeated or overlapping run does no
 * harm. It never publishes. Output: counters only.
 *
 * Exit codes: 0 done, 1 a step failed (counted as errors; retried next run),
 * 2 invalid --limit.
 */
class NotificationsReconcile extends Command
{
    protected $signature = 'famboook:notifications-reconcile {--limit=100 : The most messages handled in this run (1-1000)}';

    protected $description = 'Recover notification publication: stale claims, automatic retries and orphaned queued messages (counts only).';

    public function handle(NotificationPublicationReconciler $reconciler): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/^[1-9][0-9]{0,3}$/', $limit) !== 1 || (int) $limit > 1000) {
            $this->error('--limit must be an integer from 1 to 1000.');

            return 2;
        }

        $report = $reconciler->run((int) $limit);
        foreach ($report->toArray() as $counter => $value) {
            $this->line("{$counter}: {$value}");
        }

        return $report->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
