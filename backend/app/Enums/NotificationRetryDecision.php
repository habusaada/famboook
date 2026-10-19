<?php

namespace App\Enums;

// The answer of NotificationRetryPolicy (FB-NOTIFY-01B.2b.5b): ALLOWED, or the
// stable reason a retry may not happen now. Codes only — never shown to
// families.
enum NotificationRetryDecision: string
{
    case ALLOWED = 'ALLOWED';

    // The message is not PUBLISH_FAILED.
    case NOT_FAILED = 'NOT_FAILED';

    // The retry configuration is invalid: fail closed.
    case CONFIG_INVALID = 'CONFIG_INVALID';

    // The stored failure code is not a known NotificationFailureCode.
    case UNKNOWN_FAILURE = 'UNKNOWN_FAILURE';

    case PERMANENT_FAILURE = 'PERMANENT_FAILURE';

    // Automatic only: a manual-only failure waits for the sender.
    case MANUAL_ONLY_FAILURE = 'MANUAL_ONLY_FAILURE';

    // Automatic only: the automatic attempt budget is used up.
    case AUTOMATIC_ATTEMPTS_EXHAUSTED = 'AUTOMATIC_ATTEMPTS_EXHAUSTED';

    // Manual only: a transient failure still has automatic attempts left.
    case AUTOMATIC_RETRY_PENDING = 'AUTOMATIC_RETRY_PENDING';

    // Manual only: the manual allowance is used up.
    case MANUAL_RETRIES_EXHAUSTED = 'MANUAL_RETRIES_EXHAUSTED';

    // No worker claim is left (publish_attempts at the total limit).
    case CLAIM_LIMIT_REACHED = 'CLAIM_LIMIT_REACHED';

    // expires_at is reached or less than the minimum lead ahead (R2).
    case EXPIRY_TOO_SOON = 'EXPIRY_TOO_SOON';

    // Automatic only: the backoff after the last failure has not elapsed.
    case BACKOFF_PENDING = 'BACKOFF_PENDING';

    public function allowed(): bool
    {
        return $this === self::ALLOWED;
    }
}
