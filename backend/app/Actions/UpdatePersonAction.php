<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Person;
use App\Support\FamilyActivityLog;
use App\Support\NationalIdGuard;
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
            // A changed National ID must not collide with another Person
            // (docs/03 §21; changing it needs person.national-id.update).
            if (array_key_exists('national_id', $data) && $data['national_id'] !== $person->national_id) {
                NationalIdGuard::assertAvailable($data['national_id'], 'national_id', $person->id);
            }

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
