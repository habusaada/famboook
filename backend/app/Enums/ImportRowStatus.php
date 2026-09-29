<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §88a "Import Staging" — state of one staged
// source row. FLAGGED needs human review; only APPLIED rows link a Family.
enum ImportRowStatus: string
{
    case PENDING = 'PENDING';
    case VALID = 'VALID';
    case FLAGGED = 'FLAGGED';
    case REJECTED = 'REJECTED';
    case APPLIED = 'APPLIED';
    case SKIPPED = 'SKIPPED';
}
