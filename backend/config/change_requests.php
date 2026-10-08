<?php

// Change Requests (docs/05 WF-ADR-049 … 052, PWA-5).
return [

    // The family submission switch (PWA-5e, AE-13). When false — the default
    // — no NEW family request can be submitted (CHANGE_REQUEST_SUBMISSION_
    // DISABLED, 503) and type discovery lists nothing. History, detail,
    // resubmission and cancellation of existing requests, and the whole Staff
    // review workflow, continue. Only a strict boolean true opens it.
    'family_submission_enabled' => env('CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED', false),

    // Family-side mutation limits, per signed-in user.
    'family_limits' => [
        'submit_user_minute' => (int) env('CHANGE_REQUESTS_FAMILY_SUBMIT_LIMIT_USER_MINUTE', 3),
        'submit_user_hour' => (int) env('CHANGE_REQUESTS_FAMILY_SUBMIT_LIMIT_USER_HOUR', 20),
        'action_user_minute' => (int) env('CHANGE_REQUESTS_FAMILY_ACTION_LIMIT_USER_MINUTE', 10),
    ],
];
