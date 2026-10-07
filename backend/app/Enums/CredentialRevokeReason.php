<?php

namespace App\Enums;

// docs/11 FP-ADR-070 — why a credential was revoked. REISSUED is set only by
// the reissue action; Staff choose ADMINISTRATIVE or COMPROMISED.
enum CredentialRevokeReason: string
{
    case REISSUED = 'REISSUED';
    case ADMINISTRATIVE = 'ADMINISTRATIVE';
    case COMPROMISED = 'COMPROMISED';

    /** @return list<self> the reasons a Staff revocation may use */
    public static function staffReasons(): array
    {
        return [self::ADMINISTRATIVE, self::COMPROMISED];
    }
}
