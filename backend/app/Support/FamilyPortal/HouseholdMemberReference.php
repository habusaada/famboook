<?php

namespace App\Support\FamilyPortal;

use App\Enums\FingerprintContext;
use App\Models\FamilyMembership;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\KeyedFingerprint;

/**
 * The opaque household-member reference of the Family Portal (docs/11 FU-13,
 * FP-ADR-063): `member_ref`, the full keyed HMAC-SHA256 (64 lowercase hex
 * characters) of a FAMILY MEMBERSHIP — "this person as part of this
 * household" — under its own domain separation
 * (FingerprintContext::MEMBER_REF) and the Family Auth key and versions.
 *
 * - Not reversible and not enumerable without the key; it never contains a
 *   Person id, membership id, person_code, National ID or mobile.
 * - Never stored: recomputed on every request. A Change Request stores the
 *   internal membership id, never this reference.
 * - NOT authorization. A reference resolves only among the ACTIVE
 *   memberships of the Family that the family.context boundary resolved —
 *   another household's membership is never even a candidate — and every
 *   failure (malformed, random, foreign, ended) is the same NULL.
 * - An ended membership stops resolving; a new membership (a transfer) has
 *   a new reference. An active membership whose Person is unavailable keeps
 *   its reference: the membership is still part of the household.
 * - Key rotation: references are built with the current key version and
 *   resolve with the current and the configured previous version.
 */
final class HouseholdMemberReference
{
    private const FORMAT = '/\A[0-9a-f]{64}\z/';

    /** The reference of one membership of one family, with the current key version. */
    public static function of(int $familyId, int $membershipId, ?int $version = null): string
    {
        return KeyedFingerprint::of(FingerprintContext::MEMBER_REF, self::value($familyId, $membershipId), $version);
    }

    /**
     * The ACTIVE membership of the context's Family that the reference names,
     * or NULL — the same NULL for every reason, never revealed to a client.
     */
    public static function resolve(FamilyAccessResult $context, string $ref): ?FamilyMembership
    {
        // Exactly 64 lowercase hex characters; nothing is normalized.
        if (! $context->hasFamilyContext() || preg_match(self::FORMAT, $ref) !== 1) {
            return null;
        }

        $familyId = (int) $context->family->getKey();
        // The candidates come from the context's Family only, ACTIVE only.
        $candidates = FamilyMembership::query()
            ->where('family_id', $familyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach (KeyedFingerprint::versions() as $version) {
            foreach ($candidates as $membership) {
                if (hash_equals(self::of($familyId, (int) $membership->getKey(), $version), $ref)) {
                    return $membership;
                }
            }
        }

        return null;
    }

    private static function value(int $familyId, int $membershipId): string
    {
        return $familyId.':'.$membershipId;
    }
}
