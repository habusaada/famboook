<?php

namespace App\Enums;

// Why a Change Request was rejected (PWA-5a, WF-ADR-049). A controlled code;
// the optional family-visible message is separate free text, and internal
// notes stay on the workflow event. NO_LONGER_APPLICABLE is the only reason
// for rejecting an APPROVED request, after a refused apply (AE-4).
enum ChangeRequestRejectionReason: string
{
    case INSUFFICIENT_INFORMATION = 'INSUFFICIENT_INFORMATION';
    case CANNOT_VERIFY = 'CANNOT_VERIFY';
    case DATA_ALREADY_CORRECT = 'DATA_ALREADY_CORRECT';
    case DUPLICATE_REQUEST = 'DUPLICATE_REQUEST';
    case DATA_CHANGED = 'DATA_CHANGED';
    case NO_LONGER_APPLICABLE = 'NO_LONGER_APPLICABLE';
    case OTHER = 'OTHER';
}
