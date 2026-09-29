<?php

namespace App\Enums;

// docs/02-DATA-DICTIONARY.md §88a "Import Staging" — lifecycle of one
// uploaded source file. Only APPLIED means rows reached canonical tables.
enum ImportBatchStatus: string
{
    case UPLOADED = 'UPLOADED';
    case VALIDATING = 'VALIDATING';
    case READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    case READY_TO_APPLY = 'READY_TO_APPLY';
    case APPLYING = 'APPLYING';
    case APPLIED = 'APPLIED';
    case FAILED = 'FAILED';
}
