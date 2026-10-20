<?php

namespace App\Support\Notifications;

use App\Enums\NotificationHeartbeatState;
use App\Enums\NotificationMode;
use App\Enums\NotificationReadinessReason as Reason;
use App\Models\User;

/**
 * The ONE answer to "may notification work start now?" (FB-NOTIFY-01B.2b;
 * ADR FB-NOTIFY-01 ADR-08: no synchronous fallback, nothing before the worker
 * is operational). Each method returns the reasons it may NOT — an empty list
 * means ready. READ-ONLY: it publishes nothing, dispatches nothing and writes
 * no notification table.
 *
 * Readiness is never authorization: a ready system grants no one the right to
 * publish. Permissions, policies and the coordinator's current scope are
 * checked by their own owners.
 */
final class NotificationReadiness
{
    /**
     * The sender-independent preconditions of manual publication: valid
     * configuration, mode not OFF, an asynchronous queue and a fresh worker
     * heartbeat. (In PILOT a sender must also be allow-listed: forSender.)
     *
     * @return list<Reason>
     */
    public static function forPublication(): array
    {
        $reasons = self::configuration();
        if (NotificationConfig::mode() === NotificationMode::OFF) {
            $reasons[] = Reason::MODE_OFF;
        }

        return [...$reasons, ...self::infrastructure()];
    }

    /**
     * May THIS sender's manual publication be requested now?
     *
     * @return list<Reason>
     */
    public static function forSender(User $sender): array
    {
        $reasons = self::forPublication();
        if (NotificationConfig::mode() === NotificationMode::PILOT
            && ! in_array((int) $sender->getKey(), NotificationConfig::pilotSenderUserIds() ?? [], true)) {
            $reasons[] = Reason::SENDER_NOT_IN_PILOT;
        }

        return $reasons;
    }

    /**
     * SYSTEM discovery (ADR-09): mode ON only, its own gate and a valid
     * activation cutoff. PILOT never opens it — the pilot allow-list names
     * manual senders, and SYSTEM messages have no sender to restrict.
     *
     * @return list<Reason>
     */
    public static function forSystemDiscovery(): array
    {
        $reasons = self::forPublication();
        if (NotificationConfig::mode() === NotificationMode::PILOT) {
            $reasons[] = Reason::SYSTEM_DISCOVERY_REQUIRES_ON;
        }
        if (! NotificationConfig::systemDiscoveryEnabled()) {
            $reasons[] = Reason::SYSTEM_DISCOVERY_DISABLED;
        }
        if (! NotificationConfig::systemCutoffIsSet()) {
            $reasons[] = Reason::SYSTEM_CUTOFF_MISSING;
        } elseif (NotificationConfig::systemCutoff() === null) {
            $reasons[] = Reason::SYSTEM_CUTOFF_INVALID;
        }

        return $reasons;
    }

    /**
     * Follow-up automation (ADR-06 §2c): its own gate and a valid follow-up
     * cutoff (no backfill before it) on top of a mode that is not OFF. A set
     * cutoff alone opens nothing.
     *
     * @return list<Reason>
     */
    public static function forFollowups(): array
    {
        $reasons = self::forPublication();
        if (! NotificationConfig::followupsEnabled()) {
            $reasons[] = Reason::FOLLOWUPS_DISABLED;
        }
        if (! NotificationConfig::followupsCutoffIsSet()) {
            $reasons[] = Reason::FOLLOWUPS_CUTOFF_MISSING;
        } elseif (NotificationConfig::followupsCutoff() === null) {
            $reasons[] = Reason::FOLLOWUPS_CUTOFF_INVALID;
        }

        return $reasons;
    }

    /**
     * The scheduler and worker: an asynchronous queue whose retry_after fits
     * the publication job's timeout and the stale threshold, and a fresh
     * heartbeat (dispatched by the scheduler, run by a worker on the
     * notifications queue).
     *
     * @return list<Reason>
     */
    public static function infrastructure(): array
    {
        $reasons = [];
        if (! self::queueIsAsynchronous()) {
            $reasons[] = Reason::QUEUE_NOT_ASYNC;
        } elseif (! NotificationConfig::queueTimingValid()) {
            $reasons[] = Reason::QUEUE_TIMING_INVALID;
        }
        $reasons[] = match (NotificationHeartbeat::state()) {
            NotificationHeartbeatState::FRESH => null,
            NotificationHeartbeatState::STALE => Reason::HEARTBEAT_STALE,
            NotificationHeartbeatState::ABSENT => Reason::HEARTBEAT_ABSENT,
        };

        return array_values(array_filter($reasons));
    }

    /** The driver of the default queue connection, or NULL when it is not defined. */
    public static function queueDriver(): ?string
    {
        $connection = config('queue.default');
        $driver = is_string($connection) ? config("queue.connections.{$connection}.driver") : null;

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /** `sync` runs in the caller's process and `null` discards: neither is a worker. */
    public static function queueIsAsynchronous(): bool
    {
        $driver = self::queueDriver();

        return $driver !== null && ! in_array($driver, ['sync', 'null'], true);
    }

    /** @return list<Reason> */
    private static function configuration(): array
    {
        return NotificationConfig::invalidKeys() === [] ? [] : [Reason::CONFIG_INVALID];
    }
}
