<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Exceptions\PersonLifeStatusException;
use App\Models\Person;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Confirms that a Person whose life status is UNKNOWN is alive (docs/03
 * §30a; docs/11 FU-07, PFP-024): exactly UNKNOWN → ALIVE, nothing else.
 * Imported spouses and imported household heads may carry UNKNOWN; an
 * UNKNOWN head cannot reach the Family Portal, so Staff confirm it here.
 * Callers: the Staff endpoint (permission person.record-death) and, later,
 * the PERSON_CORRECTION / CONFIRM_ALIVE Change Request application.
 *
 * - The Person row is locked and re-read; a soft-deleted Person is not
 *   found. RecordPersonDeathAction locks the same row, so the two
 *   serialize: whichever runs first wins and the other is refused.
 * - ALIVE is refused (PERSON_ALREADY_ALIVE) rather than ignored, so a late
 *   caller sees the conflict; DECEASED is refused (PERSON_DECEASED) — this
 *   path never brings anyone back to life; an UNKNOWN Person carrying a
 *   death date is refused (INCONSISTENT_LIFE_RECORD) for Staff repair.
 * - is_active and an active membership are not required: life status is
 *   independent of record status (docs/03 §27).
 * - Nothing else changes: memberships, the household-head flag, marital
 *   status, the mobile and its trust, a User-Person Link, a Family Auth
 *   identity or any session. Confirming an UNKNOWN head only makes the
 *   existing activation flow possible; it activates nothing.
 *
 * PERSON_ALIVE_CONFIRMED is recorded on the Person's current Family with
 * the verification method as its only metadata (a controlled code); the
 * actor is the activity's actor. A future Change Request application adds
 * its own provenance next to the method without changing this contract.
 */
class ConfirmPersonAliveAction
{
    public function handle(Person $person, LifeStatusVerificationMethod $method, ?int $actingUserId): Person
    {
        return DB::transaction(function () use ($person, $method, $actingUserId) {
            /** @var Person $person */
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();

            match ($person->life_status) {
                LifeStatus::ALIVE => throw new PersonLifeStatusException(PersonLifeStatusException::PERSON_ALREADY_ALIVE),
                LifeStatus::DECEASED => throw new PersonLifeStatusException(PersonLifeStatusException::PERSON_DECEASED),
                LifeStatus::UNKNOWN => null,
            };
            if ($person->death_date !== null) {
                throw new PersonLifeStatusException(PersonLifeStatusException::INCONSISTENT_LIFE_RECORD);
            }

            $person->life_status = LifeStatus::ALIVE;
            $person->updated_by = $actingUserId;
            $person->save();

            $familyId = $person->activeMembership()->value('family_id');
            if ($familyId !== null) {
                FamilyActivityLog::record($familyId, FamilyActivityType::PERSON_ALIVE_CONFIRMED, $person, $actingUserId, [
                    'verification_method' => $method->value,
                ]);
            }

            return $person;
        });
    }
}
