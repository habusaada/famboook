<?php

namespace App\Support\Credentials;

use App\Enums\CredentialStatus;
use App\Enums\CredentialSubjectType;
use App\Enums\FamilyStatus;
use App\Models\DigitalCredential;

/**
 * Resolves an opaque credential token for PUBLIC verification (docs/11
 * FP-ADR-070), always with an EXPECTED subject type — a token of another
 * type is simply not found. One query: the hash, the expected type, an
 * ACTIVE credential, and (for FAMILY) an ACTIVE, not deleted Family.
 *
 * Every failure — malformed, unknown, revoked, another type, an inactive,
 * archived or deleted Family — is the same NULL; the reason is never
 * returned. Family validity is read live, never written to the credential,
 * so a Family made ACTIVE again verifies again unless the card was revoked.
 */
final class CredentialResolver
{
    public static function resolveFamily(#[\SensitiveParameter] mixed $token): ?DigitalCredential
    {
        if (! CredentialTokens::isWellFormed($token)) {
            return null;
        }

        return DigitalCredential::query()
            ->where('token_hash', CredentialTokens::hash($token))
            ->where('subject_type', CredentialSubjectType::FAMILY->value)
            ->where('status', CredentialStatus::ACTIVE->value)
            // The relation's default scope already leaves deleted Families out.
            ->whereHas('family', fn ($family) => $family->where('status', FamilyStatus::ACTIVE->value))
            ->with(['family.clan', 'family.branch'])
            ->first();
    }
}
