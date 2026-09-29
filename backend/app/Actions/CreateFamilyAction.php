<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\FamilyStatus;
use App\Enums\RegistrationSource;
use App\Models\Family;
use App\Support\BusinessIdentifier;
use App\Support\FamilyActivityLog;
use App\Support\FamilyLineage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical creation of ONE Family record (docs/03 §7a, §96b) — an internal
 * building block, not an endpoint. RegisterFamilyAction uses it; a future
 * import Apply will too.
 *
 * - Clan required, Branch optional, both by code and validated by
 *   FamilyLineage (Branch inside the Clan and selectable; NULL = no Branch).
 * - family_code from the reserved id (BusinessIdentifier, FAM-000001).
 * - status ACTIVE; registration_source is a RegistrationSource value;
 *   registration_date is required (the date the Family enters the registry).
 * - FAMILY_CREATED is recorded on the new Family (no metadata).
 *
 * Only the Family row: no Person, membership, residence or declaration.
 */
class CreateFamilyAction
{
    /**
     * Validates Clan / Branch without writing anything; returns the resolved ids.
     *
     * @return array{clan_id: int, branch_id: ?int}
     */
    public function lineage(array $data): array
    {
        $family = new Family;
        FamilyLineage::apply($family, $data);

        return ['clan_id' => (int) $family->clan_id, 'branch_id' => $family->branch_id];
    }

    /**
     * @param  array{
     *     clan_code: string,
     *     branch_code?: string|null,
     *     registration_date: string,
     *     registration_source: string,
     *     paper_form_no?: string|null,
     *     notes?: string|null,
     * }  $data
     */
    public function handle(array $data, ?int $actingUserId): Family
    {
        // Clan / Branch are validated before anything is written.
        $lineage = $this->lineage($data);
        if (RegistrationSource::tryFrom((string) ($data['registration_source'] ?? '')) === null) {
            throw ValidationException::withMessages(['registration_source' => 'مصدر التسجيل غير صالح.']);
        }
        if (($data['registration_date'] ?? null) === null) {
            throw ValidationException::withMessages(['registration_date' => 'تاريخ التسجيل مطلوب.']);
        }

        return DB::transaction(function () use ($data, $actingUserId, $lineage) {
            $familyId = BusinessIdentifier::nextId('families');

            // forceCreate, not create: `id` is deliberately not fillable, so
            // create() would silently drop the id reserved above; the INSERT
            // would then draw a second sequence value and the public code
            // would no longer match the row id (and codes would skip).
            $family = Family::forceCreate([
                'id' => $familyId,
                'family_code' => BusinessIdentifier::format('FAM', $familyId),
                'clan_id' => $lineage['clan_id'],
                'branch_id' => $lineage['branch_id'],
                'status' => FamilyStatus::ACTIVE->value,
                'registration_date' => $data['registration_date'],
                'registration_source' => $data['registration_source'],
                'paper_form_no' => $data['paper_form_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_CREATED, $family, $actingUserId);

            return $family;
        });
    }
}
