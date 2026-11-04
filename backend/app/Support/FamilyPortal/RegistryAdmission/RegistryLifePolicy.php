<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use DateTimeImmutable;

/**
 * The life policy of FB-FAMILY-ADD-01 (RR-02A; D-06 and its PROPOSED revision D-06R).
 * Fails closed: anything but a complete, exact configuration is VERIFIER_ONLY.
 *
 * REGISTRY_SCOPE_AND_DECLARATION is active only when ALL hold:
 * - `life_policy.mode` is exactly REGISTRY_SCOPE_AND_DECLARATION;
 * - `life_policy.approval_reference` names the recorded decision
 *   ([A-Za-z0-9._:/-], 3 … 80 characters);
 * - the active, approved declaration carries a `life_text` (the head's separate
 *   statement of current life).
 *
 * A release counts only when `living_scope_releases` lists it with its extraction date.
 * A single malformed entry, a duplicate, an impossible date or a date in the future lists
 * nothing. The scope is what the issuing authority stated at extraction; it is NEVER a
 * proof of current life.
 */
final class RegistryLifePolicy
{
    public const VERIFIER_ONLY = 'VERIFIER_ONLY';

    public const SCOPE_AND_DECLARATION = 'REGISTRY_SCOPE_AND_DECLARATION';

    public static function scopeAndDeclarationActive(): bool
    {
        return config('family_registry_admission.life_policy.mode') === self::SCOPE_AND_DECLARATION
            && self::approvalReference() !== null
            && RegistryAdmissionDeclarations::active()?->lifeText !== null;
    }

    public static function approvalReference(): ?string
    {
        $reference = config('family_registry_admission.life_policy.approval_reference');

        return is_string($reference) && preg_match('#\A[A-Za-z0-9._:/-]{3,80}\z#', $reference) === 1 ? $reference : null;
    }

    /** The extraction date (YYYY-MM-DD) of $releaseId when the revised policy is active and lists it; NULL otherwise. */
    public static function extractedOn(int $releaseId): ?string
    {
        return self::scopeAndDeclarationActive() ? (self::releases()[$releaseId] ?? null) : null;
    }

    /** @return array<int, string> release id => extraction date; [] when any entry is malformed */
    public static function releases(): array
    {
        $raw = config('family_registry_admission.life_policy.living_scope_releases');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $releases = [];
        foreach (explode(',', $raw) as $entry) {
            if (preg_match('/\A\s*([1-9][0-9]{0,17}):([0-9]{4}-[0-9]{2}-[0-9]{2})\s*\z/', $entry, $m) !== 1) {
                return [];
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $m[2]);
            if ($date === false || $date->format('Y-m-d') !== $m[2] || $m[2] > now()->toDateString() || isset($releases[(int) $m[1]])) {
                return [];
            }
            $releases[(int) $m[1]] = $m[2];
        }

        return $releases;
    }
}
