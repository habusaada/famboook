<?php

namespace App\Enums;

// What the civil registry proves about a person's life (FB-FAMILY-ADD-01 ADD-WP-05,
// D-06). There is deliberately no ALIVE case: until gate G-05 approves a source of
// proof of life, nothing in the registry can prove it.
enum LifeEvidence: string
{
    // A valid death date.
    case DECEASED = 'DECEASED';
    // A death date that is present but not a valid date.
    case INDETERMINATE = 'INDETERMINATE';
    // No usable evidence either way: an empty death date is not proof of life.
    case UNVERIFIED = 'UNVERIFIED';
}
