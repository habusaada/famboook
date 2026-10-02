<?php

namespace App\Enums;

// docs/03 §89b — approved mobile verification methods.
enum MobileVerificationMethod: string
{
    case IN_PERSON = 'IN_PERSON';
    case STAFF_CALLBACK = 'STAFF_CALLBACK';
    case AUTHORIZED_RECORD_REVIEW = 'AUTHORIZED_RECORD_REVIEW';
}
