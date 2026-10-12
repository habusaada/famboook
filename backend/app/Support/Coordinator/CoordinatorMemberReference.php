<?php

namespace App\Support\Coordinator;

use App\Enums\FingerprintContext;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Support\FamilyAuth\KeyedFingerprint;

/**
 * The opaque member reference of Coordinator Space (CO-01C, docs/06
 * AUTH-ADR-092): the full keyed HMAC-SHA256 (64 lowercase hex characters) of
 * a FAMILY MEMBERSHIP under its own domain separation
 * (FingerprintContext::COORDINATOR_MEMBER_REF), so it never equals the
 * household member_ref of the same membership.
 *
 * - Not reversible and not enumerable without the key; it never contains a
 *   Person id, membership id, person_code, National ID or mobile.
 * - Never stored: recomputed on every request.
 * - NOT authorization. It resolves only among the ACTIVE memberships of a
 *   Family that the caller has ALREADY resolved through the Coordinator's
 *   scoped query — another Family's membership is never even a candidate —
 *   and every failure (malformed, random, foreign, ended) is the same NULL.
 * - Key rotation: built with the current key version, resolved with the
 *   current and the configured previous versions.
 */
final class CoordinatorMemberReference
{
    private const FORMAT = '/\A[0-9a-f]{64}\z/';

    public static function of(int $familyId, int $membershipId, ?int $version = null): string
    {
        return KeyedFingerprint::of(FingerprintContext::COORDINATOR_MEMBER_REF, $familyId.':'.$membershipId, $version);
    }

    /** The ACTIVE membership of the in-scope Family that the reference names, or NULL. */
    public static function resolve(Family $family, string $ref): ?FamilyMembership
    {
        // Exactly 64 lowercase hex characters; nothing is normalized.
        if (preg_match(self::FORMAT, $ref) !== 1) {
            return null;
        }

        $familyId = (int) $family->getKey();
        $candidates = FamilyMembership::query()
            ->where('family_id', $familyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'family_id', 'person_id']);

        foreach (KeyedFingerprint::versions() as $version) {
            foreach ($candidates as $membership) {
                if (hash_equals(self::of($familyId, (int) $membership->getKey(), $version), $ref)) {
                    return $membership;
                }
            }
        }

        return null;
    }
}
