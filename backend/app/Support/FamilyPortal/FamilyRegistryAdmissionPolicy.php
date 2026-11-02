<?php

namespace App\Support\FamilyPortal;

use App\Enums\FamilyRegistryAdmissionMode;
use App\Models\Family;

/**
 * The effective direct admission mode (FB-FAMILY-ADD-01, D-04): exactly OFF, PILOT
 * or ON, and anything else is OFF. Read by the guarded discovery route
 * (ADD-WP-06B-3) through allowsFamily(): PILOT allows only the configured Family
 * ids, and an empty or unreadable allowlist allows nobody.
 */
final class FamilyRegistryAdmissionPolicy
{
    public static function mode(): FamilyRegistryAdmissionMode
    {
        $raw = config('family_registry_admission.mode');

        return is_string($raw) ? (FamilyRegistryAdmissionMode::tryFrom(strtoupper(trim($raw))) ?? FamilyRegistryAdmissionMode::OFF) : FamilyRegistryAdmissionMode::OFF;
    }

    public static function allowsFamily(Family $family): bool
    {
        return match (self::mode()) {
            FamilyRegistryAdmissionMode::OFF => false,
            FamilyRegistryAdmissionMode::ON => true,
            FamilyRegistryAdmissionMode::PILOT => in_array((int) $family->getKey(), self::pilotFamilyIds(), true),
        };
    }

    /** @return list<int> */
    private static function pilotFamilyIds(): array
    {
        $ids = config('family_registry_admission.pilot_family_ids');

        return is_array($ids) ? array_values(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) : [];
    }
}
