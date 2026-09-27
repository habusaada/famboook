<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects the relationship of a CURRENT member to the household head
 * (docs/03 §56 "Data Correction", permission family-membership.update).
 *
 * The membership is corrected in place: the Person, the membership row,
 * its dates and the household-head flag are never touched, and no
 * membership is deleted or recreated. The HEAD relationship and the
 * is_household_head flag must agree, so this can never move or remove the
 * household head: a head keeps HEAD, and HEAD cannot be given to anyone
 * else (that is the separate household-head change, docs/03 §15).
 */
class CorrectMembershipRelationshipAction
{
    public const NOT_A_CURRENT_MEMBER = 'هذا الشخص ليس فردًا حاليًا في هذه الأسرة.';

    public function handle(Family $family, Person $person, int $relationshipTypeId, ?int $actingUserId): FamilyMembership
    {
        return DB::transaction(function () use ($family, $person, $relationshipTypeId, $actingUserId) {
            /** @var FamilyMembership|null $membership */
            $membership = FamilyMembership::query()
                ->where('family_id', $family->id)
                ->where('person_id', $person->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            abort_if($membership === null, 409, self::NOT_A_CURRENT_MEMBER);

            $type = RelationshipType::query()->whereKey($relationshipTypeId)->where('is_active', true)->first();
            if ($type === null) {
                throw ValidationException::withMessages(['relationship_type_id' => 'صلة القرابة المختارة غير متاحة.']);
            }

            $isHeadType = $type->code === 'HEAD';
            if ($membership->is_household_head && ! $isHeadType) {
                throw ValidationException::withMessages([
                    'relationship_type_id' => 'صلة رب الأسرة ثابتة ولا تُعدَّل من هنا. تغيير رب الأسرة إجراء مستقل.',
                ]);
            }
            if (! $membership->is_household_head && $isHeadType) {
                throw ValidationException::withMessages([
                    'relationship_type_id' => 'لا يمكن اختيار "رب الأسرة" لفرد ليس رب الأسرة. تغيير رب الأسرة إجراء مستقل.',
                ]);
            }

            $membership->relationship_type_id = $type->id;

            // A correction that changes nothing is not an activity.
            if ($membership->isDirty('relationship_type_id')) {
                $membership->updated_by = $actingUserId;
                $membership->save();

                FamilyActivityLog::record($family->id, FamilyActivityType::MEMBERSHIP_RELATIONSHIP_CORRECTED, $person, $actingUserId);
            }

            return $membership->load(['person', 'relationshipType']);
        });
    }
}
