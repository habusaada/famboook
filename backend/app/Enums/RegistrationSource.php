<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §7 "registration_source" — example values.
enum RegistrationSource: string
{
    case PAPER_FORM = 'PAPER_FORM';
    case MANUAL_ENTRY = 'MANUAL_ENTRY';
    case IMPORT = 'IMPORT';
    case VERIFIED_SOURCE = 'VERIFIED_SOURCE';
    // CO-02 (docs/02 §7 amended): set ONLY by the Staff APPLY of an approved
    // REGISTER_FAMILY request; never selectable by Staff or a household.
    case COORDINATOR_REQUEST = 'COORDINATOR_REQUEST';

    /** @return list<self> the sources a person may choose when entering data */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $source) => $source !== self::COORDINATOR_REQUEST));
    }
}
