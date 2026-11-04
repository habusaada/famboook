<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/** The combined life status of one child (RR-02): only PROVEN_ALIVE can be admitted. */
enum LifeStatus: string
{
    case PROVEN_ALIVE = 'PROVEN_ALIVE';
    case DECEASED = 'DECEASED';
    case UNVERIFIED = 'UNVERIFIED';
}
