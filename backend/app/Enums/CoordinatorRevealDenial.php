<?php

namespace App\Enums;

// Why a Coordinator member reveal was refused (CO-01C). Recorded as the
// reason of a DENIED security event only; the client always receives the
// same generic 404, never the reason.
enum CoordinatorRevealDenial: string
{
    // The Family code is not in the Coordinator's effective scope (or does
    // not exist): the two are never told apart.
    case FAMILY_NOT_IN_SCOPE = 'FAMILY_NOT_IN_SCOPE';
    // The reference names no ACTIVE membership of that Family.
    case MEMBER_NOT_FOUND = 'MEMBER_NOT_FOUND';
    // The membership is active but its Person is unavailable (soft-deleted).
    case MEMBER_UNAVAILABLE = 'MEMBER_UNAVAILABLE';
}
