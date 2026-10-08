<?php

namespace App\Enums;

// Who may submit NEW family Change Requests (PWA-6.1b, docs/11 FP-ADR-075).
// Read only through FamilySubmissionPolicy, which fails closed to OFF.
enum FamilySubmissionMode: string
{
    // Nobody: the default and the emergency state.
    case OFF = 'OFF';

    // Only the Families on the configured pilot allowlist.
    case PILOT = 'PILOT';

    // Every otherwise eligible household head.
    case GENERAL = 'GENERAL';
}
