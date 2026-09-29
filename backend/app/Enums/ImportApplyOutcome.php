<?php

namespace App\Enums;

// docs/03 §96b "Apply provenance" — what a future Apply did for one intended
// effect of one import row. Only CREATED / REUSED reference a registry entity.
enum ImportApplyOutcome: string
{
    // A new registry entity was created by this import row.
    case CREATED = 'CREATED';
    // An existing registry entity (exact National ID) was used, not changed.
    case REUSED = 'REUSED';
    // Deliberately not created (e.g. a conflicting SPOUSE membership); the
    // source relationship is kept here as evidence with a reason code.
    case OMITTED = 'OMITTED';
    // The effect could not be applied safely; the row needs review.
    case BLOCKED = 'BLOCKED';

    public function referencesEntity(): bool
    {
        return $this === self::CREATED || $this === self::REUSED;
    }
}
