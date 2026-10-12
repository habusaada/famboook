<?php

namespace App\Enums;

// The sensitive values a Coordinator may reveal for ONE member of a Family in
// scope (CO-01C, docs/06 AUTH-ADR-092): the National ID and the primary
// mobile — never the alternate mobile or anything else. The request names a
// code; the persons column is decided here, server-side, never from input.
enum CoordinatorRevealField: string
{
    case NATIONAL_ID = 'NATIONAL_ID';
    case MOBILE = 'MOBILE';

    /** The persons column behind the code: a fixed mapping. */
    public function column(): string
    {
        return match ($this) {
            self::NATIONAL_ID => 'national_id',
            self::MOBILE => 'mobile',
        };
    }
}
