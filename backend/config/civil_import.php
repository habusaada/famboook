<?php

/*
 * FB-CIVIL-01A-UI — civil identity import management (docs/adr/FB-CIVIL-01A-UI-*).
 *
 * Nothing here is secret: the monitoring login's password is NOT configured
 * here or in .env. IdentityImportMonitor reads it at run time from the
 * protected file named below (D-UI-9), so it never reaches the cached
 * configuration. The worker's logins (U2, D-UI-10) come from its own
 * root-owned environment file, loaded by systemd; the worker never reads the
 * application .env or the cached configuration (identity-import-worker).
 */
return [

    // Protected PHP file returning ['username' => …, 'password' => …] for the
    // metadata-only monitoring login (root:www-data 0640 on Production).
    'monitor_credentials_file' => env('CIVIL_IMPORT_MONITOR_CREDENTIALS_FILE', '/etc/famboook/identity-monitor.php'),

    // Publication gate (U3). Closed by default; shown read-only (S-4).
    // U2 implements no publication execution whatever this value is.
    'publish_enabled' => (bool) env('CIVIL_IMPORT_PUBLISH_ENABLED', false),

    // ---- U2: the dedicated import worker (ADR §4). Read by the worker only,
    // except the timing values, which the web application also uses to
    // describe the worker's state.
    'worker' => [
        // Server-side source root (D-UI-3): one sub-directory per export,
        // placed by an authorized operator. Never served, never read by the
        // web process. Unset: no source is catalogued.
        'source_root' => env('CIVIL_IMPORT_SOURCE_ROOT'),

        // The database the worker must be connected to (the FB-CIVIL-01A
        // --target check). Unset: every execution request is refused.
        'target' => env('CIVIL_IMPORT_TARGET_DATABASE'),

        'chunk_size' => (int) env('CIVIL_IMPORT_CHUNK_SIZE', 5000),
        'idle_poll_seconds' => 30,
        'heartbeat_seconds' => 30,

        // A RUNNING request without a heartbeat for this long is WORKER_LOST
        // (its FB-CIVIL-01A operation stays resumable).
        'stale_after_minutes' => 10,

        // The worker is shown as STALE when it has not been seen for this long.
        'offline_after_seconds' => 120,
    ],

];
