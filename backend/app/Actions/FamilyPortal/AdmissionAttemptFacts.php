<?php

namespace App\Actions\FamilyPortal;

use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionReference;

/**
 * @internal What one AdmitRegistryChildAction attempt learnt so far, for its refusal row
 * (the release, and the reference whose child is recorded as a keyed fingerprint only).
 */
final class AdmissionAttemptFacts
{
    public ?int $releaseId = null;

    public ?RegistryAdmissionReference $reference = null;

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['releaseId' => $this->releaseId, 'reference' => $this->reference === null ? null : '[redacted]'];
    }
}
