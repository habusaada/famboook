<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\RegistryChildStatus;

/**
 * One suggested child as discovery may present it (FB-FAMILY-ADD-01 ADD-WP-06A).
 * INTERNAL DTO, not a response contract. It carries no internal reason, no other
 * household, no source payload or record id, and only a masked National ID.
 *
 * `ref` (RR-02) is the opaque admission reference (RegistryAdmissionRefs), issued only
 * for an ELIGIBLE child and only when the caller asked for references; NULL otherwise.
 */
final class SuggestedChild
{
    public function __construct(
        public readonly ?string $fullName,
        public readonly ?string $nationalIdMasked,
        public readonly ?string $birthDate,
        /** 'SON' / 'DAUGHTER' only from an APPROVED gender mapping (G-04); NULL otherwise. */
        public readonly ?string $relationship,
        public readonly RegistryChildStatus $status,
        public readonly ?string $ref = null,
    ) {}
}
