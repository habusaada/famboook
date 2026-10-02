<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\AccountSide;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated family-side user for the Family Portal bootstrap
 * (GET /api/v1/family/me, docs/06 §22b). Resolved afresh on every request:
 *
 * - display_name is the linked Person's name, never users.name (a snapshot
 *   taken at activation);
 * - context.available says whether a Family context exists right now. When
 *   it does not, family is null and the reason is NOT sent.
 *
 * Never a National ID, a mobile, an internal id, a permission list or any
 * fingerprint. UX only: every family-data endpoint authorizes on its own.
 *
 * @mixin User
 */
class FamilyCurrentUserResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resolver = app(FamilyAccessResolver::class);
        $context = $resolver->familyContext($this->resource);
        // Who the user is can stay valid when the Family context is gone.
        $identity = $context->allowed() ? $context : $resolver->identity($this->resource);
        $roles = array_values(array_intersect(AccountSide::FAMILY_SIDE_ROLES, $this->getRoleNames()->all()));
        $family = $context->hasFamilyContext() ? $context->family : null;

        return [
            'display_name' => $identity->allowed() ? $identity->person->full_name : null,
            'roles' => $roles,
            'coordinator' => in_array(AccountSide::COORDINATOR, $roles, true),
            'context' => [
                'available' => $family !== null,
                'family' => $family === null ? null : [
                    'code' => $family->family_code,
                    'name' => $family->branch?->name ?? $family->clan?->name,
                ],
            ],
        ];
    }
}
