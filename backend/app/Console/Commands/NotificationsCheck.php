<?php

namespace App\Console\Commands;

use App\Enums\NotificationFollowupStatus;
use App\Enums\NotificationHeartbeatState;
use App\Enums\NotificationMode;
use App\Enums\NotificationReadinessReason;
use App\Enums\NotificationStatus;
use App\Support\Notifications\NotificationConfig;
use App\Support\Notifications\NotificationFollowupEngine;
use App\Support\Notifications\NotificationHeartbeat;
use App\Support\Notifications\NotificationReadiness;
use App\Support\Notifications\NotificationRetryPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Notification configuration and operational readiness (FB-NOTIFY-01B.2b) —
 * READ-ONLY. Prints the effective mode and gates, the queue driver, the
 * worker heartbeat, readiness per channel as reason codes, and counts only:
 * never a user or family id, a name, message content, a recipient or a
 * credential. It dispatches nothing and writes nothing, and stays safe when
 * the notification tables (or failed_jobs) do not exist in this database.
 *
 * Warnings (exit code 1): invalid configuration, a set but invalid SYSTEM
 * or follow-up cutoff, the replaced NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS still set, an
 * enabled mode or gate that is not ready, PILOT with no usable allow-list,
 * stale PUBLISHING messages, and an enabled mode without the notification
 * tables. The release default (everything OFF) has none.
 */
class NotificationsCheck extends Command
{
    protected $signature = 'famboook:notifications-check';

    protected $description = 'Read-only check of the notification mode, gates, queue, worker heartbeat and publication counts (counts and codes only).';

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        // The command instance is reused within one process (scheduler, tests):
        // every run starts without the previous run's warnings.
        $this->warnings = [];
        $this->configuration();
        $this->infrastructure();
        $this->readiness();
        $this->counts();

        if ($this->warnings === []) {
            $this->info('Notifications: no warnings.');

            return self::SUCCESS;
        }
        foreach ($this->warnings as $warning) {
            $this->warn("WARN: {$warning}");
        }

