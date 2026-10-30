<?php

// CORS for the Next.js SPA talking to this Laravel API via Sanctum stateful
// cookie authentication. docs/06-PERMISSIONS.md §90: "CORS configuration must
// explicitly allow only approved application origins." No production domains
// are configured here. FB-WEB-04: CORS_ALLOWED_ORIGINS is a comma-separated
// list of exact origins; unset or empty, FRONTEND_URL is the only origin. A
// wildcard is dropped: this API allows credentials.
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'dev-login', 'dev-logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) (env('CORS_ALLOWED_ORIGINS') ?: env('FRONTEND_URL', 'http://localhost:3000')))),
        fn (string $origin): bool => $origin !== '' && $origin !== '*',
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Required for Sanctum's stateful SPA cookie authentication.
    'supports_credentials' => true,

];
