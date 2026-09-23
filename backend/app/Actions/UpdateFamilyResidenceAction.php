<?php

namespace App\Actions;

use App\Enums\DisplacementStatus;
use App\Models\Family;
use App\Models\FamilyResidence;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the Family's CURRENT residence in place (docs/03-BUSINESS-
 * RULES.md §56 "Data Correction", permission residence.update).
 *
 * It never creates, ends or deletes residence records and never touches
 * the Family, its memberships or its Persons. Recording that a family
 * moved is a different operation (residence.change, with history) and is
 * out of scope here.
 */
class UpdateFamilyResidenceAction
{
    private const EDITABLE = [
        'governorate',
        'city',
        'area',
        'neighborhood',
        'address_text',
        'original_residence_text',
        'displacement_status',
        'displacement_location_text',
    ];

    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload
     *                                      (see UpdateFamilyResidenceRequest).
     */
    public function handle(Family $family, array $data, ?int $actingUserId): Family
    {
        return DB::transaction(function () use ($family, $data, $actingUserId) {
            /** @var FamilyResidence|null $residence */
            $residence = $family->currentResidence()->lockForUpdate()->first();

            abort_if($residence === null, 409, 'لا يوجد سكن حالي مسجّل لهذه الأسرة.');

            $residence->fill(array_intersect_key($data, array_flip(self::EDITABLE)));

            // A displacement location only exists for a displaced family:
            // switching to NOT_DISPLACED or unknown never leaves a stale one.
            if ($residence->displacement_status !== DisplacementStatus::DISPLACED) {
                $residence->displacement_location_text = null;
            }

            $residence->updated_by = $actingUserId;
            $residence->save();

            return $family->fresh([
                'memberships.person',
                'memberships.relationshipType',
                'currentResidence',
            ]);
        });
    }
}
