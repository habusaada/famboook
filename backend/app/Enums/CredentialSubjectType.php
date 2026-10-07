<?php

namespace App\Enums;

// docs/11 FP-ADR-070 — the subject a digital credential belongs to. PWA-8
// implements FAMILY only; another subject is an additive later change.
enum CredentialSubjectType: string
{
    case FAMILY = 'FAMILY';
}
