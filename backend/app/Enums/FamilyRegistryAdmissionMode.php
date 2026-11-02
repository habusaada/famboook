<?php

namespace App\Enums;

// The direct admission switch (FB-FAMILY-ADD-01, D-04). Read only through
// FamilyRegistryAdmissionPolicy, which fails closed to OFF.
enum FamilyRegistryAdmissionMode: string
{
    case OFF = 'OFF';
    case PILOT = 'PILOT';
    case ON = 'ON';
}
