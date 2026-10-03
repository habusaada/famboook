<?php

namespace App\Enums;

// docs/11 §30a — the class of an SMS delivery failure. INTERNAL ONLY: a
// Family Portal user never sees it; it goes to the operational log and, as a
// code, to auth_security_events.
enum SmsFailureOutcome: string
{
    // Worth a later try (by the user's own resend): the provider is busy or
    // unreachable before the request was made.
    case TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    // This message can never be delivered as it is (our request or the
    // destination is wrong).
    case PERMANENT_FAILURE = 'PERMANENT_FAILURE';
    // Nothing will be delivered until an operator acts: credentials, account,
    // sender, credit, or no provider configured at all.
    case PROVIDER_CONFIGURATION_FAILURE = 'PROVIDER_CONFIGURATION_FAILURE';
    // The outcome is not known — the message may or may not have been sent.
    // Never retried automatically.
    case UNKNOWN = 'UNKNOWN';
}
