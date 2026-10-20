<?php

namespace App\Console\Commands;

use App\Support\Notifications\NotificationFollowupEngine;
use Illuminate\Console\Command;

/**
 * One bounded follow-up run (FB-NOTIFY-01E.3-F2) — NotificationFollowupEngine:
 * OPEN follow-ups of expired messages close as EXPIRED (always), then due
 * stages open (only when NotificationReadiness::forFollowups() is ready and
 * the follow-up cutoff is valid). Scheduled every minute (routes/console.php);
 * idempotent and safe to run concurrently (SKIP LOCKED per recipient).
 * Output: counters only.
 *
 * Exit codes: 0 done, 1 a step failed (retried next run), 2 invalid --limit.
 */
class NotificationsFollowups extends Command
{
    protected $signature = 'famboook:notifications-followups {--limit=100 : The most recipients per phase in this run (1-1000)}';

    protected $description = 'Open due notification follow-ups and close expired ones (counts only).';

    public function handle(NotificationFollowupEngine $engine): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/^[1-9][0-9]{0,3}$/', $limit) !== 1 || (int) $limit > 1000) {
            $this->error('--limit must be an integer from 1 to 1000.');

            return 2;
        }

        $counts = $engine->run((int) $limit);
        $this->line('opening: '.($counts['opening_ready'] ? 'READY' : 'CLOSED (famboook:notifications-check shows why)'));
        foreach ($counts as $counter => $value) {
            if ($counter !== 'opening_ready') {
                $this->line("{$counter}: {$value}");
            }
        }

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
