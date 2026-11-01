<?php

namespace App\Enums;

// What the registry holds for a National ID proposed as a new family member
// (App\Support\FamilyMemberIdentity). Internal: a family is never told which.
enum FamilyMemberIdentityOutcome: string
{
    // No non-deleted holder: a new Person would be created, and
    // CreatePersonAction still refuses a value held by a soft-deleted Person.
    case NEW_PERSON = 'NEW_PERSON';
    // Exactly one holder, ALIVE, active and with no active membership.
    case ATTACHABLE = 'ATTACHABLE';
    // More than one equivalent holder (ambiguous legacy data).
    case AMBIGUOUS = 'AMBIGUOUS';
    // The holder has an active membership anywhere: that would be a transfer.
    case ACTIVE_MEMBERSHIP = 'ACTIVE_MEMBERSHIP';
    // The holder is DECEASED, of UNKNOWN life status, inactive or gone.
    case NOT_ATTACHABLE = 'NOT_ATTACHABLE';

    public function refused(): bool
    {
        return ! in_array($this, [self::NEW_PERSON, self::ATTACHABLE], true);
    }
}
