<?php

namespace Tests\Feature\People;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonApiTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(string $role = 'SUPER_ADMIN'): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function personWithMembership(): array
    {
        $family = Family::factory()->create();
        $person = Person::factory()->create([
            'full_name' => 'خالد يوسف النجار',
            'mobile' => '0790000000',
        ]);
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
        ]);

        return [$person, $family];
    }

    public function test_authorized_user_can_view_a_person(): void
    {
        $user = $this->authorizedUser();
        [$person, $family] = $this->personWithMembership();

        $response = $this->actingAs($user)->getJson("/api/v1/people/{$person->person_code}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'person_code',
                'full_name',
                'gender',
                'birth_date',
                'mobile',
                'alternate_mobile',
                'life_status',
                'is_active',
                'family_membership' => ['family_code', 'is_household_head', 'relationship_type', 'started_at'],
            ],
        ]);
        $response->assertJsonPath('data.full_name', 'خالد يوسف النجار');
        $response->assertJsonPath('data.family_membership.family_code', $family->family_code);
        $response->assertJsonPath('data.family_membership.is_household_head', true);
        $response->assertJsonMissingPath('data.national_id');
    }

    public function test_person_response_includes_relationship_type_when_present(): void
    {
        $this->seed(\Database\Seeders\RelationshipTypeSeeder::class);
        $user = $this->authorizedUser();

        $family = Family::factory()->create();
        $person = Person::factory()->create(['full_name' => 'سارة أحمد']);
        $daughterId = \App\Models\RelationshipType::where('code', 'DAUGHTER')->value('id');
        FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => $person->id,
            'relationship_type_id' => $daughterId,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/people/{$person->person_code}");

        $response->assertOk();
        $response->assertJsonPath('data.family_membership.relationship_type.code', 'DAUGHTER');
        $response->assertJsonPath('data.family_membership.relationship_type.name', 'ابنة');
    }

    public function test_person_response_relationship_type_is_null_for_legacy_membership(): void
    {
        $user = $this->authorizedUser();
        [$person] = $this->personWithMembership(); // no relationship_type_id set

        $response = $this->actingAs($user)->getJson("/api/v1/people/{$person->person_code}");

        $response->assertOk();
        $response->assertJsonPath('data.family_membership.relationship_type', null);
    }

    public function test_person_detail_returns_404_for_unknown_person_code(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->getJson('/api/v1/people/PER-999999');

        $response->assertStatus(404);
    }

    public function test_person_detail_requires_authentication(): void
    {
        [$person] = $this->personWithMembership();

        $response = $this->getJson("/api/v1/people/{$person->person_code}");

        $response->assertStatus(401);
    }

    public function test_person_detail_requires_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(); // no role
        [$person] = $this->personWithMembership();

        $response = $this->actingAs($user)->getJson("/api/v1/people/{$person->person_code}");

        $response->assertStatus(403);
    }

    public function test_authorized_user_can_update_a_person(): void
    {
        $user = $this->authorizedUser();
        [$person] = $this->personWithMembership();

        $response = $this->actingAs($user)->patchJson("/api/v1/people/{$person->person_code}", [
            'full_name' => 'خالد يوسف النجار المحدّث',
            'mobile' => '0791111111',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.full_name', 'خالد يوسف النجار المحدّث');
        $response->assertJsonPath('data.mobile', '0791111111');

        $this->assertSame('خالد يوسف النجار المحدّث', $person->fresh()->full_name);
        $this->assertSame('0791111111', $person->fresh()->mobile);
    }

    public function test_update_person_is_partial_and_does_not_require_all_fields(): void
    {
        $user = $this->authorizedUser();
        [$person] = $this->personWithMembership();
        $originalMobile = $person->mobile;

        $response = $this->actingAs($user)->patchJson("/api/v1/people/{$person->person_code}", [
            'alternate_mobile' => '0792222222',
        ]);

        $response->assertOk();
        $this->assertSame('0792222222', $person->fresh()->alternate_mobile);
        $this->assertSame($originalMobile, $person->fresh()->mobile);
    }

    public function test_update_person_rejects_future_birth_date(): void
    {
        $user = $this->authorizedUser();
        [$person] = $this->personWithMembership();

        $response = $this->actingAs($user)->patchJson("/api/v1/people/{$person->person_code}", [
            'birth_date' => now()->addYear()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['birth_date']);
    }

    public function test_update_person_does_not_accept_household_head_or_life_status_fields(): void
    {
        $user = $this->authorizedUser();
        [$person, $family] = $this->personWithMembership();

        // These fields aren't in the validated rule set, so Laravel
        // simply ignores them (no mass-assignment protection bypass) —
        // confirms the endpoint cannot be used to flip household head
        // or life status.
        $this->actingAs($user)->patchJson("/api/v1/people/{$person->person_code}", [
            'life_status' => 'DECEASED',
            'is_household_head' => false,
        ])->assertOk();

        $this->assertSame('ALIVE', $person->fresh()->life_status->value);
        $membership = FamilyMembership::where('person_id', $person->id)->where('family_id', $family->id)->first();
        $this->assertTrue($membership->is_household_head);
    }

    public function test_update_person_requires_authentication(): void
    {
        [$person] = $this->personWithMembership();

        $response = $this->patchJson("/api/v1/people/{$person->person_code}", [
            'full_name' => 'محاولة بدون تسجيل دخول',
        ]);

        $response->assertStatus(401);
    }

    public function test_update_person_requires_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('DATA_ENTRY'); // has person.create but not person.update
        [$person] = $this->personWithMembership();

        $response = $this->actingAs($user)->patchJson("/api/v1/people/{$person->person_code}", [
            'full_name' => 'محاولة بدون صلاحية',
        ]);

        $response->assertStatus(403);
    }
}
