<?php

namespace App\Enums;

// docs/03 §96b "Apply planner" — what a future Apply INTENDS to do for one
// effect. Planning state only: it is never stored. On execution it becomes
// provenance (import_apply_records.outcome): CREATE → CREATED, REUSE → REUSED,
// OMIT → OMITTED; a BLOCK effect makes its row non-executable.
enum ImportApplyIntent: string
{
    case CREATE = 'CREATE';
    case REUSE = 'REUSE';
    case OMIT = 'OMIT';
    case BLOCK = 'BLOCK';

    /** The provenance outcome execution will record (BLOCK is never executed). */
    public function outcome(): ?ImportApplyOutcome
    {
        return match ($this) {
            self::CREATE => ImportApplyOutcome::CREATED,
            self::REUSE => ImportApplyOutcome::REUSED,
            self::OMIT => ImportApplyOutcome::OMITTED,
            self::BLOCK => null,
        };
    }
}
