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
    // every admission is refused DECLARATION_REQUIRED (fail closed). It must name an
    // APPROVED entry of `declarations` below (RR-02, RegistryAdmissionDeclarations).
    'declaration_version' => env('FAMILY_REGISTRY_ADMISSION_DECLARATION_VERSION'),

    // The declaration catalogue (RR-02; ADR §6, G-02): version => text and approval.
    // A version's text NEVER changes; a new wording is a new version. `approved` is
    // flipped only by a reviewed commit recording the G-02 decision, never by .env.
    // A draft is never delivered or accepted.
    'declarations' => [
        '2026-11-draft-1' => [
            'approved' => false,
            'text' => 'أُقرّ بأن هذا الابن / هذه الابنة من أفراد أسرتي ويقيم/تقيم فعليًا ضمن هذه الأسرة، وأن هذا الإقرار صحيح على مسؤوليتي.',
        ],
    ],

    // Proof of life (RR-02; D-06, G-05). Only an authoritative source bound as
    // RegistryLifeVerifier can prove a life, and NONE is bound: an empty death date is
    // never proof. Evidence older than this, from the future or without a date fails
    // closed.
    'life_verification' => [
        'max_age_hours' => (int) env('FAMILY_REGISTRY_LIFE_EVIDENCE_MAX_AGE_HOURS', 24),
    ],

    // RR-02: bind the published civil registry (the RR-02 adapter over
    // CivilRegistryReader) as the discovery source and the release pin verifier.
    // Default false: nothing is bound and every allowed request answers 503. Turning it
    // on needs DBA-1 … DBA-4 applied and verified and gate G-07 (readiness command).
    'civil_registry_binding' => filter_var(env('FAMILY_REGISTRY_ADMISSION_CIVIL_REGISTRY_BINDING', false), FILTER_VALIDATE_BOOL),

    // Per-user discovery and admission limits (ADR §10), independent of each other and of
    // the civil lookup. Admission attempts are counted before validation (ADD-WP-07C).
    'limits' => [
        'discovery_user_minute' => (int) env('FAMILY_REGISTRY_DISCOVERY_LIMIT_USER_MINUTE', 10),
        'discovery_user_hour' => (int) env('FAMILY_REGISTRY_DISCOVERY_LIMIT_USER_HOUR', 60),
        'admission_user_minute' => (int) env('FAMILY_REGISTRY_ADMISSION_LIMIT_USER_MINUTE', 5),
        'admission_user_hour' => (int) env('FAMILY_REGISTRY_ADMISSION_LIMIT_USER_HOUR', 20),
    ],

];
