<?php

namespace App\Enums;

// docs/02 §45b — stored mobile trust states. NO_MOBILE and UNVERIFIED are
// derived states with no row: an imported mobile is UNVERIFIED by absence.
enum MobileTrustStatus: string
{
    case PENDING_VERIFICATION = 'PENDING_VERIFICATION';
    case TRUSTED = 'TRUSTED';
    case STALE = 'STALE';
    case REVOKED = 'REVOKED';
}
