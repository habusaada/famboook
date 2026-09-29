<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Models\Person;
use App\Support\DeathDate;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Records the official death of an EXISTING Person (docs/03 §28-§30;
 * permission person.record-death, docs/06 §96): a lifecycle change after
 * registration. Callers: the future staff endpoint and the DEATH_REPORT
 * Change Request application (docs/03 §31). A Person who is ALREADY
 * deceased when first registered (e.g. an imported deceased household head)
 * is created DECEASED by CreatePersonAction instead — no misleading
 * "death after registration" event. Both use the same DeathDate rules.
 *
 * - life_status becomes DECEASED; death_date is the given date or NULL
 *   when the exact date is unknown (docs/03 §29) — never invented.
 * - A supplied date must be a real Y-m-d date, not in the future and not
 *   before the birth date (also a Postgres CHECK).
 * - Nothing else changes: memberships, the household-head flag, other
 *   Persons (no spouse becomes WIDOWED or head) and is_active (life status
 *   is independent of record status, docs/03 §27). A deceased head is
 *   surfaced for review by the Data Quality check HOUSEHOLD_HEAD_DECEASED
 *   (docs/03 §16).
 * - A Person already DECEASED is refused (409): correcting a recorded
 *   death is a different, not yet built, operation.
 *
 * PERSON_DEATH_RECORDED is recorded on the Person's current Family with
 * no metadata (never the date).
 */
class RecordPersonDeathAction
{
    public function handle(Person $person, ?string $deathDate, ?int $actingUserId): Person
    {
        return DB::transaction(function () use ($person, $deathDate, $actingUserId) {
            /** @var Person $person */
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();

            abort_if($person->life_status === LifeStatus::DECEASED, 409, 'وفاة هذا الشخص مسجّلة مسبقًا.');

            DeathDate::validate($deathDate, $person->birth_date?->toDateString());

            $person->life_status = LifeStatus::DECEASED;
            $person->death_date = $deathDate;
            $person->updated_by = $actingUserId;
            $person->save();

            $familyId = $person->activeMembership()->value('family_id');
            if ($familyId !== null) {
                FamilyActivityLog::record($familyId, FamilyActivityType::PERSON_DEATH_RECORDED, $person, $actingUserId);
            }

            return $person;
        });
    }
}
