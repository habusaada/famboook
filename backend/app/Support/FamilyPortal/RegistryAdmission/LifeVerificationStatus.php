<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/** What an authoritative life source states (RR-02). UNKNOWN is never alive. */
enum LifeVerificationStatus: string
{
    case ALIVE = 'ALIVE';
    case DECEASED = 'DECEASED';
    case UNKNOWN = 'UNKNOWN';
}
