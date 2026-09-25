<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Family;
use App\Support\FamilyActivityLog;
use App\Support\FamilyLineage;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a Family's basic registration metadata in place
 * (docs/03-BUSINESS-RULES.md §56 "Data Correction", permission
 * family.update).
 *
 * Only the listed fields and the Clan/Branch can change. It never touches the family code,
 * status, household head, memberships, persons or residence.
 */
class UpdateFamilyAction
{
    private const EDITABLE = [
        'registration_date',
        'paper_form_no',
        'notes',
    ];

    /** Clan/Branch, applied through FamilyLineage (docs/03 §7a). */
    private const LINEAGE = ['clan_id', 'branch_id'];

    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload
     *                                      (see UpdateFamilyRequest).
     */
    public function handle(Family $family, array $data, ?int $actingUserId): Family
    {
        return DB::transaction(function () use ($family, $data, $actingUserId) {
            $family->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
            FamilyLineage::apply($family, $data);
            // A save that changes nothing is not an activity.
            $changed = $family->isDirty([...self::EDITABLE, ...self::LINEAGE]);

            $family->updated_by = $actingUserId;
            $family->save();

            if ($changed) {
                FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_UPDATED, $family, $actingUserId);
            }

            return $family->fresh([
                'memberships.person',
                'memberships.relationshipType',
                'currentResidence',
                'clan',
                'branch.group.branches',
            ]);
        });
    }
}
