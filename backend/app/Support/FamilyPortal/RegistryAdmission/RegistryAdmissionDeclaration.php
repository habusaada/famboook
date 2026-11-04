<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/**
 * One APPROVED version of the household head's declaration (FB-FAMILY-ADD-01; ADR §6,
 * O-K, G-02), as RegistryAdmissionDeclarations::active() hands it out: the version an
 * admission must carry and the exact text the head is shown. The discovery answer
 * delivers it; the admission records the version in its evidence (declaration_version,
 * declared_at). It is never a draft: a draft is never built into this object.
 */
final class RegistryAdmissionDeclaration
{
    public function __construct(
        public readonly string $version,
        public readonly string $text,
    ) {}

    /** @return array{version: string, text: string} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'text' => $this->text];
    }
}
