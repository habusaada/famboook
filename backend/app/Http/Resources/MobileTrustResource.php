<?php

namespace App\Http\Resources;

use App\Models\PersonMobileTrust;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mobile trust record for authorized Staff (docs/06 §22b). The number
 * appears only as a mask of its last two digits; people as a display name.
 * Never the fingerprint, its key version, an internal id or anything about
 * OTP challenges.
 *
 * @mixin PersonMobileTrust
 */
class MobileTrustResource extends JsonResource
{
    public const MASK = '********';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'mobile_masked' => self::MASK.$this->mobile_last2,
            'verification_method' => $this->verification_method?->value,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->verifier?->name,
            'stale_at' => $this->stale_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revoker?->name,
            'revoke_reason' => $this->revoke_reason,
        ];
    }
}
