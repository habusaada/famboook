<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\AccountSide;
use App\Support\CivilLookup\CivilLookupAccess;
use App\Support\CivilLookup\CivilLookupContext;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\SignInHistory;
use App\Support\Notifications\NotificationVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated family-side user for the Family Portal bootstrap
 * (GET /api/v1/family/me, docs/06 §22b). Resolved afresh on every request:
 *
 * - display_name is the linked Person's name, never users.name (a snapshot
 *   taken at activation);
 * - context.available says whether a Family context exists right now. When
 *   it does not, family is null and the reason is NOT sent;
 * - coordinator_space says whether Coordinator Space is open right now — the
 *   SAME check the coordinator.space boundary runs (CoordinatorScopes), so
 *   the two cannot disagree. Scope details live in the coordinator context;
 * - notifications / coordinator_notifications say whether to show the
 *   notification UI (NotificationVisibility: mode, pilot lists, existing
 *   notifications) — never which list matched or any id;
 * - civil_lookup.family / civil_lookup.coordinator say whether to SHOW the
 *   Civil Registry Lookup entry of each side (FB-CIVIL-02B): the side's
 *   boundary (a Family context / Coordinator Space open) AND the same
 *   CivilLookupAccess evaluation the endpoints run. Never a quota, a reason
 *   or a policy.
 *
 * Never a National ID, a mobile, an internal id, a permission list or any
 * fingerprint. UX only: every family-data endpoint authorizes on its own.
 *
 * FB-FAMILY-DASHBOARD-01: previous_sign_in_at (UTC ISO 8601 or null) and
 * first_sign_in come from THIS session (SignInHistory): null / false means
 * unknown unless first_sign_in is true. Never an IP, a device or another
 * user's event.
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
        $coordinator = in_array(AccountSide::COORDINATOR, $roles, true);
        $coordinatorSpace = $coordinator && app(CoordinatorScopes::class)->context($this->resource)->allowed();

        return [
            'display_name' => $identity->allowed() ? $identity->person->full_name : null,
            'roles' => $roles,
            'coordinator' => $coordinator,
            'coordinator_space' => $coordinatorSpace,
            // Pilot isolation (ADR §2h): whether to SHOW the household's
            // notification UI (C1) and the coordinator's notification screens
            // (C3). Visibility only; the endpoints authorize on their own.
            'notifications' => NotificationVisibility::forFamily($family),
            'coordinator_notifications' => NotificationVisibility::forCoordinator($this->resource, $coordinator),
            'civil_lookup' => [
                'family' => $family !== null && CivilLookupAccess::evaluate($this->resource, CivilLookupContext::FAMILY)['allowed'],
                'coordinator' => $coordinatorSpace && CivilLookupAccess::evaluate($this->resource, CivilLookupContext::COORDINATOR)['allowed'],
            ],
            ...SignInHistory::forSession($request),
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
