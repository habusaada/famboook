<?php

namespace App\Enums;

// docs/11 §30a — why Coordinator Space is not available to an account.
// INTERNAL ONLY: never sent to a client.
enum CoordinatorAccessDenial: string
{
    // The account's OWN household context failed (FamilyAccessResolver).
    case NO_FAMILY_CONTEXT = 'NO_FAMILY_CONTEXT';
    // The account does not hold the COORDINATOR role.
    case NOT_COORDINATOR = 'NOT_COORDINATOR';
    // The account does not hold coordinator-space.access.
    case NO_SPACE_PERMISSION = 'NO_SPACE_PERMISSION';
    // No active assignment whose hierarchy target is still active.
    case NO_EFFECTIVE_SCOPE = 'NO_EFFECTIVE_SCOPE';
}
