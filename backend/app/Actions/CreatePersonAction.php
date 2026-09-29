<?php

namespace App\Actions;

use App\Enums\Gender;
use App\Enums\LifeStatus;
use App\Enums\MaritalStatus;
use App\Models\Person;
use App\Support\BusinessIdentifier;
use App\Support\DeathDate;
use App\Support\NationalIdGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical creation of ONE Person (docs/03 §18-§30, §96b) — an internal
 * building block, not an endpoint. Every flow that creates a Person goes
 * through here: RegisterFamilyAction, AddFamilyMemberAction and (future)
 * import Apply.
 *
 * - person_code from the reserved id (BusinessIdentifier, PER-000001).
 * - National ID: NULL allowed (unknown, docs/03 §19 — never a fake value);
 *   otherwise NationalIdGuard refuses any existing Person with that exact
 *   value (no second Person, no attach, no merge).
 * - life_status is EXPLICIT (no default): ALIVE and UNKNOWN never carry a
 *   death date; DECEASED may carry one (DeathDate rules) or NULL when the
 *   date is unknown — never invented. A Person known to be deceased when
 *   first registered starts DECEASED; RecordPersonDeathAction is only for a
 *   death after registration.
 * - Nothing about the Family: no membership, no activity entry (activity is
 *   Family-scoped and recorded by the calling Family action).
 *
 * Callers decide which values are allowed from their own input: the staff
 * registration flows always pass ALIVE and never forward a client life status.
 */
class CreatePersonAction
{
    /**
     * @param  array{
     *     full_name: string,
     *     life_status: string,
     *     national_id?: string|null,
     *     gender?: string|null,
     *     birth_date?: string|null,
     *     death_date?: string|null,
     *     marital_status?: string|null,
     *     mobile?: string|null,
     *     alternate_mobile?: string|null,
     *     alternate_mobile_owner_relation?: string|null,
     * }  $data
     * @param  string  $nationalIdField  Validation key used for a duplicate National ID.
     */
    public function handle(array $data, ?int $actingUserId, string $nationalIdField = 'national_id'): Person
    {
        $life = LifeStatus::tryFrom((string) ($data['life_status'] ?? ''));
        if ($life === null) {
            throw ValidationException::withMessages(['life_status' => 'حالة الحياة غير صالحة.']);
        }
        // full_name: required by the callers' validation and by persons.full_name
        // NOT NULL (kept as the final guard so existing flows behave exactly as before).
        $fullName = $data['full_name'] ?? null;
        $gender = $data['gender'] ?? null;
        if ($gender !== null && Gender::tryFrom($gender) === null) {
            throw ValidationException::withMessages(['gender' => 'الجنس غير صالح.']);
        }
        $marital = $data['marital_status'] ?? MaritalStatus::UNKNOWN->value;
        if (MaritalStatus::tryFrom($marital) === null) {
            throw ValidationException::withMessages(['marital_status' => 'الحالة الاجتماعية غير صالحة.']);
        }
        $birthDate = $data['birth_date'] ?? null;
        $deathDate = $data['death_date'] ?? null;
        if ($life === LifeStatus::DECEASED) {
            DeathDate::validate($deathDate, $birthDate);
        } elseif ($deathDate !== null) {
            throw ValidationException::withMessages(['death_date' => 'لا يُسجَّل تاريخ وفاة إلا لشخص متوفى.']);
        }

        return DB::transaction(function () use ($data, $actingUserId, $nationalIdField, $life, $fullName, $gender, $marital, $birthDate, $deathDate) {
            // Never a second Person with the same National ID (docs/03 §21).
            NationalIdGuard::assertAvailable($data['national_id'] ?? null, $nationalIdField);

            $personId = BusinessIdentifier::nextId('persons');

            // forceCreate, not create: `id` is deliberately not fillable, so
            // create() would silently drop the id reserved above; the INSERT
            // would then draw a second sequence value and the public code
            // would no longer match the row id (and codes would skip).
            return Person::forceCreate([
                'id' => $personId,
                'person_code' => BusinessIdentifier::format('PER', $personId),
                'full_name' => $fullName,
                'national_id' => $data['national_id'] ?? null,
                'gender' => $gender,
                'marital_status' => $marital,
                // NULL = unknown; never a placeholder (docs/03 §26).
                'birth_date' => $birthDate,
                'life_status' => $life->value,
                'death_date' => $deathDate,
                'mobile' => $data['mobile'] ?? null,
                'alternate_mobile' => $data['alternate_mobile'] ?? null,
                'alternate_mobile_owner_relation' => $data['alternate_mobile_owner_relation'] ?? null,
                'is_active' => true,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);
        });
    }
}
