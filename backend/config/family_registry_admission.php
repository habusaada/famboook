<?php

/*
 * FB-FAMILY-ADD-01 — direct admission of civil-verified children
 * (docs/adr/FB-FAMILY-ADD-01-DIRECT-ADMISSION.md §11).
 *
 * OFF (default) | PILOT | ON. Read ONLY through FamilyRegistryAdmissionPolicy,
 * which fails closed: missing or invalid → OFF. Foundation only: nothing reads
 * it to open a route or a workflow yet, so no value activates anything.
 * Independent of every other switch. Changing it needs gate G-07.
 */
return [

    'mode' => env('FAMILY_REGISTRY_ADMISSION_MODE', 'OFF'),

    // PILOT only (ADD-WP-06B-3): the Family ids allowed, comma-separated. Empty or
    // unreadable entries allow nobody. Ignored by OFF and ON.
    'pilot_family_ids' => array_values(array_filter(array_map(
        fn (string $id) => ctype_digit(trim($id)) && (int) trim($id) > 0 ? (int) trim($id) : null,
        explode(',', (string) env('FAMILY_REGISTRY_ADMISSION_PILOT_FAMILY_IDS', '')),
    ))),

    // The version of the household head's declaration an admission must carry
    // (ADD-WP-07B-2c; ADR §6, open decision O-K). NO default: while it is unset,
    // every admission is refused DECLARATION_REQUIRED (fail closed).
    'declaration_version' => env('FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION'),

    // Per-user discovery and admission limits (ADR §10), independent of each other and of
    // the civil lookup. Admission attempts are counted before validation (ADD-WP-07C).
    'limits' => [
        'discovery_user_minute' => (int) env('FAMILY_REGISTRY_DISCOVERY_LIMIT_USER_MINUTE', 10),
        'discovery_user_hour' => (int) env('FAMILY_REGISTRY_DISCOVERY_LIMIT_USER_HOUR', 60),
        'admission_user_minute' => (int) env('FAMILY_REGISTRY_ADMISSION_LIMIT_USER_MINUTE', 5),
        'admission_user_hour' => (int) env('FAMILY_REGISTRY_ADMISSION_LIMIT_USER_HOUR', 20),
    ],

];
