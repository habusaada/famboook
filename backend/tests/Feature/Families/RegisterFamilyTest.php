<?php

namespace Tests\Feature\Families;

use App\Actions\RegisterFamilyAction;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterFamilyTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(): array
    {
        return [
            'registration_date' => '2026-09-01',
            'registration_source' => 'MANUAL_ENTRY',
            'paper_form_no' => null,
            'notes' => 'ملاحظة اختبار',
            'household_head' => [
                'full_name' => 'محمد أحمد الشريف',
                'national_id' => '1234567890',
                'gender' => 'MALE',
                'birth_date' => '1982-03-14',
                'mobile' => '0790000000',
            ],
            'residence' => [
                'governorate' => 'عمّان',
                'city' => 'الزرقاء',
                'area' => 'حي النصر',
                'address_text' => 'شارع الملك حسين',
                'displacement_status' => 'NOT_DISPLACED',
            ],
        ];
    }

    private function authorizedUser(string $role = 'SUPER_ADMIN'): User
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_authorized_user_can_register_a_family_via_api(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/families', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'ACTIVE');
        $response->assertJsonPath('data.member_count', 1);
        $response->assertJsonPath('data.male_count', 1);
        $response->assertJsonPath('data.members.0.is_household_head', true);
        $response->assertJsonPath('data.members.0.full_name', 'محمد أحمد الشريف');
        $response->assertJsonPath('data.residence.city', 'الزرقاء');

        $familyCode = $response->json('data.family_code');
        $this->assertMatchesRegularExpression('/^FAM-\d{6}$/', $familyCode);
    }

    public function test_registration_creates_family_household_head_membership_and_residence(): void
    {
        $user = $this->authorizedUser();

        $this->actingAs($user)->postJson('/api/v1/families', $this->validPayload())
            ->assertCreated();

        // Family created.
        $this->assertSame(1, Family::count());
        $family = Family::first();
        $this->assertMatchesRegularExpression('/^FAM-\d{6}$/', $family->family_code);
        $this->assertSame('ACTIVE', $family->status->value);

        // Household-head Person created.
        $this->assertSame(1, Person::count());
        $person = Person::first();
        $this->assertMatchesRegularExpression('/^PER-\d{6}$/', $person->person_code);
        $this->assertSame('محمد أحمد الشريف', $person->full_name);
        $this->assertSame('ALIVE', $person->life_status->value);

        // Active household-head membership created.
        $this->assertSame(1, FamilyMembership::count());
        $membership = FamilyMembership::first();
        $this->assertTrue($membership->is_household_head);
        $this->assertTrue($membership->is_active);
        $this->assertSame($family->id, $membership->family_id);
        $this->assertSame($person->id, $membership->person_id);

        // Current residence created.
        $this->assertSame(1, FamilyResidence::count());
        $residence = FamilyResidence::first();
        $this->assertTrue($residence->is_current);
        $this->assertSame($family->id, $residence->family_id);
        $this->assertSame('الزرقاء', $residence->city);
    }

    public function test_registration_is_transactional_and_rolls_back_on_failure(): void
    {
        $action = new RegisterFamilyAction;

        $payload = $this->validPayload();
        // Force a NOT NULL violation on persons.full_name, which only
        // fails on the *second* insert inside the transaction — proving
        // the already-successful Family insert is rolled back too.
        $payload['household_head']['full_name'] = null;

        try {
            $action->handle($payload, null);
            $this->fail('Expected a database exception to be thrown.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(0, Family::count());
        $this->assertSame(0, Person::count());
        $this->assertSame(0, FamilyMembership::count());
        $this->assertSame(0, FamilyResidence::count());
    }

    public function test_registration_fails_validation_with_missing_required_fields(): void
    {
        $user = $this->authorizedUser();

        $response = $this->actingAs($user)->postJson('/api/v1/families', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'registration_date',
            'registration_source',
            'household_head.full_name',
            'household_head.gender',
            'household_head.birth_date',
            'residence.governorate',
            'residence.city',
        ]);
    }

    public function test_registration_rejects_future_birth_date(): void
    {
        $user = $this->authorizedUser();

        $payload = $this->validPayload();
        $payload['household_head']['birth_date'] = now()->addYear()->toDateString();

        $response = $this->actingAs($user)->postJson('/api/v1/families', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['household_head.birth_date']);
    }

    public function test_unauthenticated_user_cannot_register_a_family(): void
    {
        $response = $this->postJson('/api/v1/families', $this->validPayload());

        $response->assertStatus(401);
        $this->assertSame(0, Family::count());
    }

    public function test_user_without_permission_cannot_register_a_family(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('REPORTS_VIEWER'); // has no family.create per RBAC review

        $response = $this->actingAs($user)->postJson('/api/v1/families', $this->validPayload());

        $response->assertStatus(403);
        $this->assertSame(0, Family::count());
    }

    public function test_only_one_active_household_head_per_family_is_allowed(): void
    {
        $family = Family::factory()->create();
        $head = Person::factory()->create();
        $other = Person::factory()->create();

        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => $head->id,
        ]);

        $this->expectException(QueryException::class);

        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $family->id,
            'person_id' => $other->id,
        ]);
    }

    public function test_only_one_active_family_membership_per_person_is_allowed(): void
    {
        $person = Person::factory()->create();
        $familyA = Family::factory()->create();
        $familyB = Family::factory()->create();

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

    public function test_only_one_current_residence_per_family_is_allowed(): void
    {
        $family = Family::factory()->create();

        FamilyResidence::factory()->create([
            'family_id' => $family->id,
            'is_current' => true,
        ]);

        $this->expectException(QueryException::class);

        FamilyResidence::factory()->create([
            'family_id' => $family->id,
            'is_current' => true,
        ]);
    }
}
