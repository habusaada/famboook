<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The fields of one committed family_registry_admissions row that a replay compares
 * and records (FB-FAMILY-ADD-01 ADD-WP-07B-2a; ADD-WP-07B-2R D-B2, D-B5). Holds no
 * National ID: the child is the stored keyed fingerprint and the key version that
 * produced it. A malformed row is refused (fail closed), never repaired.
 *
 * ADD-WP-07B-2c: forKey() reads the row of one idempotency key, and the membership and
 * Person ids are carried for the replay's member reference (NULL in rows built by hand).
 */
final class RegistryAdmissionEvidence
{
    /** RR-02A: the life of the admitted child was proven by an authoritative verifier. */
    public const LIFE_VERIFIED_SOURCE = 'VERIFIED_SOURCE';

    /** RR-02A (D-06R): a living-scope release plus the head's declaration; recorded UNKNOWN, never ALIVE. */
    public const LIFE_REGISTRY_SCOPE_DECLARATION = 'REGISTRY_SCOPE_DECLARATION';

    private const TABLE = 'family_registry_admissions';

    private function __construct(
        public readonly int $familyId,
        public readonly int $admittedBy,
        public readonly int $admittedByPersonId,
        public readonly string $referenceFingerprint,
        public readonly int $referenceKeyVersion,
        public readonly int $releaseId,
        public readonly string $declarationVersion,
        public readonly ?int $familyMembershipId = null,
        public readonly ?int $personId = null,
    ) {}

    /**
     * The committed admission of this actor's idempotency key, or NULL. A committed row
     * never changes (append-only), so reading it without a lock is safe; under the
     * Family lock the same call sees a concurrent admission once it has committed.
     */
    public static function forKey(int $actorUserId, string $clientReference): ?self
    {
        $row = DB::table(self::TABLE)->where('admitted_by', $actorUserId)->where('client_reference', $clientReference)->first();

        return $row === null ? null : self::fromRow($row);
    }

    /** @param  object  $row  a family_registry_admissions row */
    public static function fromRow(object $row): self
    {
        $ids = [];
        foreach (['family_id', 'admitted_by', 'admitted_by_person_id', 'reference_key_version', 'release_id'] as $column) {
            $ids[$column] = self::positiveInt($row->{$column} ?? null);
        }
        $optional = [];
        foreach (['family_membership_id', 'person_id'] as $column) {
            $optional[$column] = property_exists($row, $column) ? self::positiveInt($row->{$column}) : null;
            if (property_exists($row, $column) && $optional[$column] === null) {
                throw new InvalidArgumentException('An admission evidence row is malformed.');
            }
        }
        $fingerprint = $row->reference_fingerprint ?? null;
        $declaration = $row->declaration_version ?? null;
        if (in_array(null, $ids, true)
            || ! is_string($fingerprint) || preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) !== 1
            || ! is_string($declaration) || trim($declaration) === '') {
            throw new InvalidArgumentException('An admission evidence row is malformed.');
        }

        return new self($ids['family_id'], $ids['admitted_by'], $ids['admitted_by_person_id'], $fingerprint,
            $ids['reference_key_version'], $ids['release_id'], $declaration, $optional['family_membership_id'], $optional['person_id']);
    }

    /** A positive integer, as either driver returns it; NULL for anything else. */
    private static function positiveInt(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }
}
