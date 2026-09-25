<?php

namespace Tests\Feature\Families;

use App\Actions\RegisterFamilyAction;
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
 * Residence before displacement, displacement status/location and the
 * alternate phone owner/relation (docs/02-DATA-DICTIONARY.md §9-10, §19).
 */
class FamilyResidenceDisplacementTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('SUPER_ADMIN');

        return $user;
    }

    private function payload(array $residence = [], array $head = []): array
    {
        return [
            'registration_date' => '2026-09-01',
            'registration_source' => 'PAPER_FORM',
            'household_head' => [
                'full_name' => 'سامي عادل التجريبي',
                'gender' => 'MALE',
                'birth_date' => '1985-06-01',
                'mobile' => '0590000001',
                ...$head,
            ],
            'clan_code' => 'AL_BREEM',
            'residence' => [
                'governorate' => 'خانيونس',
                'city' => 'خانيونس',
                ...$residence,
            ],
        ];
    }

    private function register(array $payload)
    {
        return $this->actingAs($this->authorizedUser())->postJson('/api/v1/families', $payload);
    }

    public function test_registration_stores_original_residence(): void
    {
        $response = $this->register($this->payload([
            'original_residence_text' => 'بني سهيلا – خانيونس',
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.residence.original_residence_text', 'بني سهيلا – خانيونس');
        $this->assertSame('بني سهيلا – خانيونس', FamilyResidence::first()->original_residence_text);
    }

    public function test_displaced_family_stores_displacement_location(): void
    {
        $response = $this->register($this->payload([
            'original_residence_text' => 'بني سهيلا – خانيونس',
            'displacement_status' => 'DISPLACED',
            'displacement_location_text' => 'مواصي خانيونس',
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.residence.displacement_status', 'DISPLACED');
        $response->assertJsonPath('data.residence.displacement_location_text', 'مواصي خانيونس');

        $residence = FamilyResidence::first();
        $this->assertSame(DisplacementStatus::DISPLACED, $residence->displacement_status);
        $this->assertSame('مواصي خانيونس', $residence->displacement_location_text);
    }

    public function test_displaced_family_may_omit_displacement_location(): void
    {
        $response = $this->register($this->payload(['displacement_status' => 'DISPLACED']));

        $response->assertCreated();
        $response->assertJsonPath('data.residence.displacement_status', 'DISPLACED');
        $response->assertJsonPath('data.residence.displacement_location_text', null);
    }

    public function test_non_displaced_family_with_displacement_location_is_rejected(): void
    {
        $response = $this->register($this->payload([
            'displacement_status' => 'NOT_DISPLACED',
            'displacement_location_text' => 'مواصي خانيونس',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('residence.displacement_location_text');
        $this->assertSame(0, Family::count());
    }

    public function test_unknown_displacement_status_with_displacement_location_is_rejected(): void
    {
        $response = $this->register($this->payload([
            'displacement_location_text' => 'مواصي خانيونس',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('residence.displacement_location_text');
    }

    public function test_non_displaced_family_saves_with_null_location(): void
    {
        $response = $this->register($this->payload([
            'displacement_status' => 'NOT_DISPLACED',
            'displacement_location_text' => null,
        ]));

        $response->assertCreated();
        $response->assertJsonPath('data.residence.displacement_status', 'NOT_DISPLACED');
        $response->assertJsonPath('data.residence.displacement_location_text', null);
    }

    public function test_action_clears_location_for_non_displaced_family(): void
    {
        // Defense in depth: the Domain Action never persists a location
        // for a non-displaced family, even if called without the request.
        $family = app(RegisterFamilyAction::class)->handle($this->payload([
            'displacement_status' => 'NOT_DISPLACED',
            'displacement_location_text' => 'مواصي خانيونس',
        ]), null);

        $this->assertNull($family->currentResidence->displacement_location_text);
    }

    public function test_invalid_displacement_status_is_rejected(): void
    {
        $response = $this->register($this->payload(['displacement_status' => 'مقيم']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('residence.displacement_status');
    }

    public function test_displacement_status_may_be_omitted_and_stays_unknown(): void
    {
        $response = $this->register($this->payload());

        $response->assertCreated();
        $response->assertJsonPath('data.residence.displacement_status', null);
        $this->assertNull(FamilyResidence::first()->displacement_status);
    }

    public function test_legacy_residence_without_displacement_data_is_still_returned(): void
    {
        $user = $this->authorizedUser();
        $family = Family::factory()->create();
        $head = Person::factory()->create();
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => $head->id,
        ]);
        FamilyResidence::factory()->create([
            'family_id' => $family->id,
            'displacement_status' => null,
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/families/{$family->family_code}");

        $response->assertOk();
        $response->assertJsonPath('data.residence.displacement_status', null);
        $response->assertJsonPath('data.residence.original_residence_text', null);
        $response->assertJsonPath('data.residence.displacement_location_text', null);
    }

    public function test_family_detail_returns_residence_and_displacement_fields(): void
    {
        $code = $this->register($this->payload([
            'area' => 'قيزان النجار',
            'neighborhood' => 'الحي الغربي',
            'address_text' => 'قرب المدرسة',
            'original_residence_text' => 'بني سهيلا – خانيونس',
            'displacement_status' => 'DISPLACED',
            'displacement_location_text' => 'مواصي خانيونس',
        ]))->json('data.family_code');

        $response = $this->getJson("/api/v1/families/{$code}");

        $response->assertOk();
        $response->assertJsonPath('data.residence.governorate', 'خانيونس');
        $response->assertJsonPath('data.residence.neighborhood', 'الحي الغربي');
        $response->assertJsonPath('data.residence.started_at', '2026-09-01');
        $response->assertJsonPath('data.residence.original_residence_text', 'بني سهيلا – خانيونس');
        $response->assertJsonPath('data.residence.displacement_status', 'DISPLACED');
        $response->assertJsonPath('data.residence.displacement_location_text', 'مواصي خانيونس');
    }

    public function test_alternate_mobile_owner_relation_is_persisted_and_exposed(): void
    {
        $response = $this->register($this->payload([], [
            'alternate_mobile' => '0590000002',
            'alternate_mobile_owner_relation' => 'أحمد محمد – أخ',
        ]));

        $response->assertCreated();

        $person = Person::first();
        $this->assertSame('0590000002', $person->alternate_mobile);
        $this->assertSame('أحمد محمد – أخ', $person->alternate_mobile_owner_relation);

        // Descriptive only: no Person or membership is created from it.
        $this->assertSame(1, Person::count());
        $this->assertSame(1, FamilyMembership::count());

        $this->getJson("/api/v1/people/{$person->person_code}")
            ->assertOk()
            ->assertJsonPath('data.alternate_mobile', '0590000002')
            ->assertJsonPath('data.alternate_mobile_owner_relation', 'أحمد محمد – أخ');
    }

    public function test_alternate_mobile_owner_relation_without_alternate_mobile_is_rejected(): void
    {
        $response = $this->register($this->payload([], [
            'alternate_mobile_owner_relation' => 'أحمد محمد – أخ',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('household_head.alternate_mobile_owner_relation');
    }

    public function test_removing_alternate_mobile_clears_owner_relation(): void
    {
        $person = Person::factory()->create([
            'alternate_mobile' => '0590000002',
            'alternate_mobile_owner_relation' => 'أحمد محمد – أخ',
        ]);

        $this->actingAs($this->authorizedUser())
            ->patchJson("/api/v1/people/{$person->person_code}", ['alternate_mobile' => null])
            ->assertOk()
            ->assertJsonPath('data.alternate_mobile_owner_relation', null);

        $this->assertNull($person->fresh()->alternate_mobile_owner_relation);
    }
}
