<?php

namespace App\Support\FamilyPortal;

use App\Enums\FamilyRegistryAdmissionMode;
use App\Models\Family;
use App\Models\User;
use App\Support\FamilyAuth\FamilyAccessResult;

/**
 * The effective direct admission mode (FB-FAMILY-ADD-01, D-04): exactly OFF, PILOT
 * or ON, and anything else is OFF. Read by the guarded discovery route
 * (ADD-WP-06B-3) through allowsFamily(): PILOT allows only the configured Family
 * ids, and an empty or unreadable allowlist allows nobody.
 *
 * allowsAccount() is the ONE account check of the feature: the discovery and
 * admission gates refuse with it, and GET /api/v1/family/me reports it as the
 * visibility-only `registry_admission` (RR-01). It reads no registry and
 * records, counts or exposes nothing.
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

    /**
     * True only for an account with a household context (an eligible head), the
     * family portal permission, a Family the flag allows, and
     * family-member.admit-from-registry (held by no role).
     */
    public static function allowsAccount(User $user, ?FamilyAccessResult $context): bool
    {
        return $context !== null && $context->hasFamilyContext()
            && $user->can('family-portal.access')
            && self::allowsFamily($context->family)
            && $user->can('family-member.admit-from-registry');
    }

    /** @return list<int> */
    private static function pilotFamilyIds(): array
    {
        $ids = config('family_registry_admission.pilot_family_ids');

        return is_array($ids) ? array_values(array_filter($ids, fn ($id) => is_int($id) && $id > 0)) : [];
    }
}
