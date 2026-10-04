<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Person;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a Person's basic data in place (docs/03-BUSINESS-RULES.md §56
 * "Data Correction", permission person.update). The accepted fields are
 * limited by UpdatePersonRequest; National ID (CorrectNationalIdAction),
 * life status, death and membership changes are separate controlled
 * operations.
 *
 * Records PERSON_UPDATED on the Person's current Family. A Person with no
 * active membership has no family timeline to write to.
 *
 * Defense in depth: the action writes only its own allow-list (the fields
 * UpdatePersonRequest accepts), whatever a caller passes. Life status, death
 * date, National ID, record status, codes and audit columns are never
 * written here, even by an internal caller such as a future Change Request
 * application.
 */
class UpdatePersonAction
{
    /** The only Person fields this action writes. */
    public const FIELDS = [
        'full_name',
        'gender',
        'marital_status',
        'birth_date',
        'mobile',
        'alternate_mobile',
        'alternate_mobile_owner_relation',
    ];

    /**
     * @param  array<string, mixed>  $data  Already-validated partial payload
     *                                      (see UpdatePersonRequest).
     */
    public function handle(Person $person, array $data, ?int $actingUserId): Person
    {
        // Never the National ID (CorrectNationalIdAction), life status or
        // death (ConfirmPersonAliveAction, RecordPersonDeathAction), or any
        // other field outside the allow-list.
        $data = array_intersect_key($data, array_flip(self::FIELDS));

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
