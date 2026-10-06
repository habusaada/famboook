<?php

namespace App\Http\Resources;

use App\Support\FamilyAuth\CurrentTrustedMobile;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\MobileMask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * «حسابي» for the Family Portal (GET /api/v1/family/account, PWA-3B.5,
 * docs/11 §23a): the account facts of the signed-in household head that
 * /family/me does not carry — an explicit allow-list built from the
 * family.context result.
 *
 * - activated_at: when the CURRENT User-Person Link was activated; NULL
 *   stays NULL;
 * - mobile.state: the derived state decided by CurrentTrustedMobile (NO_MOBILE,
 *   UNVERIFIED, TRUSTED, STALE, REVOKED, UNAVAILABLE) — the current state
 *   only, never the trust history;
 * - mobile.masked: the Person's mobile through the Family MobileMask, the
 *   same value «بياناتي الشخصية» shows.
 *
 * Never an id, uuid, person_code, role or permission list, trust row,
 * verifier, revoker, revoke reason, fingerprint, key version, OTP, session
 * or security event. Roles, the Coordinator flags and the display name stay
 * on /family/me; the coordinator scope stays on /family/coordinator/context.
 *
 * @property FamilyAccessResult $resource
 */
class FamilyAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $person = $this->resource->person;

        return [
            'activated_at' => $this->resource->link->activated_at?->toIso8601String(),
            'mobile' => [
                'state' => app(CurrentTrustedMobile::class)->for($person)->state(),
                'masked' => MobileMask::mask($person->mobile),
            ],
        ];
    }
}
