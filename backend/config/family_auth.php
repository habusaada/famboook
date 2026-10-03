<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Family Portal keyed fingerprints (docs/11 §30a, docs/04 §55b)
    |--------------------------------------------------------------------------
    |
    | A dedicated secret for the Family Portal login key, the mobile trust
    | fingerprint and OTP code hashes. It is NOT APP_KEY and there is no
    | fallback to it: with no key the Staff application runs normally and
    | every fingerprint operation fails closed (App\Support\FamilyAuth\
    | KeyedFingerprint). At least 32 bytes; "base64:" prefixed values are
    | decoded. The previous key/version exist only for a key rotation.
    |
    */

    'fingerprint' => [
        'key' => env('FAMILY_AUTH_FINGERPRINT_KEY'),
        'key_version' => (int) env('FAMILY_AUTH_FINGERPRINT_KEY_VERSION', 1),
        'previous_key' => env('FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY'),
        'previous_key_version' => env('FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY_VERSION') === null
            ? null
            : (int) env('FAMILY_AUTH_FINGERPRINT_PREVIOUS_KEY_VERSION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approved policy values (docs/03 §89b)
    |--------------------------------------------------------------------------
    |
    | Product decisions, not deployment settings. The OTP values are read by
    | App\Support\FamilyAuth\OtpChallenges; passwords arrive in PWA-1F.
    |
    */

    'otp' => [
        'digits' => 6,
        'ttl_seconds' => 300,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'max_sends' => 3,
        // After a correct code: how long the verified grant may be consumed.
        'grant_ttl_seconds' => 600,
    ],

    'password_min_length' => 8,

    // bcrypt ignores input past 72 bytes: a longer password is refused, never
    // truncated. Bytes of UTF-8, not characters. Not a tunable.
    'password_max_bytes' => 72,

    /*
    |--------------------------------------------------------------------------
    | SMS delivery (docs/11 §30a, docs/08 §16a)
    |--------------------------------------------------------------------------
    |
    | driver: empty (the default) = nothing can be delivered
    | (UnconfiguredSmsSender). `tweetsms` = the Production provider, TweetsMS
    | (App\Support\Sms\TweetsSmsSender); it sends only with an API key AND a
    | sender, both SECRETS of the server .env, never of the repository. `log`
    | is a LOCAL / TESTING development driver only: it writes to its own file
    | and refuses to run in any other environment.
    |
    */

    'sms' => [
        'driver' => env('FAMILY_SMS_DRIVER'),
        'log_path' => storage_path('logs/family-sms-dev.log'),
        'tweetsms' => [
            'api_key' => env('TWEETSMS_API_KEY'),
            'sender' => env('TWEETSMS_SENDER'),
            'endpoint' => env('TWEETSMS_ENDPOINT', 'https://www.tweetsms.ps/api.php/office/sendsms'),
            'connect_timeout' => (int) env('TWEETSMS_CONNECT_TIMEOUT', 3),
            'timeout' => (int) env('TWEETSMS_TIMEOUT', 8),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP SMS abuse ceilings (docs/11 §30a) — App\Support\FamilyAuth\OtpThrottle
    |--------------------------------------------------------------------------
    |
    | Sends allowed across ALL challenges, checked before every SMS (failed
    | deliveries count). Security settings, environment-overridable; the
    | per-challenge rules (cooldown, 3 sends, 5 attempts) are the `otp` values
    | above. A ceiling below 1 blocks every send. The destination ceilings are
    | higher than the person ones because several household heads may share
    | one phone.
    |
    */

    'throttle' => [
        'person' => [
            'hour' => (int) env('FAMILY_OTP_THROTTLE_PERSON_HOUR', 5),
            'day' => (int) env('FAMILY_OTP_THROTTLE_PERSON_DAY', 10),
        ],
        'destination' => [
            'hour' => (int) env('FAMILY_OTP_THROTTLE_DESTINATION_HOUR', 10),
            'day' => (int) env('FAMILY_OTP_THROTTLE_DESTINATION_DAY', 20),
        ],
        'ip' => [
            'hour' => (int) env('FAMILY_OTP_THROTTLE_IP_HOUR', 20),
        ],
        'global' => [
            'hour' => (int) env('FAMILY_OTP_THROTTLE_GLOBAL_HOUR', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (docs/11 §30a)
    |--------------------------------------------------------------------------
    |
    | Finished OTP challenges are purged by `famboook:purge-otp-challenges`
    | (scheduled daily; the scheduler cron is a deployment prerequisite).
    | auth_security_events are retained 24 months; their purge is not
    | implemented yet, and nothing here deletes them.
    |
    */

    'retention' => [
        'otp_challenge_days' => (int) env('FAMILY_OTP_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Family Portal activation gate (docs/08 §16a)
    |--------------------------------------------------------------------------
    |
    | Off by default; read by App\Http\Middleware\EnsureActivationEnabled on
    | the four public activation endpoints, which answer 503 while it is off.
    | It must stay false in Production until the SMS provider, the queue
    | decision, delivery failure handling, secure credentials, the scheduler
    | and the Family login (PWA-1G) exist.
    |
    */

    'activation_enabled' => (bool) env('FAMILY_ACTIVATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Family login gate and lockout (docs/11 §30a, docs/08 §16a)
    |--------------------------------------------------------------------------
    |
    | login_enabled: off by default, independent of the other two gates; read
    | by App\Http\Middleware\EnsureFamilyLoginEnabled (503 while off).
    | Deploying the code enables nothing.
    |
    | limits (App\Support\FamilyAuth\FamilyLogin), all over decay_seconds:
    |   ip_attempts              every attempt from one IP (route limiter)
    |   identifier_ip_failures   FAILED attempts for one identifier from one IP
    |   identifier_failures      FAILED attempts for one identifier, any IP
    | The identifier is its keyed fingerprint and the IP a digest. A
    | successful login clears the two identifier counters.
    |
    */

    'login_enabled' => (bool) env('FAMILY_LOGIN_ENABLED', false),

    'login' => [
        'limits' => [
            'decay_seconds' => (int) env('FAMILY_LOGIN_LIMIT_DECAY_SECONDS', 900),
            'ip_attempts' => (int) env('FAMILY_LOGIN_LIMIT_IP_ATTEMPTS', 20),
            'identifier_ip_failures' => (int) env('FAMILY_LOGIN_LIMIT_IDENTIFIER_IP_FAILURES', 5),
            'identifier_failures' => (int) env('FAMILY_LOGIN_LIMIT_IDENTIFIER_FAILURES', 20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Family password reset gate and request ceilings (docs/11 §30a)
    |--------------------------------------------------------------------------
    |
    | password_reset_enabled: off by default, independent of the other two
    | gates; read by App\Http\Middleware\EnsurePasswordResetEnabled (503 while
    | off). A reset sends an SMS: it must stay false in Production until SMS
    | delivery is Production-ready.
    |
    | limits: the same pattern as activation, with their own counters. They
    | complement the OTP SMS ceilings; they do not replace them. The response
    | floor of every step is activation.min_response_ms.
    |
    */

    'password_reset_enabled' => (bool) env('FAMILY_PASSWORD_RESET_ENABLED', false),

    'password_reset' => [
        'limits' => [
            'start_ip_minute' => (int) env('FAMILY_PASSWORD_RESET_LIMIT_START_IP_MINUTE', 10),
            'start_ip_hour' => (int) env('FAMILY_PASSWORD_RESET_LIMIT_START_IP_HOUR', 30),
            'start_identifier_hour' => (int) env('FAMILY_PASSWORD_RESET_LIMIT_START_IDENTIFIER_HOUR', 5),
            'verify_ip_minute' => (int) env('FAMILY_PASSWORD_RESET_LIMIT_VERIFY_IP_MINUTE', 30),
            'resend_ip_minute' => (int) env('FAMILY_PASSWORD_RESET_LIMIT_RESEND_IP_MINUTE', 10),
            'complete_ip_minute' => (int) env('FAMILY_PASSWORD_RESET_LIMIT_COMPLETE_IP_MINUTE', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Activation abuse controls (docs/11 §30a)
    |--------------------------------------------------------------------------
    |
    | min_response_ms: the least time an activation or password reset start,
    | verify, resend or complete takes (one shared value), so a real
    | challenge (lookups, locked transaction, events) and a decoy answer
    | alike. The SMS is sent after the response and does not count. 400 is a
    | DEVELOPMENT default — the Production value is set from measurements on
    | Production-like infrastructure (docs/08 §16a). 0 = off.
    |
    | limits: request ceilings of the public endpoints, per IP and — for
    | start — per identifier (its keyed fingerprint, never the National ID).
    | They complement the OTP SMS ceilings above; they do not replace them.
    |
    */

    'activation' => [
        'min_response_ms' => (int) env('FAMILY_ACTIVATION_MIN_RESPONSE_MS', 400),
        'limits' => [
            'start_ip_minute' => (int) env('FAMILY_ACTIVATION_LIMIT_START_IP_MINUTE', 10),
            'start_ip_hour' => (int) env('FAMILY_ACTIVATION_LIMIT_START_IP_HOUR', 30),
            'start_identifier_hour' => (int) env('FAMILY_ACTIVATION_LIMIT_START_IDENTIFIER_HOUR', 5),
            'send_ip_minute' => (int) env('FAMILY_ACTIVATION_LIMIT_SEND_IP_MINUTE', 10),
            'verify_ip_minute' => (int) env('FAMILY_ACTIVATION_LIMIT_VERIFY_IP_MINUTE', 30),
            'resend_ip_minute' => (int) env('FAMILY_ACTIVATION_LIMIT_RESEND_IP_MINUTE', 10),
            'complete_ip_minute' => (int) env('FAMILY_ACTIVATION_LIMIT_COMPLETE_IP_MINUTE', 10),
        ],
    ],

];
