<?php

namespace App\Http\Resources;

use App\Models\DigitalCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Digital Family Card for Staff holding family-card.view (docs/11
 * FP-ADR-070): the public card number, status, dates, the issuer and revoker
 * display names (NULL issuer = the system, i.e. Family Portal issuance) and
 * the revoke reason code. Never the token, its hash or sealed copy, a QR, or
 * any internal id.
 *
 * @mixin DigitalCredential
 */
class FamilyCredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'credential_number' => $this->credential_number,
            'status' => $this->status->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'issued_by' => $this->issuer?->name,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revoker?->name,
            'revoke_reason' => $this->revoke_reason?->value,
        ];
    }
}
