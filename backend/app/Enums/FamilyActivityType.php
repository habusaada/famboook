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
    case NEED_CREATED = 'NEED_CREATED';
    case NEED_UPDATED = 'NEED_UPDATED';
    case NEED_FULFILLED = 'NEED_FULFILLED';
    case NEED_CLOSED = 'NEED_CLOSED';
    case ASSISTANCE_NOMINEE_ADDED = 'ASSISTANCE_NOMINEE_ADDED';
    case ASSISTANCE_NOMINEE_REMOVED = 'ASSISTANCE_NOMINEE_REMOVED';

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

    /** @return list<self> */
    public static function needCases(): array
    {
        return [self::NEED_CREATED, self::NEED_UPDATED, self::NEED_FULFILLED, self::NEED_CLOSED];
    }

    /** @return list<self> */
    public static function assistanceCases(): array
    {
        return [self::ASSISTANCE_NOMINEE_ADDED, self::ASSISTANCE_NOMINEE_REMOVED];
    }
}
