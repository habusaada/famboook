<?php

namespace Tests\Feature\Registry;

use App\Models\RelationshipType;
use App\Models\User;
use Database\Seeders\ClanSeeder;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Testing\TestResponse;

/** Synthetic Families / Persons registered through the real API. */
trait BuildsRegistryFixtures
{
    protected User $staff;

    protected function setUpRegistry(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ClanSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->staff = $this->user('DATA_ENTRY');
    }

    protected function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $head */
    protected function register(array $head = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff)->postJson('/api/v1/families', [
            'registration_date' => '2026-09-01',
            'registration_source' => 'PAPER_FORM',
            'clan_code' => 'AL_BREEM',
            'household_head' => [
                'full_name' => 'رب أسرة تجريبي',
                'gender' => 'MALE',
                'birth_date' => '1980-01-15',
                ...$head,
            ],
            'residence' => ['governorate' => 'خانيونس', 'city' => 'خانيونس'],
        ]);
    }

    /** @return array{family_code: string, person_code: string} */
    protected function family(string $headName, ?string $nationalId = null): array
    {
        $response = $this->register(['full_name' => $headName, 'national_id' => $nationalId])->assertCreated();

        return [
            'family_code' => $response->json('data.family_code'),
            'person_code' => $response->json('data.members.0.person_code'),
        ];
    }

    /** @param array<string, mixed> $member */
    protected function addMember(string $familyCode, array $member = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff)->postJson("/api/v1/families/{$familyCode}/members", [
            'full_name' => 'فرد تجريبي',
            'gender' => 'FEMALE',
            'birth_date' => '2010-05-05',
            'relationship_type_id' => RelationshipType::where('code', 'DAUGHTER')->value('id'),
            ...$member,
        ]);
    }

    /** Asserts no internal numeric id key, and none of $secrets, in a JSON response. */
    protected function assertNoLeak(TestResponse $response, array $secrets = []): void
    {
        $json = $response->json();
        array_walk_recursive($json, function ($value, $key) {
            $this->assertFalse(
                ($key === 'id' || str_ends_with((string) $key, '_id')) && is_int($value),
                "Internal numeric id '{$key}' exposed."
            );
        });
        foreach (['national_id', 'condition_name', 'password', 'remember_token', ...$secrets] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }
}
