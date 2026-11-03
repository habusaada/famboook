<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\FingerprintContext;
use App\Support\FamilyAuth\KeyedFingerprint;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

/**
 * What an authentic admission reference proves for a REPLAY, and nothing more
 * (FB-FAMILY-ADD-01 ADD-WP-07B-2a; ADD-WP-07B-2R D-B2, D-B3, D-B6). INTERNAL: built only
 * by RegistryAdmissionRefs::openForReplay(); nothing uses it yet.
 *
 * - It authorizes nothing. It is not a RegistryAdmissionReference, cannot become one and
 *   gives no way back to the child's National ID: the only operation is
 *   matchesEvidence(), which compares it with a committed evidence row. A replay claim
 *   never reaches a write path (ADR §10).
 * - It keeps no National ID. When it is built, the child's keyed fingerprint is computed
 *   under every usable key version (KeyedFingerprint::versions()); the National ID is
 *   then dropped. A stored row is compared under ITS key version; a version that is no
 *   longer usable does not match (fail closed, D-B6: the caller answers a conflict).
 * - Release, parent link and outcome are not part of the match (D-B2).
 * - Comparisons are constant-time and all evaluated, whatever fails first.
 * - Masked in debug output; never JSON-encoded, serialized or unserialized.
 */
final class RegistryAdmissionReplayClaim implements JsonSerializable
{
    /** @param  array<int, string>  $fingerprints  key version => keyed fingerprint of the child */
    private function __construct(
        private readonly int $userId,
        private readonly int $familyId,
        private readonly int $headPersonId,
        private readonly array $fingerprints,
    ) {}

    /** @internal for RegistryAdmissionRefs::openForReplay() only. Throws when the fingerprint key is unusable. */
    public static function of(int $userId, int $familyId, int $headPersonId, #[\SensitiveParameter] string $childNationalId): self
    {
        if ($userId < 1 || $familyId < 1 || $headPersonId < 1 || ! CivilRegistryValues::validNationalId($childNationalId)) {
            throw new InvalidArgumentException('A replay claim needs positive ids and a valid National ID.');
        }
        $fingerprints = [];
        foreach (KeyedFingerprint::versions() as $version) {
            $fingerprints[$version] = KeyedFingerprint::of(FingerprintContext::FAMILY_REGISTRY_ADMISSION_REFERENCE, $childNationalId, $version);
        }

        return new self($userId, $familyId, $headPersonId, $fingerprints);
    }

    /**
     * Whether $evidence records this actor, Family, household head and child, under
     * this declaration version.
     */
    public function matchesEvidence(RegistryAdmissionEvidence $evidence, string $declarationVersion): bool
    {
        $fingerprint = $this->fingerprints[$evidence->referenceKeyVersion] ?? null;
        $checks = [
            $evidence->admittedBy === $this->userId,
            $evidence->familyId === $this->familyId,
            $evidence->admittedByPersonId === $this->headPersonId,
            $fingerprint !== null && hash_equals($evidence->referenceFingerprint, $fingerprint),
            $declarationVersion !== '' && hash_equals($evidence->declarationVersion, $declarationVersion),
        ];

        return ! in_array(false, $checks, true);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'familyId' => $this->familyId, 'headPersonId' => $this->headPersonId, 'fingerprints' => '[redacted]'];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('A replay claim is never serialized.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('A replay claim is never serialized.');
    }

    /** @param  array<mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A replay claim is never unserialized.');
    }
}
