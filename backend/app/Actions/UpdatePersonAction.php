<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Person;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a Person's basic data in place (docs/03-BUSINESS-RULES.md §56
 * "Data Correction", permission person.update). The accepted fields are
 * limited by UpdatePersonRequest; life status, death and membership
 * changes are separate controlled operations.
 *
 * Records PERSON_UPDATED on the Person's current Family. A Person with no
 * active membership has no family timeline to write to.
 */
class UpdatePersonAction
{
    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload
     *                                      (see UpdatePersonRequest).
     */
    public function handle(Person $person, array $data, ?int $actingUserId): Person
    {
        return DB::transaction(function () use ($person, $data, $actingUserId) {
            $person->fill($data);

            // A save that changes nothing is not an activity.
            $changed = $person->isDirty(array_keys($data));

            $person->updated_by = $actingUserId;
            $person->save();

            $familyId = $person->activeMembership()->value('family_id');
            if ($changed && $familyId !== null) {
                FamilyActivityLog::record($familyId, FamilyActivityType::PERSON_UPDATED, $person, $actingUserId);
            }

            return $person;
        });
    }
}
