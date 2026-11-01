<?php

namespace App\Support\ChangeRequests;

use App\Enums\HouseholdReconciliationMode;

/**
 * The effective household reconciliation mode (FB-FAMILY-RECON-01): exactly OFF,
 * PILOT or ON, and anything else is OFF. Foundation only: no route or workflow
 * reads it yet. What PILOT admits is decided with the submission work package.
 */
final class HouseholdReconciliationPolicy
{
    public static function mode(): HouseholdReconciliationMode
    {
        $raw = config('household_reconciliation.mode');

        return is_string($raw) ? (HouseholdReconciliationMode::tryFrom(strtoupper(trim($raw))) ?? HouseholdReconciliationMode::OFF) : HouseholdReconciliationMode::OFF;
    }
}
