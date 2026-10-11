<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestAttestation;
use App\Enums\LifeStatusVerificationMethod;

/**
 * What a Staff reviewer supplies when approving a request type that requires
 * attestations (FP-ADR-076): the attestation codes and, for identity, the
 * National ID typed from the person's document. The typed value is compared
 * once with the proposal (NationalId::matches, constant time) and then
 * dropped: it is never stored, logged, returned or put into an event.
 * For a type that requires it (FP-ADR-077), the reviewer's life-status
 * verification method — a controlled code, stored in the APPROVED event.
 */
final readonly class ChangeRequestApprovalEvidence
{
    /** @param list<ChangeRequestAttestation> $attestations */
    public function __construct(
        public array $attestations = [],
        #[\SensitiveParameter] public ?string $verifiedNationalId = null,
        public ?LifeStatusVerificationMethod $verificationMethod = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function attests(ChangeRequestAttestation $attestation): bool
    {
        return in_array($attestation, $this->attestations, true);
    }
}
