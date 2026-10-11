<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Support\FamilyActivityLog;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use App\Support\NationalIdGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 * Lock order (docs/11 FP-ADR-076), the same as every National ID writer:
 * the Person's current Family (KEY SHARE — the activity insert needs it),
 * then the National ID advisory lock, then the Person row. A Change Request
 * APPLY takes Family → National ID → Person too, so the two can wait for
 * each other but never deadlock. If the Person's active Family changed
 * between the first read and the Person lock, the correction is refused
 * (nothing changed; retry) rather than taking a lock out of order.
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
            $familyId = self::activeFamilyId($person->id);
            if ($familyId !== null) {
                Family::withTrashed()->whereKey($familyId)->lock('for key share')->first();
            }
            if (trim($nationalId) !== '') {
                NationalIdGuard::lock($nationalId);
            }

            /** @var Person $person */
            $person = Person::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();

            // Re-entering the current value changes nothing and is not an
            // activity. The response is the same either way.
            if ($person->national_id === $nationalId) {
                return $person;
            }
            if (self::activeFamilyId($person->id) !== $familyId) {
                throw ValidationException::withMessages(['national_id' => 'تغيّرت عضوية الشخص أثناء التصحيح. أعد المحاولة.']);
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

    private static function activeFamilyId(int $personId): ?int
    {
        $id = FamilyMembership::query()->where('person_id', $personId)->where('is_active', true)->value('family_id');

        return $id === null ? null : (int) $id;
    }
}
