<?php

namespace App\Support\Notifications;

use App\Enums\NotificationMode;
use Carbon\CarbonImmutable;

/**
 * The ONE reader of config/notifications.php (FB-NOTIFY-01B.2b). Every value
 * is validated and FAILS CLOSED: an invalid mode is OFF, an invalid gate is
 * off, a malformed allow-list allows nobody, an invalid number or queue name
 * is NULL (and makes NotificationReadiness refuse). invalidKeys() names the
 * offending keys — never their values.
 */
final class NotificationConfig
{
    public const DEFAULT_QUEUE = 'notifications';

    /**
     * The publication job's hard time limit (PublishNotificationJob). Must
     * stay below the queue's retry_after (or a running job is delivered a
     * second time) and the stale threshold below which a PUBLISHING claim is
     * never recovered (R5) — see queueTimingValid().
     */
    public const PUBLICATION_JOB_TIMEOUT_SECONDS = 60;

    /** The keys every notification operation depends on (each cutoff concerns its own channel only). */
    public const REQUIRED_KEYS = [
        'mode',
        'pilot_sender_user_ids',
        'pilot_family_ids',
        'system_discovery_enabled',
        'followups_enabled',
        'queue',
        'heartbeat_max_age_seconds',
        'automatic_publish_attempts',
        'manual_publish_retries',
        'stale_publishing_seconds',
    ];

    /** The effective mode: fail closed to OFF. */
    public static function mode(): NotificationMode
    {
        return self::parsedMode() ?? NotificationMode::OFF;
    }

    /**
     * The pilot allow-list as user ids, or NULL when it is malformed (then
     * nobody is allowed). The CoordinatorSubmissionPolicy format.
     *
     * @return list<int>|null
     */
    public static function pilotSenderUserIds(): ?array
    {
        return self::idList('pilot_sender_user_ids');
    }

    /**
     * The PILOT households (pilot isolation, ADR §2h) as family ids, or NULL
     * when the list is malformed (then no household is a pilot household).
     * The same format as the sender allow-list.
     *
     * @return list<int>|null
     */
    public static function pilotFamilyIds(): ?array
    {
        return self::idList('pilot_family_ids');
    }

    /** @return list<int>|null a comma-separated list of positive ids; NULL when malformed */
    private static function idList(string $key): ?array
    {
        $raw = config("notifications.{$key}");
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [];
        }
        if (! is_string($raw)) {
            return null;
        }

