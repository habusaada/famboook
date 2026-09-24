<?php

namespace App\Actions;

use App\Enums\DisplacementStatus;
use App\Enums\FamilyActivityType;
use App\Models\Family;
use App\Models\FamilyResidence;
use App\Support\FamilyActivityLog;
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
    /** Current-address fields → RESIDENCE_UPDATED. */
    private const ADDRESS_FIELDS = [
        'governorate',
        'city',
        'area',
        'neighborhood',
        'address_text',
    ];

    /** Displacement fields (docs/02 §19) → DISPLACEMENT_UPDATED. */
    private const DISPLACEMENT_FIELDS = [
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

            $editable = [...self::ADDRESS_FIELDS, ...self::DISPLACEMENT_FIELDS];
            $residence->fill(array_intersect_key($data, array_flip($editable)));

            // A displacement location only exists for a displaced family:
            // switching to NOT_DISPLACED or unknown never leaves a stale one.
            if ($residence->displacement_status !== DisplacementStatus::DISPLACED) {
                $residence->displacement_location_text = null;
            }

            // Which group of fields changed decides the event; values are
            // never recorded. One request touching both writes both events.
            $addressChanged = $residence->isDirty(self::ADDRESS_FIELDS);
            $displacementChanged = $residence->isDirty(self::DISPLACEMENT_FIELDS);

            $residence->updated_by = $actingUserId;
            $residence->save();

            if ($addressChanged) {
                FamilyActivityLog::record($family->id, FamilyActivityType::RESIDENCE_UPDATED, $residence, $actingUserId);
            }
            if ($displacementChanged) {
                FamilyActivityLog::record($family->id, FamilyActivityType::DISPLACEMENT_UPDATED, $residence, $actingUserId);
            }

            return $family->fresh([
                'memberships.person',
                'memberships.relationshipType',
                'currentResidence',
            ]);
        });
    }
}
