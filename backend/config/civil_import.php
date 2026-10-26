<?php

/*
 * FB-CIVIL-01A-UI — civil identity import management (docs/adr/FB-CIVIL-01A-UI-*).
 *
 * Nothing here is secret: the monitoring login's password is NOT configured
 * here or in .env. IdentityImportMonitor reads it at run time from the
 * protected file named below (D-UI-9), so it never reaches the cached
 * configuration.
 */
return [

    // Protected PHP file returning ['username' => …, 'password' => …] for the
    // metadata-only monitoring login (root:www-data 0640 on Production).
    'monitor_credentials_file' => env('CIVIL_IMPORT_MONITOR_CREDENTIALS_FILE', '/etc/famboook/identity-monitor.php'),

    // Publication gate (U3). Closed by default; shown read-only in U1 (S-4).
    'publish_enabled' => (bool) env('CIVIL_IMPORT_PUBLISH_ENABLED', false),

];
