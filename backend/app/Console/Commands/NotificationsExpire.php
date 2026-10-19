<?php

namespace App\Console\Commands;

use App\Support\Notifications\NotificationExpirySweeper;
use Illuminate\Console\Command;

/**
 * One notification expiry sweep (FB-NOTIFY-01B.2b.6b): PUBLISHED messages
 * whose expires_at is reached become ARCHIVED — NotificationExpirySweeper.
 * Scheduled every minute (routes/console.php); idempotent; runs whatever the
 * feature mode. Output: counters only.
 *
 * Exit codes: 0 done, 1 a step failed (retried next run), 2 invalid --limit.
 */
class NotificationsExpire extends Command
{
    protected $signature = 'famboook:notifications-expire {--limit=100 : The most messages archived in this run (1-1000)}';

    protected $description = 'Archive notifications whose expiry is reached (counts only).';

    public function handle(NotificationExpirySweeper $sweeper): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || preg_match('/^[1-9][0-9]{0,3}$/', $limit) !== 1 || (int) $limit > 1000) {
            $this->error('--limit must be an integer from 1 to 1000.');

            return 2;
        }

        $counts = $sweeper->run((int) $limit);
        foreach ($counts as $counter => $value) {
            $this->line("{$counter}: {$value}");
        }

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
