<?php

namespace App\Enums;

// The household reconciliation switch (FB-FAMILY-RECON-01). Read only through
// HouseholdReconciliationPolicy, which fails closed to OFF.
enum HouseholdReconciliationMode: string
{
    case OFF = 'OFF';
    case PILOT = 'PILOT';
    case ON = 'ON';
}
