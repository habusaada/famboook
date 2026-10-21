<?php

// Unified notifications — configuration and operational readiness
// (ADR FB-NOTIFY-01 ADR-08 / ADR-09, FB-NOTIFY-01B.2b). Read ONLY through
// App\Support\Notifications\NotificationConfig, which validates every value
// and fails closed: an invalid value is never "repaired" into an open state.
// Nothing here publishes anything: readiness is a precondition, never a
// permission. No Production activation without explicit approval.
return [

    // OFF (default) | PILOT (only the senders below) | ON. Missing, empty or
    // anything else is OFF and reported as invalid configuration.
    'mode' => env('NOTIFICATIONS_MODE', 'OFF'),

    // PILOT allow-list: comma-separated canonical user database ids of the
    // senders who may publish manually (e.g. "12,57"). Empty = nobody; one
    // malformed entry = nobody. Server configuration only — never logged,
    // returned or committed with real ids.
    'pilot_sender_user_ids' => env('NOTIFICATIONS_PILOT_SENDER_USER_IDS', ''),

    // PILOT households (FB-NOTIFY-01 pilot isolation, ADR §2h): comma-
    // separated canonical family database ids (e.g. "301,302"). The pilot's
    // RECIPIENT side, next to the sender allow-list above: in PILOT a manual
    // announcement may address ONLY these families, selected one by one (no
    // "whole scope" / "all families" audience), and only they see the family
    // notification UI unless they already hold notifications. Empty = no
    // household; one malformed entry = no household. Ignored in OFF and ON.
    // Server configuration only — never logged, returned or committed.
    'pilot_family_ids' => env('NOTIFICATIONS_PILOT_FAMILY_IDS', ''),

    // Independent gates, each off by default; neither opens the other, and
    // neither works while the mode is OFF. SYSTEM discovery works in ON only,
    // never in PILOT. Only a real boolean `true` opens.
    'system_discovery_enabled' => env('NOTIFICATIONS_SYSTEM_DISCOVERY_ENABLED', false),
    'followups_enabled' => env('NOTIFICATIONS_FOLLOWUPS_ENABLED', false),

    // SYSTEM activation cutoff (ADR-09): events recorded before it are never
    // backfilled. Unset by default — SYSTEM discovery stays closed until it is
    // set. ISO-8601 with an explicit offset, e.g. 2026-11-01T00:00:00+02:00.
    'system_cutoff' => env('NOTIFICATIONS_SYSTEM_CUTOFF'),

    // FOLLOW-UP cutoff (ADR-06, FB-NOTIFY-01E.3-F1): only recipients created
    // (published) at or after it may ever get a follow-up; nothing before it
    // is backfilled. Unset by default, and setting it activates nothing:
    // follow-ups stay closed until the gate above is ALSO true, the mode is
    // not OFF and the worker is ready. Same format as the SYSTEM cutoff.
    'followups_cutoff' => env('NOTIFICATIONS_FOLLOWUPS_CUTOFF'),

    // The dedicated queue every notification job (and the heartbeat) uses.
    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

    // A worker heartbeat older than this is stale (120–3600).
    'heartbeat_max_age_seconds' => env('NOTIFICATIONS_HEARTBEAT_MAX_AGE_SECONDS', 300),

    // Retry policy (R4 as decided in FB-NOTIFY-01B.2b.5b; NotificationRetryPolicy).
    // A worker claim is one publication attempt (publish_attempts). Transient
    // failures are retried automatically while fewer than this many attempts
    // were made (1–3).
    'automatic_publish_attempts' => env('NOTIFICATIONS_AUTOMATIC_PUBLISH_ATTEMPTS', 3),

    // Manual retries a sender may request per message, after the automatic
    // attempts are exhausted or for a manual-only failure (0–1). Never more
    // than 4 worker claims in total, whatever is configured.
    'manual_publish_retries' => env('NOTIFICATIONS_MANUAL_PUBLISH_RETRIES', 1),

    // The REPLACED total-attempts key (FB-NOTIFY-01B.2b.2). Read only so that
    // famboook:notifications-check can warn that it is still set: it is NOT
    // mapped onto the keys above (its "total" meaning would be ambiguous).
    'legacy_max_publish_attempts' => env('NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS'),

    // A PUBLISHING message older than this is a stale candidate (R5, 60–86400).
    'stale_publishing_seconds' => env('NOTIFICATIONS_STALE_PUBLISHING_SECONDS', 600),

    // Announcement management limits, per signed-in user (FB-NOTIFY-01B.2b.6c):
    // reads include the audience preview (a resolution query); writes are
    // draft, publish, cancel and retry.
    'staff_limits' => [
        'read_user_minute' => (int) env('NOTIFICATIONS_STAFF_READ_LIMIT_USER_MINUTE', 60),
        'write_user_minute' => (int) env('NOTIFICATIONS_STAFF_WRITE_LIMIT_USER_MINUTE', 20),
        // Follow-ups (FB-NOTIFY-01E.3-F3.2): lists, summary, detail, history / contact, closure.
        'followup_read_user_minute' => (int) env('NOTIFICATIONS_STAFF_FOLLOWUP_READ_LIMIT_USER_MINUTE', 60),
        'followup_write_user_minute' => (int) env('NOTIFICATIONS_STAFF_FOLLOWUP_WRITE_LIMIT_USER_MINUTE', 20),
    ],
    'coordinator_limits' => [
        'read_user_minute' => (int) env('NOTIFICATIONS_COORDINATOR_READ_LIMIT_USER_MINUTE', 60),
        'write_user_minute' => (int) env('NOTIFICATIONS_COORDINATOR_WRITE_LIMIT_USER_MINUTE', 20),
        'followup_read_user_minute' => (int) env('NOTIFICATIONS_COORDINATOR_FOLLOWUP_READ_LIMIT_USER_MINUTE', 60),
        'followup_write_user_minute' => (int) env('NOTIFICATIONS_COORDINATOR_FOLLOWUP_WRITE_LIMIT_USER_MINUTE', 20),
    ],

    // Family inbox limits, per signed-in user (FB-NOTIFY-01B.2b.6b).
    'family_limits' => [
        'read_user_minute' => (int) env('NOTIFICATIONS_FAMILY_READ_LIMIT_USER_MINUTE', 60),
        'action_user_minute' => (int) env('NOTIFICATIONS_FAMILY_ACTION_LIMIT_USER_MINUTE', 30),
    ],
];
