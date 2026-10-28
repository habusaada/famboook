<?php

/*
 * FB-CIVIL-02A — Civil Registry Lookup (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md).
 *
 * OFF by default. In production the lookup also needs an approval reference
 * in the PROCESS environment (CIVIL_LOOKUP_PRODUCTION_APPROVAL, read at run
 * time, never cached), issued only after G1 / OD-08 and OD-09 (§L-1).
 * Nothing here is secret: the lookup login's password lives in the protected
 * credentials file only.
 */
return [

    'enabled' => (bool) env('CIVIL_LOOKUP_ENABLED', false),

    // §L-8: household members and children (reverse lookups). Needs
    // 09-lookup-indexes.dba.sql and the OD-03 / OD-10 decisions.
    'reverse_links_enabled' => (bool) env('CIVIL_LOOKUP_REVERSE_LINKS_ENABLED', false),

    // §L-13: FAMILY_USER / COORDINATOR lookup (FB-CIVIL-02B). Closed in 02A.
    'family_side_enabled' => false,

    // The read-only lookup login (08-lookup.dba.sql): a PHP file returning
    // ['username' => ..., 'password' => ...], root:www-data 0640 on a server.
    'credentials_file' => env('CIVIL_LOOKUP_CREDENTIALS_FILE', '/etc/famboook/identity-lookup.php'),

    // The quota day (§L-6).
    'timezone' => 'Asia/Gaza',

    // §L-7: lifetime of search, record and link references.
    'reference_ttl_minutes' => 30,

    // §L-11, per user (independent of the daily quota).
    'rate_limits' => [
        'per_minute' => (int) env('CIVIL_LOOKUP_RATE_PER_MINUTE', 20),
        'per_hour' => (int) env('CIVIL_LOOKUP_RATE_PER_HOUR', 300),
        'invalid_per_hour' => (int) env('CIVIL_LOOKUP_INVALID_PER_HOUR', 30),
    ],
];
