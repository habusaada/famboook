<?php

namespace App\Enums;

// docs/03 §89b — why a trusted mobile was revoked. Codes, never free text.
enum MobileTrustRevokeReason: string
{
    case REPORTED_LOST = 'REPORTED_LOST';
    case NOT_OWNER = 'NOT_OWNER';
    case VERIFICATION_ERROR = 'VERIFICATION_ERROR';
    case ADMINISTRATIVE = 'ADMINISTRATIVE';
}
