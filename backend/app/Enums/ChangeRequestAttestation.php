<?php

namespace App\Enums;

// What a Staff reviewer explicitly confirms when approving a request type
// that requires it (docs/11 FP-ADR-076). Stored only as these codes in the
// APPROVED workflow event's metadata — never the evidence itself.
enum ChangeRequestAttestation: string
{
    // The reviewer checked the person's identity document; for a typed
    // National ID it must also match the proposal (purpose-limited compare).
    case IDENTITY_VERIFIED = 'IDENTITY_VERIFIED';

    // The reviewer verified the stated relationship to the household.
    case RELATIONSHIP_VERIFIED = 'RELATIONSHIP_VERIFIED';

    /** The workflow event metadata key this attestation is stored under. */
    public function metadataKey(): string
    {
        return match ($this) {
            self::IDENTITY_VERIFIED => 'identity',
            self::RELATIONSHIP_VERIFIED => 'relationship',
        };
    }
}
