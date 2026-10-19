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

    // Independent gates, each off by default; neither opens the other, and
    // neither works while the mode is OFF. SYSTEM discovery works in ON only,
    // never in PILOT. Only a real boolean `true` opens.
    'system_discovery_enabled' => env('NOTIFICATIONS_SYSTEM_DISCOVERY_ENABLED', false),
    'followups_enabled' => env('NOTIFICATIONS_FOLLOWUPS_ENABLED', false),

    // SYSTEM activation cutoff (ADR-09): events recorded before it are never
    // backfilled. Unset by default — SYSTEM discovery stays closed until it is
    // set. ISO-8601 with an explicit offset, e.g. 2026-11-01T00:00:00+02:00.
    'system_cutoff' => env('NOTIFICATIONS_SYSTEM_CUTOFF'),

    // The dedicated queue every notification job (and the heartbeat) uses.
    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

    // A worker heartbeat older than this is stale (120–3600).
    'heartbeat_max_age_seconds' => env('NOTIFICATIONS_HEARTBEAT_MAX_AGE_SECONDS', 300),

    // Automatic publication attempts before manual retry or cancel (R4, 1–10).
    'max_publish_attempts' => env('NOTIFICATIONS_MAX_PUBLISH_ATTEMPTS', 3),

    // A PUBLISHING message older than this is a stale candidate (R5, 60–86400).
    'stale_publishing_seconds' => env('NOTIFICATIONS_STALE_PUBLISHING_SECONDS', 600),
];
