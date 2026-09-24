<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §22 "Person Health Record" — V1 record types.
enum HealthRecordType: string
{
    case DISABILITY = 'DISABILITY';
    case CHRONIC_DISEASE = 'CHRONIC_DISEASE';
    case PREGNANCY = 'PREGNANCY';
    case BREASTFEEDING = 'BREASTFEEDING';

    /** Pregnancy/breastfeeding: FEMALE only, at most one active per Person. */
    public function isMaternal(): bool
    {
        return $this === self::PREGNANCY || $this === self::BREASTFEEDING;
    }
}
