<?php

namespace App\Enums;

// docs/03 §89b — approved mobile verification methods.
enum MobileVerificationMethod: string
{
    // Staff grants (GrantPersonMobileTrustAction).
    case IN_PERSON = 'IN_PERSON';
    case STAFF_CALLBACK = 'STAFF_CALLBACK';
    case AUTHORIZED_RECORD_REVIEW = 'AUTHORIZED_RECORD_REVIEW';
    // First self-activation (FP-ADR-053): a successful OTP sent to the
    // Person's current registered mobile. Never a Staff grant method.
    case SELF_OTP = 'SELF_OTP';
    // CONTACT_UPDATE of the head's own number (FP-ADR-079): the current
    // password, an OTP to the old TRUSTED number and an OTP to the new one.
    // Never a Staff grant method.
    case SELF_OTP_TRANSFER = 'SELF_OTP_TRANSFER';

    /** @return list<self> the methods a Staff grant may use */
    public static function staffMethods(): array
    {
        return [self::IN_PERSON, self::STAFF_CALLBACK, self::AUTHORIZED_RECORD_REVIEW];
    }
}
