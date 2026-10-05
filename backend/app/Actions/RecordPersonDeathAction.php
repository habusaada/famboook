<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\UserPersonLinkEndReason;
use App\Exceptions\PersonLifeStatusException;
use App\Models\Person;
use App\Models\UserPersonLink;
use App\Support\DeathDate;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Records the official death of an EXISTING Person (docs/03 §28-§30;
 * permission person.record-death, docs/06 §96): a lifecycle change after
 * registration. Callers: the Staff endpoint (docs/11 FU-10) and, later, the
 * DEATH_REPORT Change Request application (docs/03 §31). A Person who is
 * ALREADY deceased when first registered (e.g. an imported deceased
 * household head) is created DECEASED by CreatePersonAction instead — no
 * misleading "death after registration" event. Both use the same DeathDate
 * rules.
 *
 * - ALIVE or UNKNOWN → DECEASED. death_date is the given date or NULL when
 *   the exact date is unknown (docs/03 §29) — never invented.
 * - A supplied date must be a real Y-m-d date, not in the future and not
 *   before the birth date (also a Postgres CHECK).
 * - The Person row is locked and re-read; a soft-deleted Person is not
 *   found. ConfirmPersonAliveAction locks the same row, so the two
 *   serialize.
 * - A Person already DECEASED is refused (PERSON_ALREADY_DECEASED, 409).
 *   A recorded death is irreversible in V1: nothing brings a Person back,
 *   and correcting an erroneous death is a separate, not yet built,
 *   operation.
 * - Nothing else changes: memberships, the household-head flag, other
 *   Persons (no spouse becomes WIDOWED or head) and is_active (life status
 *   is independent of record status, docs/03 §27). A deceased head stays
 *   the head — no successor is chosen (docs/03 §16, docs/11 FU-01) — and is
 *   surfaced for review by the Data Quality check HOUSEHOLD_HEAD_DECEASED.
 *
 * PERSON_DEATH_RECORDED is recorded on the Person's current Family with the
 * verification method as its only metadata (a controlled code; never the
 * date or free text); the actor is the activity's actor. A future Change
 * Request application adds its own provenance next to the method without
 * changing this contract.
 *
 * Family Portal (docs/11 §30a): a current User-Person Link of the deceased
 * is ended in this same transaction (PERSON_DECEASED) — its Family Auth
 * Identity is superseded (LINK_ENDED) and every session is revoked. The
 * User account, the membership and the household-head flag are NOT changed:
 * Head Succession stays a separate rollout gate (docs/11 FU-01).
 */
class RecordPersonDeathAction
{
    public function handle(Person $person, ?string $deathDate, LifeStatusVerificationMethod $method, ?int $actingUserId): Person
    {
        return DB::transaction(function () use ($person, $deathDate, $method, $actingUserId) {
            /** @var Person $person */
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();

            if ($person->life_status === LifeStatus::DECEASED) {
                throw new PersonLifeStatusException(PersonLifeStatusException::PERSON_ALREADY_DECEASED);
            }

            DeathDate::validate($deathDate, $person->birth_date?->toDateString());

            $person->life_status = LifeStatus::DECEASED;
            $person->death_date = $deathDate;
            $person->updated_by = $actingUserId;
            $person->save();

            $link = UserPersonLink::query()->where('person_id', $person->id)->current()->first();
            if ($link !== null) {
                app(EndUserPersonLinkAction::class)->bySystem($link, UserPersonLinkEndReason::PERSON_DECEASED, $actingUserId);
            }

            $familyId = $person->activeMembership()->value('family_id');
            if ($familyId !== null) {
                FamilyActivityLog::record($familyId, FamilyActivityType::PERSON_DEATH_RECORDED, $person, $actingUserId, [
                    'verification_method' => $method->value,
                ]);
            }

            return $person;
        });
    }
}
