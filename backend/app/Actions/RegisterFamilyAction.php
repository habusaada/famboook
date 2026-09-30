<?php

namespace App\Actions;

use App\Enums\LifeStatus;
use App\Enums\MaritalStatus;
use App\Models\Family;
use App\Support\NationalIdGuard;
use App\Support\RelationshipTypes;
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
    public function __construct(
        private readonly CreateFamilyAction $families = new CreateFamilyAction,
        private readonly CreatePersonAction $persons = new CreatePersonAction,
        private readonly CreateFamilyMembershipAction $memberships = new CreateFamilyMembershipAction,
        private readonly CreateFamilyResidenceAction $residences = new CreateFamilyResidenceAction,
    ) {}

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
     *         birth_date?: string|null,
     *         mobile?: string|null,
     *         alternate_mobile?: string|null,
     *         alternate_mobile_owner_relation?: string|null,
     *     },
     *     residence: array{
     *         governorate?: string|null,
     *         city?: string|null,
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
            // Clan/Branch are validated before anything is written.
            $this->families->lineage($data);
            // Never a second Person with the same National ID (docs/03 §21);
            // checked before the Family id is reserved so codes never skip.
            NationalIdGuard::assertAvailable($data['household_head']['national_id'] ?? null, 'household_head.national_id');
            // The household head carries the canonical HEAD relationship type
            // (docs/02 §15); a missing or inactive seed fails here, before any
            // registry write (MissingRelationshipTypeException).
            RelationshipTypes::required(RelationshipTypes::HEAD);

            // Same transaction: a failure in any later step rolls back the
            // Family and its FAMILY_CREATED activity too.
            $family = $this->families->handle($data, $actingUserId);

            $head = $data['household_head'];
            $person = $this->persons->handle([
                'full_name' => $head['full_name'],
                'national_id' => $head['national_id'] ?? null,
                'gender' => $head['gender'],
                'marital_status' => $head['marital_status'] ?? MaritalStatus::UNKNOWN->value,
                'birth_date' => $head['birth_date'] ?? null,
                // Staff registration always creates a living head; a client
                // never chooses the life status here.
                'life_status' => LifeStatus::ALIVE->value,
                'mobile' => $head['mobile'] ?? null,
                'alternate_mobile' => $head['alternate_mobile'] ?? null,
                'alternate_mobile_owner_relation' => $head['alternate_mobile_owner_relation'] ?? null,
            ], $actingUserId, 'household_head.national_id');

            $this->memberships->handle($family, $person, RelationshipTypes::HEAD, true, $data['registration_date'], $actingUserId);

            // Staff registration never states a residence source (NULL, as before).
            $this->residences->handle($family, [...$data['residence'], 'source' => null], $data['registration_date'], $actingUserId);

            // FAMILY_CREATED is recorded by CreateFamilyAction.
            return $family->fresh([
                'householdHeadMembership.person',
                'memberships.person',
                'memberships.relationshipType',
                'currentResidence',
            ]);
        });
    }
}
