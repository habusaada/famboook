<?php

namespace App\Actions;

use App\Models\Family;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a Family's basic registration metadata in place
 * (docs/03-BUSINESS-RULES.md §56 "Data Correction", permission
 * family.update).
 *
 * Only the listed fields can change. It never touches the family code,
 * status, household head, memberships, persons or residence.
 */
class UpdateFamilyAction
{
    private const EDITABLE = [
        'registration_date',
        'paper_form_no',
        'notes',
    ];

    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload
     *                                      (see UpdateFamilyRequest).
     */
    public function handle(Family $family, array $data, ?int $actingUserId): Family
    {
        return DB::transaction(function () use ($family, $data, $actingUserId) {
            $family->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
            $family->updated_by = $actingUserId;
            $family->save();

            return $family->fresh([
                'memberships.person',
                'memberships.relationshipType',
                'currentResidence',
            ]);
        });
    }
}
