<?php

namespace App\Enums;

// The household head's own sensitive values that the self reveal may return
// (docs/11 §23a, FP-ADR-062, PWA-3B.2). The request names one of these codes;
// the persons column is decided here, server-side, never from the input.
enum SelfRevealField: string
{
    case NATIONAL_ID = 'NATIONAL_ID';
    case MOBILE = 'MOBILE';
    case ALTERNATE_MOBILE = 'ALTERNATE_MOBILE';

    /** The persons column behind the code: a fixed mapping. */
    public function column(): string
    {
        return match ($this) {
            self::NATIONAL_ID => 'national_id',
            self::MOBILE => 'mobile',
            self::ALTERNATE_MOBILE => 'alternate_mobile',
        };
    }
}
