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

/**
 * PATCH /api/v1/families/{family} — correction of basic registration
 * metadata (docs/03 §56, permission family.update).
 */
class UpdateFamilyTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->family = Family::factory()->create([
            'registration_date' => '2026-09-01',
            'registration_source' => 'PAPER_FORM',
            'paper_form_no' => 'PF-100',
            'notes' => 'ملاحظة أصلية',
        ]);
        $head = Person::factory()->create();
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $this->family->id,
            'person_id' => $head->id,
        ]);
        FamilyResidence::factory()->create(['family_id' => $this->family->id]);
    }

    private function patchFamily(array $payload, string $role = 'SUPER_ADMIN')
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $this->actingAs($user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", $payload);
    }

    public function test_authorized_user_can_update_family_metadata(): void
    {
        $response = $this->patchFamily([
            'registration_date' => '2026-08-15',
            'paper_form_no' => 'PF-200',
            'notes' => 'ملاحظة مصححة',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.family_code', $this->family->family_code);
        $response->assertJsonPath('data.registration_date', '2026-08-15');
        $response->assertJsonPath('data.paper_form_no', 'PF-200');
        $response->assertJsonPath('data.notes', 'ملاحظة مصححة');

        $fresh = $this->family->fresh();
        $this->assertSame('2026-08-15', $fresh->registration_date->toDateString());
        $this->assertSame('PF-200', $fresh->paper_form_no);
    }

    public function test_partial_update_changes_only_sent_fields(): void
    {
        $this->patchFamily(['notes' => 'ملاحظة فقط'])->assertOk();

        $fresh = $this->family->fresh();
        $this->assertSame('ملاحظة فقط', $fresh->notes);
        $this->assertSame('PF-100', $fresh->paper_form_no);
        $this->assertSame('2026-09-01', $fresh->registration_date->toDateString());
    }

    public function test_optional_fields_can_be_cleared(): void
    {
        $this->patchFamily(['paper_form_no' => null, 'notes' => null])->assertOk();

        $fresh = $this->family->fresh();
        $this->assertNull($fresh->paper_form_no);
        $this->assertNull($fresh->notes);
    }

    public function test_immutable_fields_are_ignored(): void
    {
        $original = $this->family->fresh();

        $this->patchFamily([
            'notes' => 'تعديل',
            'family_code' => 'FAM-999999',
            'status' => 'ARCHIVED',
            'registration_source' => 'IMPORT',
            'id' => 9999,
            'created_at' => '2000-01-01',
        ])->assertOk()
            ->assertJsonPath('data.family_code', $original->family_code)
            ->assertJsonPath('data.status', 'ACTIVE');

        $fresh = $this->family->fresh();
        $this->assertSame($original->id, $fresh->id);
        $this->assertSame($original->family_code, $fresh->family_code);
        $this->assertSame($original->status, $fresh->status);
        $this->assertSame('PAPER_FORM', $fresh->registration_source->value);
        $this->assertEquals($original->created_at, $fresh->created_at);
    }

    public function test_members_and_residence_are_unchanged(): void
    {
        $memberships = FamilyMembership::orderBy('id')->get()->toArray();
        $residences = FamilyResidence::orderBy('id')->get()->toArray();
        $persons = Person::orderBy('id')->get()->toArray();

        $this->patchFamily(['registration_date' => '2026-08-15'])->assertOk();

        $this->assertSame($memberships, FamilyMembership::orderBy('id')->get()->toArray());
        $this->assertSame($residences, FamilyResidence::orderBy('id')->get()->toArray());
        $this->assertSame($persons, Person::orderBy('id')->get()->toArray());
        $this->assertSame(1, Family::count());
    }

    public function test_records_acting_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');

        $this->actingAs($user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", ['notes' => 'x'])
            ->assertOk();

        $this->assertSame($user->id, $this->family->fresh()->updated_by);
    }

    public function test_validation_errors(): void
    {
        $this->patchFamily(['registration_date' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration_date');

        $this->patchFamily(['registration_date' => 'not-a-date', 'paper_form_no' => str_repeat('x', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['registration_date', 'paper_form_no']);
    }

    public function test_requires_authentication(): void
    {
        $this->patchJson("/api/v1/families/{$this->family->family_code}", ['notes' => 'x'])
            ->assertStatus(401);
    }

    public function test_requires_family_update_permission(): void
    {
        foreach (['REVIEWER', 'REPORTS_VIEWER'] as $role) {
            $this->patchFamily(['notes' => 'x'], $role)->assertStatus(403);
        }

        $this->assertSame('ملاحظة أصلية', $this->family->fresh()->notes);
    }

    public function test_roles_with_family_update_can_edit(): void
    {
        foreach (['ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $this->patchFamily(['notes' => $role], $role)->assertOk();
        }
    }

    public function test_unknown_family_returns_404(): void
    {
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');

        $this->actingAs($user)->patchJson('/api/v1/families/FAM-999999', ['notes' => 'x'])
            ->assertNotFound();
    }
}
