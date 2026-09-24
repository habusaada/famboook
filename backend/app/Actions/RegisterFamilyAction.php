<?php

namespace App\Actions;

use App\Enums\DisplacementStatus;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\MaritalStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Support\BusinessIdentifier;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Registers a new Family together with its Household Head Person, the
 * corresponding active Family Membership, and the current Residence.
 *
 * docs/00-PROJECT-CONTEXT.md §111 "First Complete Staff Journey" and
 * docs/07-ROADMAP.md Phase 8/9 describe this as the core registry
 * vertical slice. The four records are created atomically: if any step
 * fails, nothing is persisted (docs/03-BUSINESS-RULES.md §103-105:
 * important operations are transactional Domain Actions).
 */
class RegisterFamilyAction
{
    /**
     * @param  array{
     *     registration_date: string,
     *     registration_source: string,
     *     paper_form_no?: string|null,
     *     notes?: string|null,
     *     household_head: array{
     *         full_name: string,
     *         national_id?: string|null,
     *         gender: string,
     *         birth_date: string,
     *         mobile?: string|null,
     *         alternate_mobile?: string|null,
     *         alternate_mobile_owner_relation?: string|null,
     *     },
     *     residence: array{
     *         governorate: string,
     *         city: string,
     *         area?: string|null,
     *         neighborhood?: string|null,
     *         address_text?: string|null,
     *         original_residence_text?: string|null,
     *         displacement_status?: string|null,
     *         displacement_location_text?: string|null,
     *         residence_type?: string|null,
     *         latitude?: float|null,
     *         longitude?: float|null,
     *     },
     * }  $data  Already-validated payload (see RegisterFamilyRequest).
     */
    public function handle(array $data, ?int $actingUserId): Family
    {
        return DB::transaction(function () use ($data, $actingUserId) {
            $familyId = BusinessIdentifier::nextId('families');

            // forceCreate, not create: `id` is deliberately not fillable, so
            // create() would silently drop the id reserved above; the INSERT
            // would then draw a second sequence value and the public code
            // would no longer match the row id (and codes would skip).
            $family = Family::forceCreate([
                'id' => $familyId,
                'family_code' => BusinessIdentifier::format('FAM', $familyId),
                'status' => 'ACTIVE',
                'registration_date' => $data['registration_date'],
                'registration_source' => $data['registration_source'],
                'paper_form_no' => $data['paper_form_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            $personId = BusinessIdentifier::nextId('persons');

            $head = $data['household_head'];
            // forceCreate keeps the reserved id (see above).
            $person = Person::forceCreate([
                'id' => $personId,
                'person_code' => BusinessIdentifier::format('PER', $personId),
                'full_name' => $head['full_name'],
                'national_id' => $head['national_id'] ?? null,
                'gender' => $head['gender'],
                'marital_status' => $head['marital_status'] ?? MaritalStatus::UNKNOWN->value,
                'birth_date' => $head['birth_date'],
                'life_status' => LifeStatus::ALIVE->value,
                'mobile' => $head['mobile'] ?? null,
                'alternate_mobile' => $head['alternate_mobile'] ?? null,
                'alternate_mobile_owner_relation' => $head['alternate_mobile_owner_relation'] ?? null,
                'is_active' => true,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            $membership = FamilyMembership::create([
                'family_id' => $family->id,
                'person_id' => $person->id,
                // Household head carries the canonical HEAD relationship
                // type (docs/02-DATA-DICTIONARY.md §15). Falls back to
                // null gracefully if relationship_types hasn't been
                // seeded yet, rather than failing family registration.
                'relationship_type_id' => RelationshipType::where('code', 'HEAD')->value('id'),
                'is_household_head' => true,
                'started_at' => $data['registration_date'],
                'is_active' => true,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            $residence = $data['residence'];
            $isDisplaced = ($residence['displacement_status'] ?? null) === DisplacementStatus::DISPLACED->value;
            FamilyResidence::create([
                'family_id' => $family->id,
                'residence_type' => $residence['residence_type'] ?? null,
                'governorate' => $residence['governorate'],
                'city' => $residence['city'],
                'area' => $residence['area'] ?? null,
                'neighborhood' => $residence['neighborhood'] ?? null,
                'address_text' => $residence['address_text'] ?? null,
                'original_residence_text' => $residence['original_residence_text'] ?? null,
                'latitude' => $residence['latitude'] ?? null,
                'longitude' => $residence['longitude'] ?? null,
                'displacement_status' => $residence['displacement_status'] ?? null,
                // A displacement location only exists for a displaced family.
                'displacement_location_text' => $isDisplaced
                    ? ($residence['displacement_location_text'] ?? null)
                    : null,
                'started_at' => $data['registration_date'],
                'is_current' => true,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            unset($membership);

            FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_CREATED, $family, $actingUserId);

            return $family->fresh([
                'householdHeadMembership.person',
                'memberships.person',
                'memberships.relationshipType',
                'currentResidence',
            ]);
        });
    }
}
