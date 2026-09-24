<?php

namespace Tests\Feature\People;

use App\Enums\LifeStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Correcting the current household head's basic Person fields goes
 * through the existing PATCH /api/v1/people/{person}. It must never
 * change who the head is.
 */
class UpdateHouseholdHeadTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private Person $head;

    private FamilyMembership $headMembership;

    private Person $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);

        $this->family = Family::factory()->create();
        $this->head = Person::factory()->create([
            'full_name' => 'سامي عادل التجريبي',
            'gender' => 'MALE',
            'birth_date' => '1980-01-01',
            'mobile' => '0590000001',
            'alternate_mobile' => '0590000002',
            'alternate_mobile_owner_relation' => 'أحمد – أخ',
        ]);
        $this->headMembership = FamilyMembership::factory()->householdHead()->create([
            'family_id' => $this->family->id,
            'person_id' => $this->head->id,
            'relationship_type_id' => RelationshipType::where('code', 'HEAD')->value('id'),
        ]);
        $this->member = Person::factory()->create(['full_name' => 'فرد آخر']);
        FamilyMembership::factory()->create([
            'family_id' => $this->family->id,
            'person_id' => $this->member->id,
            'relationship_type_id' => RelationshipType::where('code', 'SON')->value('id'),
        ]);
    }

    private function user(string $role = 'SUPER_ADMIN'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function patchHead(array $payload, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())
            ->patchJson("/api/v1/people/{$this->head->person_code}", $payload);
    }

    public function test_updates_basic_head_fields(): void
    {
        $this->patchHead([
            'full_name' => 'سامي عادل محمد التجريبي',
            'gender' => 'FEMALE',
            'birth_date' => '1981-02-03',
            'mobile' => '0591111111',
        ])->assertOk()
            ->assertJsonPath('data.full_name', 'سامي عادل محمد التجريبي')
            ->assertJsonPath('data.gender', 'FEMALE')
            ->assertJsonPath('data.birth_date', '1981-02-03')
            ->assertJsonPath('data.mobile', '0591111111');

        // The family profile reflects the change through the same Person.
        $this->getJson("/api/v1/families/{$this->family->family_code}")
            ->assertJsonPath('data.members.0.full_name', 'سامي عادل محمد التجريبي');
    }

    public function test_alternate_mobile_and_owner_relation_update(): void
    {
        $this->patchHead([
            'alternate_mobile' => '0592222222',
            'alternate_mobile_owner_relation' => 'منى – أخت',
        ])->assertOk()
            ->assertJsonPath('data.alternate_mobile', '0592222222')
            ->assertJsonPath('data.alternate_mobile_owner_relation', 'منى – أخت');
    }

    public function test_owner_relation_alone_uses_stored_alternate_mobile(): void
    {
        $this->patchHead(['alternate_mobile_owner_relation' => 'منى – أخت'])
            ->assertOk()
            ->assertJsonPath('data.alternate_mobile_owner_relation', 'منى – أخت');
    }

    public function test_clearing_alternate_mobile_clears_owner_relation(): void
    {
        $this->patchHead(['alternate_mobile' => null])
            ->assertOk()
            ->assertJsonPath('data.alternate_mobile', null)
            ->assertJsonPath('data.alternate_mobile_owner_relation', null);

        $this->assertNull($this->head->fresh()->alternate_mobile_owner_relation);
    }

    public function test_owner_relation_without_alternate_mobile_is_rejected(): void
    {
        $this->patchHead([
            'alternate_mobile' => null,
            'alternate_mobile_owner_relation' => 'منى – أخت',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('alternate_mobile_owner_relation');

        $this->assertSame('أحمد – أخ', $this->head->fresh()->alternate_mobile_owner_relation);
    }

    public function test_life_status_is_not_changed_by_generic_update(): void
    {
        // docs/03 §30: recording death is a controlled operation, not a
        // generic field edit — life_status is not an accepted field.
        $this->patchHead(['full_name' => 'اسم', 'life_status' => 'DECEASED'])->assertOk();

        $this->assertSame(LifeStatus::ALIVE, $this->head->fresh()->life_status);
        $this->assertSame('ALIVE', $this->getJson("/api/v1/people/{$this->head->person_code}")->json('data.life_status'));
    }

    public function test_national_id_update_requires_national_id_permission(): void
    {
        $original = $this->head->fresh()->national_id;

        // person.update alone is not enough (docs/06 §39, §95).
        $this->patchHead(['national_id' => '999999999'])->assertStatus(403);
        $this->assertSame($original, $this->head->fresh()->national_id);
    }

    public function test_national_id_update_allowed_with_national_id_permission(): void
    {
        // No seeded role holds person.national-id.update yet; grant it to a
        // throwaway test role to prove the gate opens with the permission.
        $role = Role::create(['name' => 'TEST_NATIONAL_ID_EDITOR', 'guard_name' => 'web']);
        $role->givePermissionTo(['person.update', 'person.national-id.update']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->patchHead(['national_id' => '999999999'], $user)->assertOk();

        $this->assertSame('999999999', $this->head->fresh()->national_id);
    }

    public function test_national_id_is_not_exposed_by_person_or_family_responses(): void
    {
        $this->actingAs($this->user());

        $this->getJson("/api/v1/people/{$this->head->person_code}")
            ->assertOk()->assertJsonMissingPath('data.national_id');

        $this->getJson("/api/v1/families/{$this->family->family_code}")
            ->assertOk()->assertJsonMissingPath('data.members.0.national_id');
    }

    public function test_head_membership_identifiers_and_other_members_are_unchanged(): void
    {
        $headCode = $this->head->person_code;
        $familyCode = $this->family->family_code;
        $memberships = FamilyMembership::orderBy('id')->get()->toArray();
        $otherMember = $this->member->fresh()->toArray();
        $family = $this->family->fresh()->toArray();

        $this->patchHead([
            'full_name' => 'اسم مصحح',
            'mobile' => '0593333333',
            // Not accepted fields: ignored.
            'person_code' => 'PER-999999',
            'is_household_head' => false,
            'family_id' => 999,
        ])->assertOk()
            ->assertJsonPath('data.person_code', $headCode)
            ->assertJsonPath('data.family_membership.is_household_head', true)
            ->assertJsonPath('data.family_membership.relationship_type.code', 'HEAD');

        $this->assertSame($headCode, $this->head->fresh()->person_code);
        $this->assertSame($memberships, FamilyMembership::orderBy('id')->get()->toArray());
        $this->assertSame($otherMember, $this->member->fresh()->toArray());
        $this->assertSame($family, $this->family->fresh()->toArray());
        $this->assertSame($familyCode, $this->family->fresh()->family_code);
        $this->assertSame(2, Person::count());
        $this->assertSame(2, FamilyMembership::count());
        $this->assertSame(
            $this->head->id,
            FamilyMembership::where('family_id', $this->family->id)->where('is_household_head', true)->sole()->person_id
        );
    }

    public function test_administrator_and_data_entry_can_correct_basic_fields_but_not_national_id(): void
    {
        $originalNationalId = $this->head->fresh()->national_id;

        foreach (['ADMINISTRATOR', 'DATA_ENTRY'] as $role) {
            $user = $this->user($role);

            $this->patchHead(['full_name' => "اسم مصحح {$role}", 'mobile' => '0594444444'], $user)
                ->assertOk()
                ->assertJsonPath('data.full_name', "اسم مصحح {$role}")
                ->assertJsonMissingPath('data.national_id');

            // person.update alone never authorizes National ID changes.
            $this->patchHead(['national_id' => '999999999'], $user)->assertStatus(403);
            $this->patchHead(['full_name' => 'x', 'national_id' => '999999999'], $user)->assertStatus(403);
        }

        $this->assertSame($originalNationalId, $this->head->fresh()->national_id);
        $this->assertSame('اسم مصحح DATA_ENTRY', $this->head->fresh()->full_name);
    }

    public function test_roles_without_person_update_cannot_edit_person(): void
    {
        foreach (['REVIEWER', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->patchHead(['full_name' => 'ممنوع'], $this->user($role))->assertStatus(403);
        }

        $this->assertSame('سامي عادل التجريبي', $this->head->fresh()->full_name);
    }
}
