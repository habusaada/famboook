<?php

namespace App\Enums;

// Who may submit NEW Coordinator Change Requests (CO-02.3b; docs/06
// AUTH-ADR-093). Read only through CoordinatorSubmissionPolicy, which fails
// closed to OFF. Independent of the family channel (FamilySubmissionMode).
enum CoordinatorSubmissionMode: string
{
    // Nobody: the default and the emergency state.
    case OFF = 'OFF';

    // Only the Coordinator accounts on the configured pilot allowlist.
    case PILOT = 'PILOT';

    // Every otherwise eligible Coordinator.
    case GENERAL = 'GENERAL';
}
