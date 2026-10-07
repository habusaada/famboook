<?php

// Digital credentials (docs/11 FP-ADR-070, PWA-8.2).
return [

    // The issuance switch: when false no NEW credential is issued (Family
    // Portal lazy issuance, Staff issue and reissue). Verification of existing
    // credentials, showing an existing card and Staff revocation continue.
    'family_card_issuance_enabled' => env('FAMILY_CARD_ISSUANCE_ENABLED', true),

    // The public verification page; the QR encodes this URL plus the token.
    'verify_base_url' => rtrim(env('CREDENTIAL_VERIFY_BASE_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/verify'), '/').'/',

    // POST /api/v1/credentials/verify, per client IP.
    'verify_limits' => [
        'ip_minute' => (int) env('CREDENTIAL_VERIFY_LIMIT_IP_MINUTE', 30),
        'ip_hour' => (int) env('CREDENTIAL_VERIFY_LIMIT_IP_HOUR', 300),
    ],

    // POST /api/v1/family/card, per signed-in user.
    'family_card_limits' => [
        'user_minute' => (int) env('FAMILY_CARD_LIMIT_USER_MINUTE', 10),
    ],
];
