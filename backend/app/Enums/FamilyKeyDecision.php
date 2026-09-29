<?php

namespace App\Enums;

// docs/03 §96a — an administrator's explicit decision for one distinct
// source family key of an import batch. No decision = UNRESOLVED, which is
// NOT the same as NO_BRANCH (an explicit decision to assign no Branch).
enum FamilyKeyDecision: string
{
    case MATCH_EXISTING_BRANCH = 'MATCH_EXISTING_BRANCH';
    case CREATE_NEW_BRANCH = 'CREATE_NEW_BRANCH';
    case SAME_BRANCH_AS_KEY = 'SAME_BRANCH_AS_KEY';
    case NO_BRANCH = 'NO_BRANCH';
}