        $ids = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if (preg_match('/^[1-9][0-9]{0,17}$/', $entry) !== 1) {
                return null;
            }
            $ids[] = (int) $entry;
        }

        return array_values(array_unique($ids));
    }

    public static function systemDiscoveryEnabled(): bool
    {
        return config('notifications.system_discovery_enabled') === true;
    }

    public static function followupsEnabled(): bool
    {
        return config('notifications.followups_enabled') === true;
    }

    /** The dedicated queue name, or NULL when invalid. */
    public static function queue(): ?string
    {
        $raw = config('notifications.queue');

        return is_string($raw) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $raw) === 1 ? $raw : null;
    }

    public static function heartbeatMaxAgeSeconds(): ?int
    {
        return self::integer('heartbeat_max_age_seconds', 120, 3600);
    }

    /** Worker claims after which a transient failure is no longer retried automatically (R4: 3). */
    public static function automaticPublishAttempts(): ?int
    {
        return self::integer('automatic_publish_attempts', 1, 3);
    }

    /** Manual retries a sender may request per message (R4: 1). */
    public static function manualPublishRetries(): ?int
    {
        return self::integer('manual_publish_retries', 0, 1);
    }

    /** The default queue connection's retry_after in seconds, or NULL when it has none. */
    public static function queueRetryAfterSeconds(): ?int
    {
        $connection = config('queue.default');
        $retryAfter = is_string($connection) ? config("queue.connections.{$connection}.retry_after") : null;

        return is_int($retryAfter) && $retryAfter > 0 ? $retryAfter : null;
    }

    /**
     * The job timeout, the queue's retry_after and the stale threshold are
     * compatible: timeout < retry_after < stale_publishing_seconds (60 < 90 <
     * 600 by default). Otherwise a running job could be redelivered, or a
     * live claim recovered as stale: fail closed.
     */
    public static function queueTimingValid(): bool
    {
        $retryAfter = self::queueRetryAfterSeconds();
        $stale = self::stalePublishingSeconds();

        return $retryAfter !== null && $stale !== null
            && $retryAfter > self::PUBLICATION_JOB_TIMEOUT_SECONDS
            && $stale > $retryAfter;
    }

    /** Whether the replaced total-attempts key is still configured (it changes nothing). */
    public static function legacyMaxPublishAttemptsSet(): bool
    {
        return config('notifications.legacy_max_publish_attempts') !== null;
    }

    public static function stalePublishingSeconds(): ?int
    {
        return self::integer('stale_publishing_seconds', 60, 86400);
    }

    /** The SYSTEM activation cutoff, or NULL when unset or invalid. */
    public static function systemCutoff(): ?CarbonImmutable
    {
        return self::cutoff('system_cutoff');
    }

    public static function systemCutoffIsSet(): bool
    {
        return self::isSet('system_cutoff');
    }

    /**
     * The FOLLOW-UP cutoff (ADR-06): recipients published before it never get
     * a follow-up (no backfill). NULL when unset or invalid — then follow-ups
     * stay closed (NotificationReadiness::forFollowups).
     */
    public static function followupsCutoff(): ?CarbonImmutable
    {
        return self::cutoff('followups_cutoff');
    }

    public static function followupsCutoffIsSet(): bool
    {
        return self::isSet('followups_cutoff');
    }

    /** @return list<string> the required keys whose value is invalid (names only) */
    public static function invalidKeys(): array
    {
        $valid = [
            'mode' => self::parsedMode() !== null,
            'pilot_sender_user_ids' => self::pilotSenderUserIds() !== null,
            'pilot_family_ids' => self::pilotFamilyIds() !== null,
            'system_discovery_enabled' => is_bool(config('notifications.system_discovery_enabled')),
            'followups_enabled' => is_bool(config('notifications.followups_enabled')),
            'queue' => self::queue() !== null,
            'heartbeat_max_age_seconds' => self::heartbeatMaxAgeSeconds() !== null,
            'automatic_publish_attempts' => self::automaticPublishAttempts() !== null,
            'manual_publish_retries' => self::manualPublishRetries() !== null,
            'stale_publishing_seconds' => self::stalePublishingSeconds() !== null,
        ];

        return array_keys(array_filter($valid, fn (bool $ok) => ! $ok));
    }

    private static function parsedMode(): ?NotificationMode
    {
        $raw = config('notifications.mode');

        return is_string($raw) ? NotificationMode::tryFrom(strtoupper(trim($raw))) : null;
    }

    /** An ISO-8601 instant with an explicit offset (in UTC), else NULL. */
    private static function cutoff(string $key): ?CarbonImmutable
    {
        $raw = config("notifications.{$key}");
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $raw) !== 1) {
            return null;
        }

        $cutoff = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $raw);
        // A round trip rejects overflowing dates and times (2026-02-30, 25:00).
        if ($cutoff === null || $cutoff->format('Y-m-d\TH:i:s') !== substr($raw, 0, 19)) {
            return null;
        }

        return $cutoff->utc();
    }

    private static function isSet(string $key): bool
    {
        $raw = config("notifications.{$key}");

        return $raw !== null && ! (is_string($raw) && trim($raw) === '');
    }

    /** An integer (or digit string) within [min, max], else NULL. */
    private static function integer(string $key, int $min, int $max): ?int
    {
        $raw = config("notifications.{$key}");
        if (is_string($raw) && preg_match('/^[0-9]{1,9}$/', trim($raw)) === 1) {
            $raw = (int) trim($raw);
        }

        return is_int($raw) && $raw >= $min && $raw <= $max ? $raw : null;
    }
}
