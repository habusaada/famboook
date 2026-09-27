<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Ends a CURRENT, non-head membership (permission family-membership.end),
 * e.g. a Person attached to the wrong Family or no longer living in it.
 *
 * Nothing is deleted (docs/03 §11, §99-100): the membership row stays as
 * history with is_active = false, ended_at and the staff member's reason
 * in end_reason. The Person is not changed, not deleted and not attached
 * to any other Family — that would be a transfer (docs/03 §13), which is
 * a separate operation.
 *
 * The current household head cannot be ended here: a Family must not be
 * left without its head by an ordinary correction (docs/03 §14-15).
 */
class EndFamilyMembershipAction
{
    public const HOUSEHOLD_HEAD = 'لا يمكن إنهاء عضوية رب الأسرة الحالي. يتطلب ذلك إجراء تغيير رب الأسرة.';

    public function handle(Family $family, Person $person, string $reason, ?int $actingUserId): FamilyMembership
    {
        return DB::transaction(function () use ($family, $person, $reason, $actingUserId) {
            /** @var FamilyMembership|null $membership */
            $membership = FamilyMembership::query()
                ->where('family_id', $family->id)
                ->where('person_id', $person->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            abort_if($membership === null, 409, CorrectMembershipRelationshipAction::NOT_A_CURRENT_MEMBER);
            abort_if($membership->is_household_head, 409, self::HOUSEHOLD_HEAD);

            // Today, but never before the start date (docs/04 §22
            // chk_membership_dates): a membership cannot end before it began.
            $today = now()->startOfDay();
            $endedAt = $membership->started_at !== null && $membership->started_at->gt($today)
                ? $membership->started_at
                : $today;

            $membership->forceFill([
                'is_active' => false,
                'ended_at' => $endedAt->toDateString(),
                'end_reason' => $reason,
                'updated_by' => $actingUserId,
            ])->save();

            // No metadata: the reason is free text and stays on the
            // membership, never in the activity log.
            FamilyActivityLog::record($family->id, FamilyActivityType::MEMBERSHIP_ENDED, $person, $actingUserId);

            return $membership;
        });
    }
}
