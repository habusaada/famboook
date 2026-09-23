<?php

// Local development CORS configuration for the Next.js SPA (localhost:3000)
// talking to this Laravel API via Sanctum stateful cookie authentication.
// docs/06-PERMISSIONS.md §90: "CORS configuration must explicitly allow only
// approved application origins." No production domains are configured here.
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Required for Sanctum's stateful SPA cookie authentication.
    'supports_credentials' => true,

];
