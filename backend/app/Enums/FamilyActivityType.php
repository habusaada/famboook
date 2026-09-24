<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §61a "Family Activity" — the canonical V1
// event codes. Arabic wording is presentation and lives in the frontend.
enum FamilyActivityType: string
{
    case FAMILY_CREATED = 'FAMILY_CREATED';
    case FAMILY_UPDATED = 'FAMILY_UPDATED';
    case FAMILY_MEMBER_ADDED = 'FAMILY_MEMBER_ADDED';
    case PERSON_UPDATED = 'PERSON_UPDATED';
    case RESIDENCE_UPDATED = 'RESIDENCE_UPDATED';
    case DISPLACEMENT_UPDATED = 'DISPLACEMENT_UPDATED';
    case HEALTH_RECORD_CREATED = 'HEALTH_RECORD_CREATED';
    case HEALTH_RECORD_UPDATED = 'HEALTH_RECORD_UPDATED';
    case HEALTH_RECORD_CLOSED = 'HEALTH_RECORD_CLOSED';
    case ASSESSMENT_CREATED = 'ASSESSMENT_CREATED';
    case ASSESSMENT_UPDATED = 'ASSESSMENT_UPDATED';
    case ASSESSMENT_COMPLETED = 'ASSESSMENT_COMPLETED';

    /** @return list<self> */
    public static function healthCases(): array
    {
        return [self::HEALTH_RECORD_CREATED, self::HEALTH_RECORD_UPDATED, self::HEALTH_RECORD_CLOSED];
    }

    /** @return list<self> */
    public static function assessmentCases(): array
    {
        return [self::ASSESSMENT_CREATED, self::ASSESSMENT_UPDATED, self::ASSESSMENT_COMPLETED];
    }
}
