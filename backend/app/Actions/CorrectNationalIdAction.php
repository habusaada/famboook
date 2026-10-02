<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Person;
use App\Support\FamilyActivityLog;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use App\Support\NationalIdGuard;
use Illuminate\Support\Facades\DB;

/**
 * Administrative correction of a Person's National ID (permission
 * person.national-id.update, AUTH-ADR-059). The only write path for an
 * existing Person's National ID.
 *
 * The replacement is always an explicit, non-blank value: this never
 * clears a National ID (there is no supported "clear" action in V1). The
 * exact duplicate rule (docs/03 §21, §93a) applies through NationalIdGuard,
 * so a value held by another Person is refused and nothing changes. The
 * value is stored as entered — no normalization (PDD-001 stays open).
 *
 * NATIONAL_ID_CORRECTED is recorded on the Person's current Family with no
 * metadata: never the old or new value.
 *
 * Family Portal (docs/11 §30a): when the Person has an ACTIVE or SUSPENDED
 * User-Person Link, the Family Auth Identity is synchronized in this same
 * transaction, so the registry value and the login identifier can never
 * disagree — the old National ID stops authenticating at commit. A value
 * that is not nine digits suspends the identity; a login key held by another
 * account refuses the correction and nothing changes.
 */
class CorrectNationalIdAction
{
    public function handle(Person $person, string $nationalId, ?int $actingUserId): Person
    {
        return DB::transaction(function () use ($person, $nationalId, $actingUserId) {
            /** @var Person $person */
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();

            // Re-entering the current value changes nothing and is not an
            // activity. The response is the same either way.
            if ($person->national_id === $nationalId) {
                return $person;
            }

            NationalIdGuard::assertAvailable($nationalId, 'national_id', $person->id);

            $person->national_id = $nationalId;
            $person->updated_by = $actingUserId;
            $person->save();

            // Throws (and rolls the correction back) on a login-key collision.
            app(FamilyAuthIdentities::class)->syncAfterNationalIdCorrection($person, $actingUserId);

            $familyId = $person->activeMembership()->value('family_id');
            if ($familyId !== null) {
                FamilyActivityLog::record($familyId, FamilyActivityType::NATIONAL_ID_CORRECTED, $person, $actingUserId);
            }

            return $person;
        });
    }
}
