<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

/**
 * The head's suggested children of one release, keyed by National ID, as an admission
 * needs them (ADD-WP-07B-2c). A too-broad answer carries no candidate.
 */
final class AdmissionCandidates
{
    /** @param  array<string, AdmissionCandidate>  $byNationalId */
    public function __construct(
        public readonly int $releaseId,
        public readonly bool $tooBroad,
        #[\SensitiveParameter] private readonly array $byNationalId,
    ) {}

    public function for(#[\SensitiveParameter] string $nationalId): ?AdmissionCandidate
    {
        return $this->byNationalId[$nationalId] ?? null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['releaseId' => $this->releaseId, 'tooBroad' => $this->tooBroad, 'candidates' => count($this->byNationalId)];
    }
}
