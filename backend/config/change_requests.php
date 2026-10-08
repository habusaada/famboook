<?php

// Change Requests (docs/05 WF-ADR-049 … 052, PWA-5).
return [

    // Who may submit NEW family requests (PWA-6.1b, docs/11 FP-ADR-075):
    // OFF (default) | PILOT (only the Families below) | GENERAL. Read ONLY
    // through FamilySubmissionPolicy, which fails closed: missing or invalid
    // → OFF. When closed: 503 CHANGE_REQUEST_SUBMISSION_DISABLED and type
    // discovery lists nothing; history, detail, resubmission, cancellation and
    // the whole Staff workflow continue.
    'family_submission_mode' => env('CHANGE_REQUESTS_FAMILY_SUBMISSION_MODE', 'OFF'),

    // PILOT allowlist: comma-separated canonical Family database ids (e.g.
    // "12,57"). Empty = no Family; one malformed entry = no Family. Server
    // configuration only — never logged, returned or committed with real ids.
    'pilot_family_ids' => env('CHANGE_REQUESTS_PILOT_FAMILY_IDS', ''),

    // The REPLACED boolean switch (PWA-5e). Read only so that
    // famboook:change-requests-check can warn that it is still set; it opens
    // nothing — a leftover `true` never becomes GENERAL.
    'legacy_family_submission_enabled' => env('CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED'),

    // Family-side mutation limits, per signed-in user.
    'family_limits' => [
        'submit_user_minute' => (int) env('CHANGE_REQUESTS_FAMILY_SUBMIT_LIMIT_USER_MINUTE', 3),
        'submit_user_hour' => (int) env('CHANGE_REQUESTS_FAMILY_SUBMIT_LIMIT_USER_HOUR', 20),
        'action_user_minute' => (int) env('CHANGE_REQUESTS_FAMILY_ACTION_LIMIT_USER_MINUTE', 10),
    ],
];
