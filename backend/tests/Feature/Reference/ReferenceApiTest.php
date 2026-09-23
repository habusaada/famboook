<?php

namespace Tests\Feature\Reference;

use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceApiTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(string $role = 'DATA_ENTRY'): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_relationship_types_are_seeded_with_documented_codes(): void
    {
        $this->seed(RelationshipTypeSeeder::class);

        $this->assertSame(7, RelationshipType::count());

        $codes = RelationshipType::orderBy('sort_order')->pluck('code')->all();
        $this->assertSame(
            ['HEAD', 'SPOUSE', 'SON', 'DAUGHTER', 'FATHER', 'MOTHER', 'OTHER'],
            $codes
        );

        $this->assertTrue(RelationshipType::where('is_active', true)->count() === 7);
    }

    public function test_relationship_type_seeding_is_idempotent(): void
    {
        $this->seed(RelationshipTypeSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);

        $this->assertSame(7, RelationshipType::count());
    }

    public function test_seeding_backfills_household_head_relationship_and_preserves_legacy_null(): void
    {
        $family = Family::factory()->create();

        $head = Person::factory()->create();
        $headMembership = FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => $head->id,
        ]);

        $nonHead = Person::factory()->create();
        $nonHeadMembership = FamilyMembership::factory()->create([
            'family_id' => $family->id,
            'person_id' => $nonHead->id,
        ]);

        $this->assertNull($headMembership->relationship_type_id);
        $this->assertNull($nonHeadMembership->relationship_type_id);

        $this->seed(RelationshipTypeSeeder::class);

        $headId = RelationshipType::where('code', 'HEAD')->value('id');
        $this->assertSame($headId, $headMembership->fresh()->relationship_type_id);

        // Non-head legacy membership is left untouched — its relationship
        // is never guessed (docs task J).
        $this->assertNull($nonHeadMembership->fresh()->relationship_type_id);
    }

    public function test_authorized_user_can_list_relationship_types(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->getJson('/api/v1/reference/relationship-types');

        $response->assertOk();
        $response->assertJsonCount(7, 'data');
        $response->assertJsonStructure(['data' => ['*' => ['id', 'code', 'name']]]);
        $response->assertJsonPath('data.0.code', 'HEAD');
        $response->assertJsonPath('data.1.code', 'SPOUSE');
    }

    public function test_relationship_types_endpoint_excludes_inactive(): void
    {
        $user = $this->authorizedUser();
        RelationshipType::where('code', 'OTHER')->update(['is_active' => false]);

        $response = $this->actingAs($user)->getJson('/api/v1/reference/relationship-types');

        $response->assertOk();
        $response->assertJsonCount(6, 'data');
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertNotContains('OTHER', $codes);
    }

    public function test_relationship_types_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reference/relationship-types');

        $response->assertStatus(401);
    }

    public function test_relationship_types_endpoint_requires_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('REVIEWER'); // does not hold reference-data.view

        $response = $this->actingAs($user)->getJson('/api/v1/reference/relationship-types');

        $response->assertStatus(403);
    }
}
