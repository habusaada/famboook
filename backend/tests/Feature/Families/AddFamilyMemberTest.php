<?php

namespace Tests\Feature\Families;

use App\Actions\AddFamilyMemberAction;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddFamilyMemberTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(?int $relationshipTypeId = null): array
    {
        return [
            'full_name' => 'أحمد محمد الشريف',
            'national_id' => null,
            'gender' => 'MALE',
            'birth_date' => '2008-01-10',
            'mobile' => null,
            'relationship_type_id' => $relationshipTypeId ?? $this->relationshipTypeId('SON'),
        ];
    }

    private function relationshipTypeId(string $code): ?int
    {
        return RelationshipType::where('code', $code)->value('id');
    }

    private function authorizedUser(string $role = 'SUPER_ADMIN'): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function familyWithHousehold(): array
    {
        $family = Family::factory()->create();
        $head = Person::factory()->create();
        $headMembership = FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => $head->id,
        ]);

        return [$family, $head, $headMembership];
    }

    public function test_authorized_user_can_add_a_family_member(): void
    {
        $user = $this->authorizedUser();
        [$family] = $this->familyWithHousehold();

        $response = $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $this->validPayload()
        );

        $response->assertCreated();
        $response->assertJsonPath('data.full_name', 'أحمد محمد الشريف');
        $response->assertJsonPath('data.is_household_head', false);
        $response->assertJsonPath('data.is_active', true);
        $response->assertJsonPath('data.relationship_type.code', 'SON');
        $response->assertJsonPath('data.relationship_type.name', 'ابن');

        $personCode = $response->json('data.person_code');
        $this->assertMatchesRegularExpression('/^PER-\d{6}$/', $personCode);
    }

    public function test_adding_a_member_creates_person_and_membership_without_touching_household_head(): void
    {
        $user = $this->authorizedUser();
        [$family, $head, $headMembership] = $this->familyWithHousehold();

        $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $this->validPayload()
        )->assertCreated();

        // Person created.
        $this->assertSame(2, Person::count()); // head + new member
        $newPerson = Person::where('full_name', 'أحمد محمد الشريف')->first();
        $this->assertNotNull($newPerson);
        $this->assertSame('ALIVE', $newPerson->life_status->value);

        // FamilyMembership created for the new member.
        $this->assertSame(2, FamilyMembership::count());
        $newMembership = FamilyMembership::where('person_id', $newPerson->id)->first();
        $this->assertFalse($newMembership->is_household_head);
        $this->assertTrue($newMembership->is_active);
        $this->assertSame($family->id, $newMembership->family_id);

        // Household head remains unchanged: still the same person,
        // still household head, still active — no second head created.
        $headMembership->refresh();
        $this->assertTrue($headMembership->is_household_head);
        $this->assertTrue($headMembership->is_active);
        $this->assertSame($head->id, $headMembership->person_id);

        $this->assertSame(
            1,
            FamilyMembership::where('family_id', $family->id)
                ->where('is_household_head', true)
                ->where('is_active', true)
                ->count()
        );
    }

    public function test_adding_a_member_is_transactional_and_rolls_back_on_failure(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        [$family] = $this->familyWithHousehold();
        $personCountBefore = Person::count();
        $membershipCountBefore = FamilyMembership::count();

        $action = new AddFamilyMemberAction;
        $payload = $this->validPayload();
        $payload['full_name'] = null; // forces a NOT NULL violation on the second insert

        try {
            $action->handle($family, $payload, null);
            $this->fail('Expected a database exception to be thrown.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame($personCountBefore, Person::count());
        $this->assertSame($membershipCountBefore, FamilyMembership::count());
    }

    public function test_adding_a_member_to_unknown_family_returns_404(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->postJson(
            '/api/v1/families/FAM-999999/members',
            $this->validPayload()
        );

        $response->assertStatus(404);
    }

    public function test_adding_a_member_fails_validation_with_missing_required_fields(): void
    {
        $user = $this->authorizedUser();
        [$family] = $this->familyWithHousehold();

        $response = $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            []
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['full_name', 'gender', 'birth_date', 'relationship_type_id']);
    }

    public function test_unauthenticated_user_cannot_add_a_family_member(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        [$family] = $this->familyWithHousehold();

        $response = $this->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $this->validPayload()
        );

        $response->assertStatus(401);
    }

    public function test_user_without_permission_cannot_add_a_family_member(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('REPORTS_VIEWER');
        [$family] = $this->familyWithHousehold();

        $response = $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $this->validPayload()
        );

        $response->assertStatus(403);
    }

    public function test_active_membership_uniqueness_constraint_still_applies(): void
    {
        [$familyA] = $this->familyWithHousehold();
        $familyB = Family::factory()->create();
        $person = Person::factory()->create();

        FamilyMembership::factory()->create([
            'family_id' => $familyA->id,
            'person_id' => $person->id,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        FamilyMembership::factory()->create([
            'family_id' => $familyB->id,
            'person_id' => $person->id,
            'is_active' => true,
        ]);
    }

    public function test_relationship_type_is_persisted_on_the_membership(): void
    {
        $user = $this->authorizedUser();
        [$family] = $this->familyWithHousehold();
        $daughterId = $this->relationshipTypeId('DAUGHTER');

        $response = $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $this->validPayload($daughterId)
        );

        $response->assertCreated();
        $personCode = $response->json('data.person_code');
        $person = Person::where('person_code', $personCode)->first();
        $membership = FamilyMembership::where('person_id', $person->id)->first();

        $this->assertSame($daughterId, $membership->relationship_type_id);
    }

    public function test_adding_a_member_with_nonexistent_relationship_type_is_rejected(): void
    {
        $user = $this->authorizedUser();
        [$family] = $this->familyWithHousehold();

        $response = $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $this->validPayload(999999)
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['relationship_type_id']);
    }

    public function test_adding_a_member_with_inactive_relationship_type_is_rejected(): void
    {
        $user = $this->authorizedUser();
        [$family] = $this->familyWithHousehold();

        $inactive = RelationshipType::where('code', 'OTHER')->first();
        $inactive->update(['is_active' => false]);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $this->validPayload($inactive->id)
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['relationship_type_id']);
    }

    public function test_adding_a_member_with_head_relationship_type_is_rejected(): void
    {
        $user = $this->authorizedUser();
        [$family] = $this->familyWithHousehold();

        $response = $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $this->validPayload($this->relationshipTypeId('HEAD'))
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['relationship_type_id']);
    }

    public function test_adding_a_member_without_relationship_type_is_rejected(): void
    {
        $user = $this->authorizedUser();
        [$family] = $this->familyWithHousehold();
        $payload = $this->validPayload();
        unset($payload['relationship_type_id']);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/families/{$family->family_code}/members",
            $payload
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['relationship_type_id']);
    }
}
