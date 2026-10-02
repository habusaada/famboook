<?php

namespace App\Enums;

// docs/02 §44, §45b. VERIFIED = the identity relation is proven; ACTIVE =
// proven and enabled. V1 activation creates links directly as ACTIVE.
enum UserPersonLinkStatus: string
{
    case PENDING_VERIFICATION = 'PENDING_VERIFICATION';
    case VERIFIED = 'VERIFIED';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case ENDED = 'ENDED';
}
