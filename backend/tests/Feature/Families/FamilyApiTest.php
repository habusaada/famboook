<?php

namespace Tests\Feature\Families;

use App\Actions\AddFamilyMemberAction;
use App\Actions\UpdateFamilyAction;
use App\Actions\UpdateFamilyResidenceAction;
use App\Models\Family;
use App\Models\FamilyHouseholdDeclaration;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
                        'relationship_type',
                    ],
                ],
            ],
        ]);
        $response->assertJsonPath('data.family_code', $family->family_code);
        $response->assertJsonPath('data.member_count', 1);
        $response->assertJsonPath('data.members.0.is_household_head', true);
        $response->assertJsonPath('data.residence.city', 'عمّان');
    }

    // ---- Registered members vs Declared Household Statistics (docs/02 §20a)

    /** Head + spouse registered (2 active), one ended membership, declared size 7. */
    private function createDeclaredFamily(): Family
    {
        $family = $this->createFamilyWithHead();
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => Person::factory()->create()->id]);
        FamilyMembership::factory()->create([
            'family_id' => $family->id, 'person_id' => Person::factory()->create()->id,
            'is_active' => false, 'ended_at' => '2026-01-01', 'end_reason' => 'OTHER',
        ]);
        // An older, non-current declaration must never be used.
        FamilyHouseholdDeclaration::factory()->create([
            'family_id' => $family->id, 'is_current' => false,
            'declared_household_size' => 3, 'declared_living_sons' => 1, 'declared_living_daughters' => 0,
        ]);
        FamilyHouseholdDeclaration::factory()->create([
            'family_id' => $family->id, 'is_current' => true,
            'declared_household_size' => 7, 'declared_living_sons' => 4, 'declared_living_daughters' => 1,
        ]);

        return $family;
    }

    public function test_family_list_exposes_the_declared_household_size_beside_the_registered_member_count(): void
    {
        $user = $this->authorizedUser();
        $undeclared = $this->createFamilyWithHead();
        $declared = $this->createDeclaredFamily();

        $rows = collect($this->actingAs($user)->getJson('/api/v1/families')->assertOk()->json('data'))->keyBy('family_code');

        // member_count keeps its meaning: ACTIVE memberships only — never the declared size.
        $this->assertSame(2, $rows[$declared->family_code]['member_count']);
        $this->assertSame(7, $rows[$declared->family_code]['declared_household_size']);
        // No declaration: the key is present and null; the member count is unaffected.
        $this->assertArrayHasKey('declared_household_size', $rows[$undeclared->family_code]);
        $this->assertNull($rows[$undeclared->family_code]['declared_household_size']);
        $this->assertSame(1, $rows[$undeclared->family_code]['member_count']);
    }

    public function test_family_detail_exposes_the_declared_statistics_as_independent_facts(): void
    {
        $user = $this->authorizedUser();
        $family = $this->createDeclaredFamily();

        $this->actingAs($user)->getJson("/api/v1/families/{$family->family_code}")->assertOk()
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.declared_household_size', 7)
            ->assertJsonPath('data.declared_living_sons', 4)
            ->assertJsonPath('data.declared_living_daughters', 1)
            ->assertJsonCount(2, 'data.members');
    }

    public function test_family_detail_returns_null_declared_statistics_when_nothing_was_declared(): void
    {
        $user = $this->authorizedUser();
        $family = $this->createFamilyWithHead();
        // A declaration may state only some values: the rest stay null, never computed.
        $partial = $this->createFamilyWithHead();
        FamilyHouseholdDeclaration::factory()->create([
            'family_id' => $partial->id, 'declared_household_size' => 9, 'declared_living_sons' => null, 'declared_living_daughters' => null,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/families/{$family->family_code}")->assertOk();
        foreach (['declared_household_size', 'declared_living_sons', 'declared_living_daughters'] as $key) {
            $this->assertArrayHasKey($key, $response->json('data'));
            $this->assertNull($response->json("data.{$key}"));
        }
        $response->assertJsonPath('data.member_count', 1);

        $this->actingAs($user)->getJson("/api/v1/families/{$partial->family_code}")->assertOk()
            ->assertJsonPath('data.declared_household_size', 9)
            ->assertJsonPath('data.declared_living_sons', null)
            ->assertJsonPath('data.declared_living_daughters', null);
    }

    public function test_family_list_query_count_does_not_grow_with_the_number_of_families(): void
    {
        $user = $this->authorizedUser();
        $count = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->getJson('/api/v1/families')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->createDeclaredFamily();
        $this->createFamilyWithHead();
        $count(); // warm-up: one-off queries (permission cache) are not part of the comparison
        $few = $count();
        foreach (range(1, 4) as $i) {
            $this->createDeclaredFamily();
        }

        $this->assertSame($few, $count());
    }

    public function test_single_family_responses_get_the_household_declaration_already_loaded(): void
    {
        $user = $this->authorizedUser();
        $family = $this->createDeclaredFamily();

        // The actions hand the resource a Family with the declaration loaded
        // (the list is covered by the query-count test above).
        $updated = app(UpdateFamilyAction::class)->handle($family, ['notes' => 'ملاحظة'], $user->id);
        $this->assertTrue($updated->relationLoaded('currentHouseholdDeclaration'));
        $moved = app(UpdateFamilyResidenceAction::class)->handle($family, ['city' => 'خانيونس'], $user->id);
        $this->assertTrue($moved->relationLoaded('currentHouseholdDeclaration'));

        $this->actingAs($user)->patchJson("/api/v1/families/{$family->family_code}", ['notes' => 'ملاحظة أخرى'])
            ->assertOk()->assertJsonPath('data.declared_household_size', 7)->assertJsonPath('data.member_count', 2);
        $this->actingAs($user)->patchJson("/api/v1/families/{$family->family_code}/residence", ['city' => 'رفح'])
            ->assertOk()->assertJsonPath('data.declared_household_size', 7)->assertJsonPath('data.member_count', 2);
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

    public function test_family_detail_returns_relationship_label_for_added_member(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        $user = $this->authorizedUser();
        $family = $this->createFamilyWithHead();
        $sonId = RelationshipType::where('code', 'SON')->value('id');

        (new AddFamilyMemberAction)->handle($family, [
            'full_name' => 'يوسف خالد النجار',
            'gender' => 'MALE',
            'birth_date' => '2015-05-01',
            'relationship_type_id' => $sonId,
        ], null);

        $response = $this->actingAs($user)->getJson("/api/v1/families/{$family->family_code}");

        $response->assertOk();
        $newMember = collect($response->json('data.members'))
            ->firstWhere('full_name', 'يوسف خالد النجار');

        $this->assertNotNull($newMember);
        $this->assertSame('SON', $newMember['relationship_type']['code']);
        $this->assertSame('ابن', $newMember['relationship_type']['name']);

        // Legacy head membership (created via factory, not seeded/backfilled
        // in this test) still returns a null relationship_type, not a guess.
        $head = collect($response->json('data.members'))->firstWhere('is_household_head', true);
        $this->assertNull($head['relationship_type']);
    }
}
