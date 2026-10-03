<?php

namespace App\Enums;

// docs/11 §30a — why an SMS was not delivered, as a safe code. INTERNAL ONLY.
// Each reason belongs to exactly one SmsFailureOutcome.
enum SmsFailureReason: string
{
    // No provider, or an incomplete provider configuration.
    case NOT_CONFIGURED = 'NOT_CONFIGURED';
    // The development log driver outside local / testing.
    case UNSAFE_ENVIRONMENT = 'UNSAFE_ENVIRONMENT';
    // The development log file could not be written.
    case LOCAL_WRITE_FAILED = 'LOCAL_WRITE_FAILED';

    // TweetsMS application result codes.
    case PROVIDER_BUSY = 'PROVIDER_BUSY';                       // -126
    case INSUFFICIENT_CREDIT = 'INSUFFICIENT_CREDIT';           // -124
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';           // -110
    case ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';                 // -111
    case ACCOUNT_BLOCKED = 'ACCOUNT_BLOCKED';                   // -112
    case SENDING_STOPPED = 'SENDING_STOPPED';                   // -114
    case INVALID_SENDER = 'INVALID_SENDER';                     // -115, -116
    case MISSING_PARAMETERS = 'MISSING_PARAMETERS';             // -100
    case INVALID_DESTINATION = 'INVALID_DESTINATION';           // -120, or refused before sending
    case UNRECOGNIZED_RESULT = 'UNRECOGNIZED_RESULT';           // any other code

    // Transport and response shape.
    case MALFORMED_RESPONSE = 'MALFORMED_RESPONSE';
    case HTTP_CLIENT_ERROR = 'HTTP_CLIENT_ERROR';
    case HTTP_SERVER_ERROR = 'HTTP_SERVER_ERROR';
    case UNEXPECTED_HTTP_STATUS = 'UNEXPECTED_HTTP_STATUS';
    case CONNECTION_FAILED = 'CONNECTION_FAILED';
    case TRANSPORT_UNCERTAIN = 'TRANSPORT_UNCERTAIN';

    // Anything else a sender threw.
    case UNEXPECTED_ERROR = 'UNEXPECTED_ERROR';
}
