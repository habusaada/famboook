<?php

namespace App\Enums;

// How Staff verified that a Person whose life status was UNKNOWN is alive
// (ConfirmPersonAliveAction, docs/03 §30a). The same Staff verification
// bases as a Staff mobile-trust grant (MobileVerificationMethod), kept as
// their own enum: life status and mobile trust are separate decisions.
enum LifeStatusVerificationMethod: string
{
    case IN_PERSON = 'IN_PERSON';
    case STAFF_CALLBACK = 'STAFF_CALLBACK';
    case AUTHORIZED_RECORD_REVIEW = 'AUTHORIZED_RECORD_REVIEW';
}
