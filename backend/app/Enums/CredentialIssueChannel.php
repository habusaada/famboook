<?php

namespace App\Enums;

// docs/11 FP-ADR-070 — how a Family credential was issued (activity
// metadata only): lazily for the eligible head in the Family Portal, or by
// Staff.
enum CredentialIssueChannel: string
{
    case FAMILY_PORTAL = 'FAMILY_PORTAL';
    case STAFF = 'STAFF';
}
