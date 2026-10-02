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
    | Product decisions, not deployment settings. Nothing reads them yet:
    | OTP challenges arrive in PWA-1E and passwords in PWA-1F.
    |
    */

    'otp' => [
        'digits' => 6,
        'ttl_seconds' => 300,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
        'max_sends' => 3,
    ],

    'password_min_length' => 8,

    /*
    |--------------------------------------------------------------------------
    | SMS delivery (docs/11 §30a, docs/08 §16a)
    |--------------------------------------------------------------------------
    |
    | No provider is chosen. With no driver nothing can be delivered
    | (UnconfiguredSmsSender), so deploying this code does not make Production
    | SMS-ready. `log` is a LOCAL / TESTING development driver only: it writes
    | to its own file and refuses to run in any other environment.
    |
    */

    'sms' => [
        'driver' => env('FAMILY_SMS_DRIVER'),
        'log_path' => storage_path('logs/family-sms-dev.log'),
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
    | Family Portal activation gate (docs/08 §16a)
    |--------------------------------------------------------------------------
    |
    | Off by default. Nothing reads it yet (activation is PWA-1F). It must stay
    | false in Production until the SMS provider, the queue worker, delivery
    | failure handling and secure credentials exist.
    |
    */

    'activation_enabled' => (bool) env('FAMILY_ACTIVATION_ENABLED', false),

];
