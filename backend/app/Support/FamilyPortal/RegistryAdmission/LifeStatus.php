<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/**
 * The combined life status of one child (RR-02, RR-02A). PROVEN_ALIVE comes from an
 * authoritative verifier. DECLARATION_IN_SCOPE (D-06R, proposed) is NOT a proof: the
 * child is in a release its issuing authority described as living persons only at
 * extraction, so it may be admitted only on the head's explicit declaration of current
 * life, and is recorded with life status UNKNOWN. Everything else is refused.
 */
enum LifeStatus: string
{
    case PROVEN_ALIVE = 'PROVEN_ALIVE';
    case DECLARATION_IN_SCOPE = 'DECLARATION_IN_SCOPE';
    case DECEASED = 'DECEASED';
    case UNVERIFIED = 'UNVERIFIED';
}
