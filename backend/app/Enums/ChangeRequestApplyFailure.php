<?php

namespace App\Enums;

// Why an APPLY attempt did not change the registry (PWA-5b, docs/04 §43). The
// request stays APPROVED and an APPLY_FAILED workflow event records this code
// as its reason — never an exception message, payload or registry value.
//
// The first three are refusals: the request can no longer be applied as
// approved, which alone allows APPROVED → REJECTED (NO_LONGER_APPLICABLE,
// AE-4). APPLY_FAILED is an unexpected system failure: retry instead.
enum ChangeRequestApplyFailure: string
{
    case BASE_CHANGED = 'BASE_CHANGED';
    case PRECONDITION_FAILED = 'PRECONDITION_FAILED';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case APPLY_FAILED = 'APPLY_FAILED';

    /** The request can no longer satisfy its canonical preconditions. */
    public function isRefusal(): bool
    {
        return $this !== self::APPLY_FAILED;
    }
}
