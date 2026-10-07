<?php

namespace App\Enums;

// docs/11 FP-ADR-070 — a credential only ever moves ACTIVE → REVOKED.
enum CredentialStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
}
