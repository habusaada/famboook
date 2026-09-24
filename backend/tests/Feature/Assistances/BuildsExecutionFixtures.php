<?php

namespace Tests\Feature\Assistances;

use App\Models\Assistance;
use App\Models\AssistanceBeneficiary;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use Database\Seeders\RelationshipTypeSeeder;

/**
 * Households with explicit relationships (relative to the household head),
 * marital status and synthetic National IDs, for Assistance V1-B tests.
 * Every identity value here is invented.
 */
trait BuildsExecutionFixtures
{
    use BuildsAssistanceFixtures;

    protected function setUpExecutionFixtures(): void
    {
        $this->setUpAssistanceFixtures();
        $this->seed(RelationshipTypeSeeder::class);
    }

    /**
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $members  key => [relationship code, person attributes]
     * @return array{family: Family, head: Person, people: array<string, Person>}
     */
    protected function household(array $head = [], array $members = [], bool $withHead = true, array $familyAttributes = []): array
    {
        $family = Family::factory()->create($familyAttributes);
        FamilyResidence::factory()->create([
            'family_id' => $family->id,
            'displacement_status' => 'DISPLACED',
            'displacement_location_text' => 'مخيم تجريبي',
            'original_residence_text' => 'حي أصلي تجريبي',
        ]);

        $headPerson = Person::factory()->create([
            'full_name' => 'رب أسرة تنفيذ',
            'gender' => 'MALE',
            'marital_status' => 'MARRIED',
            'birth_date' => '1975-01-01',
            'national_id' => '800000001',
            'mobile' => '0590000001',
            ...$head,
        ]);
        if ($withHead) {
            $this->membership($family, $headPerson, 'HEAD', true);
        } else {
            $this->membership($family, $headPerson, 'OTHER', false);
        }

        $people = [];
        foreach ($members as $key => [$relationship, $attributes]) {
            $people[$key] = Person::factory()->create([
                'gender' => 'FEMALE',
                'marital_status' => 'SINGLE',
                'birth_date' => '2002-01-01',
                ...array_diff_key($attributes, ['_inactive' => true]),
            ]);
            $this->membership($family, $people[$key], $relationship, false, $attributes['_inactive'] ?? false);
        }

        return ['family' => $family, 'head' => $headPerson, 'people' => $people];
    }

    protected function membership(Family $family, Person $person, string $relationship, bool $head, bool $inactive = false): FamilyMembership
    {
        return FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
            'is_household_head' => $head,
            'relationship_type_id' => RelationshipType::where('code', $relationship)->value('id'),
            'is_active' => ! $inactive,
            'ended_at' => $inactive ? '2026-09-01' : null,
        ]);
    }

    protected function openAssistanceOf(string $mode, array $overrides = []): Assistance
    {
        return $this->openAssistance(['execution_mode' => $mode, ...$overrides]);
    }

    /** Nominates (manually) and returns the beneficiary row. */
    protected function nominate(Assistance $assistance, Family $family, ?Person $person = null): AssistanceBeneficiary
    {
        $id = $this->actingAs($this->user)
            ->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/manual", [
                'family_code' => $family->family_code,
                'person_code' => $person?->person_code,
            ])->assertCreated()->json('data.id');

        return AssistanceBeneficiary::where('uuid', $id)->firstOrFail();
    }

    protected function approve(Assistance $assistance, AssistanceBeneficiary $beneficiary, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$beneficiary->uuid}/approve");
    }

    protected function approvedBeneficiary(Assistance $assistance, Family $family, ?Person $person = null): AssistanceBeneficiary
    {
        $beneficiary = $this->nominate($assistance, $family, $person);
        $this->approve($assistance, $beneficiary)->assertOk();

        return $beneficiary->fresh();
    }

    protected function reject(Assistance $assistance, AssistanceBeneficiary $beneficiary, ?string $reason = 'سبب رفض تجريبي', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->postJson("/api/v1/assistances/{$assistance->uuid}/nominees/{$beneficiary->uuid}/reject", $reason === null ? [] : ['rejection_reason' => $reason]);
    }

    protected function complete(Assistance $assistance, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson("/api/v1/assistances/{$assistance->uuid}/complete");
    }

    protected function statistics(Assistance $assistance): array
    {
        return $this->actingAs($this->user)->getJson("/api/v1/assistances/{$assistance->uuid}")->assertOk()->json('statistics');
    }
}
