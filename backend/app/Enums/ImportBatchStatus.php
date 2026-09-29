<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §88a "Import Staging" — lifecycle of one
// uploaded source file (docs/03 §96b "Apply lifecycle").
//
// FAILED is a PRE-Apply failure only (upload / staging): it never reached
// the registry, so the same file may be uploaded again (the checksum index
// excludes FAILED). Once Apply has started a batch can only be APPLYING,
// PARTIALLY_APPLIED or APPLIED — never FAILED (DB CHECK on
// apply_started_at) — so a partly applied file stays checksum-protected.
enum ImportBatchStatus: string
{
    case UPLOADED = 'UPLOADED';
    case VALIDATING = 'VALIDATING';
    case READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    case READY_TO_APPLY = 'READY_TO_APPLY';
    case APPLYING = 'APPLYING';
    // Some complete row transactions committed, a later row failed; resumable.
    case PARTIALLY_APPLIED = 'PARTIALLY_APPLIED';
    case APPLIED = 'APPLIED';
    case FAILED = 'FAILED';

    /** States reachable only after Apply started (apply_started_at is set). */
    public const APPLY_STARTED = [self::APPLYING, self::PARTIALLY_APPLIED, self::APPLIED];

    public function applyStarted(): bool
    {
        return in_array($this, self::APPLY_STARTED, true);
    }
}
