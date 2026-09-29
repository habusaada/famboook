<?php

namespace App\Actions;

use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\MaritalStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Support\FamilyActivityLog;
use Illuminate\Support\Facades\DB;

/**
 * Adds a new member to an existing Family: creates the Person and an
 * active (non-household-head) FamilyMembership, atomically.
 *
 * Never touches the Family's existing household-head membership, never
 * creates a Residence, never creates another Family (docs/03-BUSINESS-
 * RULES.md §11-13: membership changes preserve history and use
 * controlled Domain Actions; this only ever adds new state).
 */
class AddFamilyMemberAction
{
    public function __construct(private readonly CreatePersonAction $persons = new CreatePersonAction) {}

    /**
     * @param  array{
     *     full_name: string,
     *     national_id?: string|null,
     *     gender: string,
     *     birth_date?: string|null,
     *     mobile?: string|null,
     *     alternate_mobile?: string|null,
     *     relationship_type_id: int,
     * }  $data  Already-validated payload (see AddFamilyMemberRequest).
     */
    public function handle(Family $family, array $data, ?int $actingUserId): FamilyMembership
    {
        return DB::transaction(function () use ($family, $data, $actingUserId) {
            // Never a second Person with the same National ID (docs/03 §21):
            // no new Person, no attaching the existing one, no merge
            // (enforced by CreatePersonAction). Staff always add a living
            // member; a client never chooses the life status here.
            $person = $this->persons->handle([
                'full_name' => $data['full_name'],
                'national_id' => $data['national_id'] ?? null,
                'gender' => $data['gender'],
                'marital_status' => $data['marital_status'] ?? MaritalStatus::UNKNOWN->value,
                'birth_date' => $data['birth_date'] ?? null,
                'life_status' => LifeStatus::ALIVE->value,
                'mobile' => $data['mobile'] ?? null,
                'alternate_mobile' => $data['alternate_mobile'] ?? null,
            ], $actingUserId);

            $membership = FamilyMembership::create([
                'family_id' => $family->id,
                'person_id' => $person->id,
                'relationship_type_id' => $data['relationship_type_id'],
                'is_household_head' => false,
                'started_at' => now()->toDateString(),
                'is_active' => true,
                'created_by' => $actingUserId,
                'updated_by' => $actingUserId,
            ]);

            FamilyActivityLog::record($family->id, FamilyActivityType::FAMILY_MEMBER_ADDED, $person, $actingUserId);

            return $membership->load(['person', 'relationshipType']);
        });
    }
}