        return self::FAILURE;
    }

    private function configuration(): void
    {
        $mode = NotificationConfig::mode();
        $invalid = NotificationConfig::invalidKeys();
        $ids = NotificationConfig::pilotSenderUserIds();

        $this->line('Notification mode (effective): '.$mode->value);
        $this->line('Configuration: '.($invalid === [] ? 'VALID' : 'INVALID ('.implode(', ', $invalid).')'));
        $this->line('Pilot sender allow-list: '.match (true) {
            $ids === null => 'INVALID (no sender may publish in PILOT)',
            $ids === [] => 'empty',
            default => count($ids).' id(s)',
        });
        $this->line('SYSTEM discovery gate: '.(NotificationConfig::systemDiscoveryEnabled() ? 'ON' : 'OFF'));
        $this->line('Follow-ups gate: '.(NotificationConfig::followupsEnabled() ? 'ON' : 'OFF'));
        $cutoff = match (true) {
            ! NotificationConfig::systemCutoffIsSet() => 'NOT SET',
            NotificationConfig::systemCutoff() === null => 'INVALID',
            default => 'VALID',
        };
        $this->line('SYSTEM cutoff: '.$cutoff);
        $followupsCutoff = match (true) {
            ! NotificationConfig::followupsCutoffIsSet() => 'NOT SET',
            NotificationConfig::followupsCutoff() === null => 'INVALID',
            default => 'VALID',
        };
        $this->line('Follow-up cutoff: '.$followupsCutoff);
        $claims = app(NotificationRetryPolicy::class)->claimLimit();
        $this->line('Retry policy: '.($claims === null
            ? 'INVALID (no retry)'
            : NotificationConfig::automaticPublishAttempts().' automatic attempt(s), '.NotificationConfig::manualPublishRetries().' manual retry(ies), at most '.$claims.' claim(s)'));
        $this->line('Legacy NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS set: '.(NotificationConfig::legacyMaxPublishAttemptsSet() ? 'YES' : 'NO'));

        if ($invalid !== []) {
            $this->warnings[] = 'Invalid notification configuration ('.implode(', ', $invalid).') — every notification operation is closed.';
        }
        if ($cutoff === 'INVALID') {
            $this->warnings[] = 'NOTIFICATIONS_SYSTEM_CUTOFF is invalid (ISO-8601 with an offset) — SYSTEM discovery is closed.';
        }
        if ($followupsCutoff === 'INVALID') {
            $this->warnings[] = 'NOTIFICATIONS_FOLLOWUPS_CUTOFF is invalid (ISO-8601 with an offset) — follow-ups are closed.';
        }
        if (NotificationConfig::legacyMaxPublishAttemptsSet()) {
            $this->warnings[] = 'Remove NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS: it is replaced by NOTIFICATIONS_AUTOMATIC_PUBLISH_ATTEMPTS and NOTIFICATIONS_MANUAL_PUBLISH_RETRIES and changes nothing.';
        }
        if ($mode === NotificationMode::PILOT && ($ids === null || $ids === [])) {
            $this->warnings[] = 'PILOT mode with no usable allow-listed sender: nobody can publish.';
        }
    }

    private function infrastructure(): void
    {
        $driver = NotificationReadiness::queueDriver();
        $this->line('Queue connection driver: '.($driver ?? 'UNDEFINED').' ('.(NotificationReadiness::queueIsAsynchronous() ? 'asynchronous' : 'NOT asynchronous').')');
        $this->line('Notification queue: '.(NotificationConfig::queue() ?? 'INVALID'));

        $state = NotificationHeartbeat::state();
        $age = NotificationHeartbeat::ageSeconds();
        $max = NotificationConfig::heartbeatMaxAgeSeconds();
        $this->line('Worker heartbeat: '.($state === NotificationHeartbeatState::ABSENT
            ? 'ABSENT'
            : $state->value.' (age '.$age.' s, max '.($max ?? 'INVALID').($max === null ? '' : ' s').')'));
        $this->line('Scheduler/worker: '.(NotificationReadiness::infrastructure() === [] ? 'READY' : 'NOT READY'));
    }

    private function readiness(): void
    {
        $publication = NotificationReadiness::forPublication();
        $system = NotificationReadiness::forSystemDiscovery();
        $followups = NotificationReadiness::forFollowups();

        $this->line('Publication readiness: '.$this->verdict($publication));
        $this->line('SYSTEM discovery readiness: '.$this->verdict($system));
        $this->line('Follow-up readiness: '.$this->verdict($followups));

        if (NotificationConfig::mode() !== NotificationMode::OFF && $publication !== []) {
            $this->warnings[] = 'Notifications are enabled but publication is not ready: '.$this->codes($publication).'.';
        }
        if (NotificationConfig::systemDiscoveryEnabled() && $system !== []) {
            $this->warnings[] = 'SYSTEM discovery is enabled but not ready: '.$this->codes($system).'.';
        }
        if (NotificationConfig::followupsEnabled() && $followups !== []) {
            $this->warnings[] = 'Follow-ups are enabled but not ready: '.$this->codes($followups).'.';
        }
    }

    private function counts(): void
    {
        $tables = $this->safely(fn () => Schema::hasTable('notification_messages'));
        $this->line('Notification tables: '.match ($tables) {
            true => 'present',
            false => 'ABSENT',
            default => 'unavailable',
        });

        $stale = null;
        if ($tables === true) {
            $messages = fn () => DB::table('notification_messages');
            $this->line('Messages QUEUED: '.$this->count(fn () => $messages()->where('status', NotificationStatus::QUEUED->value)->count()));
            $this->line('Messages PUBLISHING: '.$this->count(fn () => $messages()->where('status', NotificationStatus::PUBLISHING->value)->count()));
            $threshold = NotificationConfig::stalePublishingSeconds();
            $stale = $threshold === null ? null : $this->safely(fn () => $messages()
                ->where('status', NotificationStatus::PUBLISHING->value)
                ->where('publishing_started_at', '<', now()->subSeconds($threshold))
                ->count());
            $this->line('Stale PUBLISHING'.($threshold === null ? '' : " (> {$threshold} s)").': '.$this->count(fn () => $stale));
            $this->followupCounts();
        } else {
            $this->line('Messages QUEUED: unavailable');
            $this->line('Messages PUBLISHING: unavailable');
            $this->line('Stale PUBLISHING: unavailable');
            $this->line('open_followups: unavailable');
            $this->line('expired_open: unavailable');
            $this->line('due_unopened: unavailable');
        }
        $this->line('Failed jobs (notification queue): '.$this->count(fn () => $this->failedJobs()));

        if (is_int($stale) && $stale > 0) {
            $this->warnings[] = "{$stale} PUBLISHING message(s) are stale candidates for recovery.";
        }
        if ($tables === false && NotificationConfig::mode() !== NotificationMode::OFF) {
            $this->warnings[] = 'Notifications are enabled but the notification tables do not exist in this database.';
        }
    }

    /**
     * Follow-up metrics (FB-NOTIFY-01E.3-F2) — informational counts only. No
     * warning and no threshold (none is approved), so the exit code and the
     * health script's states are unchanged, and an OFF-mode backlog is never
     * a failure:
     * - open_followups: every OPEN follow-up;
     * - expired_open:   OPEN follow-ups whose message reached expires_at or is
     *                   ARCHIVED — famboook:notifications-followups closes
     *                   them EXPIRED whatever the gate;
     * - due_unopened:   (recipient, stage) pairs that the opening rules would
     *                   open now (published at or after the cutoff, due, unmet,
     *                   not expired, family ACTIVE at publication and now) with
     *                   no follow-up yet. Annotated with the opening state: a
     *                   backlog while opening is not ready is EXPECTED; "not
     *                   applicable" without a valid cutoff (nothing is due).
     * The per-run opened / expired / deferred counters are printed by
     * famboook:notifications-followups itself.
     */
    private function followupCounts(): void
    {
        $engine = app(NotificationFollowupEngine::class);
        $now = now()->toImmutable();
        $this->line('open_followups: '.$this->count(fn () => DB::table('notification_followups')
            ->where('status', NotificationFollowupStatus::OPEN->value)->count()));
        $this->line('expired_open: '.$this->count(fn () => $engine->expiredOpenQuery($now)->count()));

        $cutoff = NotificationConfig::followupsCutoff();
        if ($cutoff === null) {
            $this->line('due_unopened: not applicable (follow-up cutoff not set or invalid)');

            return;
        }
        $readiness = NotificationReadiness::forFollowups();
        $this->line('due_unopened: '
            .$this->count(fn () => array_sum(array_map(fn ($query) => $query->count(), $engine->dueQueries($now, $cutoff))))
            .($readiness === []
                ? ' (opening ready: opened by famboook:notifications-followups)'
                : ' (expected while opening is not ready: '.$this->codes($readiness).')'));
    }

    /** Failed jobs on the notification queue, or NULL when the failed-job store is not a readable table. */
    private function failedJobs(): ?int
    {
        $queue = NotificationConfig::queue();
        $driver = config('queue.failed.driver');
        $connection = config('queue.failed.database');
        $table = config('queue.failed.table');
        if ($queue === null || ! in_array($driver, ['database', 'database-uuids'], true) || ! is_string($table)) {
            return null;
        }

        return Schema::connection($connection)->hasTable($table)
            ? DB::connection($connection)->table($table)->where('queue', $queue)->count()
            : null;
    }

    /** @param  list<NotificationReadinessReason>  $reasons */
    private function verdict(array $reasons): string
    {
        return $reasons === [] ? 'READY' : 'NOT READY ('.$this->codes($reasons).')';
    }

    /** @param  list<NotificationReadinessReason>  $reasons */
    private function codes(array $reasons): string
    {
        return implode(', ', array_map(fn (NotificationReadinessReason $reason) => $reason->value, $reasons));
    }

    private function count(callable $count): string
    {
        $value = $this->safely($count);

        return is_int($value) ? (string) $value : 'unavailable';
    }

    /** A read that must not fail the check: any database error becomes NULL (no message is printed). */
    private function safely(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
