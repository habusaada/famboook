<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/**
 * One APPROVED version of the household head's declaration (FB-FAMILY-ADD-01; ADR §6,
 * O-K, G-02), as RegistryAdmissionDeclarations::active() hands it out: the version an
 * admission must carry and the exact text the head is shown. The discovery answer
 * delivers it; the admission records the version in its evidence (declaration_version,
 * declared_at). It is never a draft: a draft is never built into this object.
 *
 * `lifeText` (RR-02A, D-06R) is the head's SEPARATE statement that the child is alive
 * now, confirmed on its own; it is delivered and required only while the revised life
 * policy is active (RegistryLifePolicy).
 */
final class RegistryAdmissionDeclaration
{
    public function __construct(
        public readonly string $version,
        public readonly string $text,
        public readonly ?string $lifeText = null,
    ) {}

    /** @return array{version: string, text: string, life_text: ?string} */
    public function toArray(bool $withLifeText = false): array
    {
        return ['version' => $this->version, 'text' => $this->text, 'life_text' => $withLifeText ? $this->lifeText : null];
    }
}
