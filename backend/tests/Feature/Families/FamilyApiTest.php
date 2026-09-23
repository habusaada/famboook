<?php

namespace Tests\Feature\Families;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyApiTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(string $role = 'SUPER_ADMIN'): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function createFamilyWithHead(): Family
    {
        $family = Family::factory()->create();
        $head = Person::factory()->create(['full_name' => 'خالد يوسف النجار']);

        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => $head->id,
        ]);

        FamilyResidence::factory()->create([
            'family_id' => $family->id,
            'city' => 'عمّان',
        ]);

        return $family;
    }

    public function test_family_list_endpoint_returns_expected_shape(): void
    {
        $user = $this->authorizedUser();
        $this->createFamilyWithHead();
        $this->createFamilyWithHead();

        $response = $this->actingAs($user)->getJson('/api/v1/families');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'family_code',
                    'status',
                    'household_head_name',
                    'member_count',
                    'registration_date',
                    'updated_at',
                ],
            ],
            'links',
            'meta',
        ]);
        $response->assertJsonPath('data.0.household_head_name', 'خالد يوسف النجار');
        $response->assertJsonPath('data.0.member_count', 1);
    }

    public function test_family_list_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/families');

        $response->assertStatus(401);
    }

    public function test_family_list_requires_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(); // no role assigned

        $response = $this->actingAs($user)->getJson('/api/v1/families');

        $response->assertStatus(403);
    }

    public function test_family_detail_endpoint_returns_expected_shape(): void
    {
        $user = $this->authorizedUser();
        $family = $this->createFamilyWithHead();

        $response = $this->actingAs($user)->getJson("/api/v1/families/{$family->family_code}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'family_code',
                'status',
                'registration_date',
                'registration_source',
                'updated_at',
                'residence' => ['governorate', 'city'],
                'member_count',
                'male_count',
                'female_count',
                'members' => [
                    '*' => [
                        'person_code',
                        'full_name',
                        'gender',
                        'birth_date',
                        'is_household_head',
                        'is_active',
                    ],
                ],
            ],
        ]);
        $response->assertJsonPath('data.family_code', $family->family_code);
        $response->assertJsonPath('data.member_count', 1);
        $response->assertJsonPath('data.members.0.is_household_head', true);
        $response->assertJsonPath('data.residence.city', 'عمّان');
    }

    public function test_family_detail_returns_404_for_unknown_family_code(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->getJson('/api/v1/families/FAM-999999');

        $response->assertStatus(404);
    }

    public function test_family_detail_requires_authentication(): void
    {
        $family = $this->createFamilyWithHead();

        $response = $this->getJson("/api/v1/families/{$family->family_code}");

        $response->assertStatus(401);
    }
}
