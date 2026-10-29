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

    // FB-CIVIL-02B (docs/adr/FB-CIVIL-02B-FAMILY-COORDINATOR-LOOKUP.md §B-2):
    // the Family Portal (household head) and Coordinator Space sides, each
    // closed by default and independent of the other. Opening one only lets
    // its gated role permissions and per-user GRANTED rows take effect; in
    // production each also needs its own approval reference and the
    // EXTENDED_CONTROLLED field-profile approval in the PROCESS environment
    // (CivilLookupGate), never a value here.
    'family_side_enabled' => (bool) env('CIVIL_LOOKUP_FAMILY_ENABLED', false),
    'coordinator_side_enabled' => (bool) env('CIVIL_LOOKUP_COORDINATOR_ENABLED', false),

    // FB-CIVIL-02G (ADR 02B §B-5b): the explicit eye-icon reveal of ONE complete
    // National ID at a time on each family-side side (Staff may always reveal).
    // Each OFF by default and independent; in production each also needs its
    // own approval reference in the PROCESS environment
    // (CIVIL_LOOKUP_FAMILY_ID_REVEAL_APPROVAL / CIVIL_LOOKUP_COORDINATOR_ID_REVEAL_APPROVAL).
    'family_id_reveal_enabled' => (bool) env('CIVIL_LOOKUP_FAMILY_ID_REVEAL_ENABLED', false),
    'coordinator_id_reveal_enabled' => (bool) env('CIVIL_LOOKUP_COORDINATOR_ID_REVEAL_ENABLED', false),

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
