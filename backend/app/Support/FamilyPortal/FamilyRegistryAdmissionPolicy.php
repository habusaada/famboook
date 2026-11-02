<?php

namespace App\Support\FamilyPortal;

use App\Enums\FamilyRegistryAdmissionMode;

/**
 * The effective direct admission mode (FB-FAMILY-ADD-01, D-04): exactly OFF, PILOT
 * or ON, and anything else is OFF. Foundation only: no route or workflow reads it
 * yet. The PILOT allowlist comes with the work package that first reads the mode.
 */
final class FamilyRegistryAdmissionPolicy
{
    public static function mode(): FamilyRegistryAdmissionMode
    {
        $raw = config('family_registry_admission.mode');

        return is_string($raw) ? (FamilyRegistryAdmissionMode::tryFrom(strtoupper(trim($raw))) ?? FamilyRegistryAdmissionMode::OFF) : FamilyRegistryAdmissionMode::OFF;
    }
}
