<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use InvalidArgumentException;

/**
 * The fields of one committed family_registry_admissions row that a replay compares
 * and records (FB-FAMILY-ADD-01 ADD-WP-07B-2a; ADD-WP-07B-2R D-B2, D-B5). INTERNAL:
 * nothing reads evidence rows yet. Holds no National ID: the child is the stored keyed
 * fingerprint and the key version that produced it. A malformed row is refused
 * (fail closed), never repaired.
 */
final class RegistryAdmissionEvidence
{
    private function __construct(
        public readonly int $familyId,
        public readonly int $admittedBy,
        public readonly int $admittedByPersonId,
        public readonly string $referenceFingerprint,
        public readonly int $referenceKeyVersion,
        public readonly int $releaseId,
        public readonly string $declarationVersion,
    ) {}

    /** @param  object  $row  a family_registry_admissions row */
    public static function fromRow(object $row): self
    {
        $ids = [];
        foreach (['family_id', 'admitted_by', 'admitted_by_person_id', 'reference_key_version', 'release_id'] as $column) {
            $ids[$column] = self::positiveInt($row->{$column} ?? null);
        }
        $fingerprint = $row->reference_fingerprint ?? null;
        $declaration = $row->declaration_version ?? null;
        if (in_array(null, $ids, true)
            || ! is_string($fingerprint) || preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) !== 1
            || ! is_string($declaration) || trim($declaration) === '') {
            throw new InvalidArgumentException('An admission evidence row is malformed.');
        }

        return new self($ids['family_id'], $ids['admitted_by'], $ids['admitted_by_person_id'], $fingerprint,
            $ids['reference_key_version'], $ids['release_id'], $declaration);
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
