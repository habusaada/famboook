<?php

namespace App\Enums;

// docs/03 §96a — RESERVED for the future reconciliation phase: how a staged
// source row relates to the canonical registry. Not assigned yet (NULL = not
// reconciled). A National ID match is a candidate identity, never permission
// to overwrite; Person and Family matching are separate.
enum ImportReconciliationStatus: string
{
    case NEW = 'NEW';
    case UNCHANGED = 'UNCHANGED';
    case CHANGED = 'CHANGED';
    case DUPLICATE_IN_FILE = 'DUPLICATE_IN_FILE';
    case CONFLICT = 'CONFLICT';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';
}
