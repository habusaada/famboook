<?php

namespace Tests\Feature\Families;

use App\Enums\DisplacementStatus;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH /api/v1/families/{family}/residence — in-place correction of the
 * current residence (docs/03 §56, permission residence.update).
 */
class UpdateFamilyResidenceTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private Person $head;

    private FamilyMembership $membership;

    private FamilyResidence $residence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->family = Family::factory()->create();
        $this->head = Person::factory()->create();
        $this->membership = FamilyMembership::factory()->householdHead()->create([
            'family_id' => $this->family->id,
            'person_id' => $this->head->id,
        ]);
        $this->residence = FamilyResidence::factory()->create([
            'family_id' => $this->family->id,
            'governorate' => 'خانيونس',
            'city' => 'بني سهيلا',
            'area' => 'الشرقية',
            'displacement_status' => null,
        ]);
    }

    private function user(string $role = 'SUPER_ADMIN'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function patchResidence(array $payload, string $role = 'SUPER_ADMIN')
    {
        return $this->actingAs($this->user($role))
            ->patchJson("/api/v1/families/{$this->family->family_code}/residence", $payload);
    }

    public function test_authorized_user_can_update_current_residence(): void
    {
        $response = $this->patchResidence(['city' => 'خانيونس']);

        $response->assertOk();
        $response->assertJsonPath('data.family_code', $this->family->family_code);
        $response->assertJsonPath('data.residence.city', 'خانيونس');
    }

    public function test_updates_original_residence(): void
    {
        $this->patchResidence(['original_residence_text' => 'بني سهيلا – خانيونس'])
            ->assertOk()
            ->assertJsonPath('data.residence.original_residence_text', 'بني سهيلا – خانيونس');

        $this->assertSame('بني سهيلا – خانيونس', $this->residence->fresh()->original_residence_text);
    }

    public function test_displaced_with_location(): void
    {
        $this->patchResidence([
            'displacement_status' => 'DISPLACED',
            'displacement_location_text' => 'مواصي خانيونس',
        ])->assertOk()
            ->assertJsonPath('data.residence.displacement_status', 'DISPLACED')
            ->assertJsonPath('data.residence.displacement_location_text', 'مواصي خانيونس');
    }

    public function test_displaced_location_is_optional(): void
    {
        $this->patchResidence(['displacement_status' => 'DISPLACED'])
            ->assertOk()
            ->assertJsonPath('data.residence.displacement_status', 'DISPLACED')
            ->assertJsonPath('data.residence.displacement_location_text', null);
    }

    public function test_switching_to_not_displaced_clears_stored_location(): void
    {
        $this->residence->update([
            'displacement_status' => DisplacementStatus::DISPLACED,
            'displacement_location_text' => 'مواصي خانيونس',
        ]);

        // Location omitted: the action still clears the stale value.
        $this->patchResidence(['displacement_status' => 'NOT_DISPLACED'])
            ->assertOk()
            ->assertJsonPath('data.residence.displacement_status', 'NOT_DISPLACED')
            ->assertJsonPath('data.residence.displacement_location_text', null);

        $this->assertNull($this->residence->fresh()->displacement_location_text);
    }

    public function test_switching_to_unknown_clears_stored_location(): void
    {
        $this->residence->update([
            'displacement_status' => DisplacementStatus::DISPLACED,
            'displacement_location_text' => 'مواصي خانيونس',
        ]);

        $this->patchResidence(['displacement_status' => null])
            ->assertOk()
            ->assertJsonPath('data.residence.displacement_status', null)
            ->assertJsonPath('data.residence.displacement_location_text', null);
    }

    public function test_not_displaced_with_location_is_rejected(): void
    {
        $this->residence->update([
            'displacement_status' => DisplacementStatus::DISPLACED,
            'displacement_location_text' => 'مواصي خانيونس',
        ]);

        $this->patchResidence([
            'displacement_status' => 'NOT_DISPLACED',
            'displacement_location_text' => 'مواصي خانيونس',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('displacement_location_text');

        // Nothing changed.
        $fresh = $this->residence->fresh();
        $this->assertSame(DisplacementStatus::DISPLACED, $fresh->displacement_status);
        $this->assertSame('مواصي خانيونس', $fresh->displacement_location_text);
    }

    public function test_location_alone_uses_stored_status(): void
    {
        // Stored status unknown (legacy): a location alone is rejected.
        $this->patchResidence(['displacement_location_text' => 'مواصي خانيونس'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('displacement_location_text');

        // Stored status DISPLACED: a location alone is accepted.
        $this->residence->update(['displacement_status' => DisplacementStatus::DISPLACED]);

        $this->patchResidence(['displacement_location_text' => 'مواصي خانيونس'])
            ->assertOk()
            ->assertJsonPath('data.residence.displacement_location_text', 'مواصي خانيونس');
    }

    public function test_legacy_null_status_survives_unrelated_edits(): void
    {
        $this->patchResidence([
            'original_residence_text' => 'بني سهيلا',
            'city' => 'خانيونس',
        ])->assertOk()
            ->assertJsonPath('data.residence.displacement_status', null);

        $this->assertNull($this->residence->fresh()->displacement_status);
    }

    public function test_current_address_fields_update(): void
    {
        $this->patchResidence([
            'governorate' => 'غزة',
            'city' => 'غزة',
            'area' => 'الرمال',
            'neighborhood' => 'الحي الشمالي',
            'address_text' => 'قرب الميناء',
        ])->assertOk();

        $fresh = $this->residence->fresh();
        $this->assertSame('غزة', $fresh->governorate);
        $this->assertSame('غزة', $fresh->city);
        $this->assertSame('الرمال', $fresh->area);
        $this->assertSame('الحي الشمالي', $fresh->neighborhood);
        $this->assertSame('قرب الميناء', $fresh->address_text);
    }

    public function test_optional_address_fields_can_be_cleared(): void
    {
        $this->patchResidence(['area' => null])->assertOk();

        $this->assertNull($this->residence->fresh()->area);
    }

    public function test_updates_in_place_without_creating_residences(): void
    {
        $this->patchResidence(['city' => 'خانيونس'])->assertOk();

        $this->assertSame(1, FamilyResidence::count());
        $this->assertSame(1, FamilyResidence::where('is_current', true)->count());
        $this->assertSame($this->residence->id, $this->family->currentResidence()->value('id'));
    }

    public function test_family_person_and_membership_are_unchanged(): void
    {
        $family = $this->family->fresh()->toArray();
        $head = $this->head->fresh()->toArray();
        $membership = $this->membership->fresh()->toArray();
        $startedAt = $this->residence->fresh()->started_at;

        $this->patchResidence([
            'city' => 'خانيونس',
            'displacement_status' => 'DISPLACED',
            // Fields outside the editable set are ignored.
            'is_current' => false,
            'started_at' => '2000-01-01',
            'family_id' => 999,
        ])->assertOk();

        $this->assertSame($family, $this->family->fresh()->toArray());
        $this->assertSame($head, $this->head->fresh()->toArray());
        $this->assertSame($membership, $this->membership->fresh()->toArray());

        $residence = $this->residence->fresh();
        $this->assertTrue($residence->is_current);
        $this->assertEquals($startedAt, $residence->started_at);
        $this->assertSame($this->family->id, $residence->family_id);
    }

    public function test_records_acting_user(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->patchJson("/api/v1/families/{$this->family->family_code}/residence", ['city' => 'خانيونس'])
            ->assertOk();

        $this->assertSame($user->id, $this->residence->fresh()->updated_by);
    }

    public function test_validation_errors(): void
    {
        $this->patchResidence([
            'governorate' => '',
            'city' => null,
            'displacement_status' => 'مقيم',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['governorate', 'city', 'displacement_status']);
    }

    public function test_family_without_current_residence_returns_conflict(): void
    {
        $this->residence->update(['is_current' => false]);

        $this->patchResidence(['city' => 'خانيونس'])->assertStatus(409);

        $this->assertSame(1, FamilyResidence::count());
    }

    public function test_unknown_family_returns_404(): void
    {
        $this->actingAs($this->user())
            ->patchJson('/api/v1/families/FAM-999999/residence', ['city' => 'خانيونس'])
            ->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $this->patchJson("/api/v1/families/{$this->family->family_code}/residence", ['city' => 'خانيونس'])
            ->assertStatus(401);
    }

    public function test_requires_residence_update_permission(): void
    {
        // REVIEWER and REPORTS_VIEWER do not hold residence.update.
        foreach (['REVIEWER', 'REPORTS_VIEWER'] as $role) {
            $this->patchResidence(['city' => 'خانيونس'], $role)->assertStatus(403);
        }

        $this->assertSame('بني سهيلا', $this->residence->fresh()->city);
    }

    public function test_roles_with_residence_update_can_edit(): void
    {
        foreach (['ADMINISTRATOR', 'DATA_ENTRY', 'SOCIAL_WORKER'] as $role) {
            $this->patchResidence(['area' => $role], $role)->assertOk();
            $this->assertSame($role, $this->residence->fresh()->area);
        }
    }
}
