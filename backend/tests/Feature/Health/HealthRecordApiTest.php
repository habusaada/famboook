<?php

namespace Tests\Feature\Health;

use App\Enums\HealthRecordType;
use App\Models\DisabilityType;
use App\Models\Family;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use Database\Seeders\DisabilityTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HealthRecordApiTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private Person $father;

    private Person $mother;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-24');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DisabilityTypeSeeder::class);

        $this->family = Family::factory()->create();
        FamilyResidence::factory()->create(['family_id' => $this->family->id]);
        $this->father = $this->member(['gender' => 'MALE', 'birth_date' => '1985-01-01'], head: true);
        $this->mother = $this->member(['gender' => 'FEMALE', 'birth_date' => '1990-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(array $attributes, bool $head = false, ?Family $family = null, bool $active = true): Person
    {
        $person = Person::factory()->create($attributes);
        $factory = $head ? FamilyMembership::factory()->householdHead() : FamilyMembership::factory();
        $factory->create([
            'family_id' => ($family ?? $this->family)->id,
            'person_id' => $person->id,
            'is_active' => $active,
        ]);

        return $person;
    }

    private function user(string $role = 'DATA_ENTRY'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function disabilityTypeId(string $code): int
    {
        return DisabilityType::where('code', $code)->value('id');
    }

    private function store(array $payload, string $role = 'DATA_ENTRY')
    {
        return $this->actingAs($this->user($role))
            ->postJson("/api/v1/families/{$this->family->family_code}/health-records", $payload);
    }

    private function index(string $role = 'DATA_ENTRY')
    {
        return $this->actingAs($this->user($role))
            ->getJson("/api/v1/families/{$this->family->family_code}/health-records");
    }

    private function record(Person $person, HealthRecordType $type, array $attributes = []): PersonHealthRecord
    {
        return PersonHealthRecord::factory()->create([
            'person_id' => $person->id,
            'type' => $type->value,
            'disability_type_id' => $type === HealthRecordType::DISABILITY ? $this->disabilityTypeId('MOTOR') : null,
            'condition_name' => $type === HealthRecordType::CHRONIC_DISEASE ? 'سكري' : null,
            ...$attributes,
        ]);
    }

    // ---------------------------------------------------------------- create

    public function test_creates_disability_record(): void
    {
        $response = $this->store([
            'person_code' => $this->father->person_code,
            'type' => 'DISABILITY',
            'disability_type_id' => $this->disabilityTypeId('VISUAL'),
            'details' => 'ضعف بصر شديد',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'DISABILITY')
            ->assertJsonPath('data.disability_type.code', 'VISUAL')
            ->assertJsonPath('data.disability_type.name', 'بصرية')
            ->assertJsonPath('data.condition_name', null)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.person.person_code', $this->father->person_code);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->json('data.id'));
    }

    public function test_creates_chronic_disease_record_with_clean_name(): void
    {
        $this->store([
            'person_code' => $this->father->person_code,
            'type' => 'CHRONIC_DISEASE',
            'condition_name' => '  ضغط   الدم  ',
        ])->assertCreated()
            ->assertJsonPath('data.condition_name', 'ضغط الدم')
            ->assertJsonPath('data.disability_type', null);
    }

    public function test_creates_pregnancy_for_female(): void
    {
        $this->store([
            'person_code' => $this->mother->person_code,
            'type' => 'PREGNANCY',
            'started_at' => '2026-07-01',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'PREGNANCY')
            ->assertJsonPath('data.started_at', '2026-07-01')
            ->assertJsonPath('data.ended_at', null);
    }

    public function test_pregnancy_rejected_for_male(): void
    {
        $this->store(['person_code' => $this->father->person_code, 'type' => 'PREGNANCY'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('person_code');

        $this->assertSame(0, PersonHealthRecord::count());
    }

    public function test_creates_breastfeeding_for_female(): void
    {
        $this->store(['person_code' => $this->mother->person_code, 'type' => 'BREASTFEEDING'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'BREASTFEEDING');
    }

    public function test_breastfeeding_rejected_for_male(): void
    {
        $this->store(['person_code' => $this->father->person_code, 'type' => 'BREASTFEEDING'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('person_code');
    }

    public function test_type_specific_fields_are_validated(): void
    {
        // Disability needs a type; chronic needs a name.
        $this->store(['person_code' => $this->father->person_code, 'type' => 'DISABILITY'])
            ->assertStatus(422)->assertJsonValidationErrors('disability_type_id');
        $this->store(['person_code' => $this->father->person_code, 'type' => 'CHRONIC_DISEASE'])
            ->assertStatus(422)->assertJsonValidationErrors('condition_name');

        // Fields of another type are refused rather than silently stored.
        $this->store([
            'person_code' => $this->mother->person_code,
            'type' => 'PREGNANCY',
            'condition_name' => 'سكري',
            'disability_type_id' => $this->disabilityTypeId('MOTOR'),
        ])->assertStatus(422)->assertJsonValidationErrors(['condition_name', 'disability_type_id']);

        $this->store(['person_code' => $this->father->person_code, 'type' => 'SURGERY'])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $this->store([
            'person_code' => $this->mother->person_code,
            'type' => 'PREGNANCY',
            'started_at' => '2026-12-01',
        ])->assertStatus(422)->assertJsonValidationErrors('started_at');

        $this->assertSame(0, PersonHealthRecord::count());
    }

    public function test_disability_type_must_exist(): void
    {
        $this->store([
            'person_code' => $this->father->person_code,
            'type' => 'DISABILITY',
            'disability_type_id' => 9999,
        ])->assertStatus(422)->assertJsonValidationErrors('disability_type_id');
    }

    public function test_inactive_disability_type_rejected_for_new_records(): void
    {
        DisabilityType::where('code', 'MOTOR')->update(['is_active' => false]);

        $this->store([
            'person_code' => $this->father->person_code,
            'type' => 'DISABILITY',
            'disability_type_id' => $this->disabilityTypeId('MOTOR'),
        ])->assertStatus(422)->assertJsonValidationErrors('disability_type_id');
    }

    public function test_person_must_be_active_member_of_family(): void
    {
        $otherFamily = Family::factory()->create();
        $outsider = $this->member(['gender' => 'FEMALE'], head: true, family: $otherFamily);
        $former = $this->member(['gender' => 'FEMALE'], active: false);

        foreach ([$outsider, $former] as $person) {
            $this->store(['person_code' => $person->person_code, 'type' => 'BREASTFEEDING'])
                ->assertStatus(422)->assertJsonValidationErrors('person_code');
        }

        $this->store(['person_code' => 'PER-999999', 'type' => 'BREASTFEEDING'])
            ->assertStatus(422)->assertJsonValidationErrors('person_code');

        $this->assertSame(0, PersonHealthRecord::count());
    }

    // ------------------------------------------------------------ duplicates

    public function test_duplicate_active_disability_prevented(): void
    {
        $this->record($this->father, HealthRecordType::DISABILITY);

        $this->store([
            'person_code' => $this->father->person_code,
            'type' => 'DISABILITY',
            'disability_type_id' => $this->disabilityTypeId('MOTOR'),
        ])->assertStatus(422)->assertJsonValidationErrors('disability_type_id');

        // A different disability type is fine.
        $this->store([
            'person_code' => $this->father->person_code,
            'type' => 'DISABILITY',
            'disability_type_id' => $this->disabilityTypeId('HEARING'),
        ])->assertCreated();
    }

    public function test_duplicate_active_chronic_disease_prevented_with_normalization(): void
    {
        $this->record($this->father, HealthRecordType::CHRONIC_DISEASE, ['condition_name' => 'السكري']);

        foreach (['السكري', '  السكرى ', 'السُّكَّري'] as $name) {
            $this->store([
                'person_code' => $this->father->person_code,
                'type' => 'CHRONIC_DISEASE',
                'condition_name' => $name,
            ])->assertStatus(422)->assertJsonValidationErrors('condition_name');
        }

        $this->store([
            'person_code' => $this->father->person_code,
            'type' => 'CHRONIC_DISEASE',
            'condition_name' => 'ضغط الدم',
        ])->assertCreated();
    }

    public function test_duplicate_active_pregnancy_prevented(): void
    {
        $this->record($this->mother, HealthRecordType::PREGNANCY);

        $this->store(['person_code' => $this->mother->person_code, 'type' => 'PREGNANCY'])
            ->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_duplicate_active_breastfeeding_prevented(): void
    {
        $this->record($this->mother, HealthRecordType::BREASTFEEDING);

        $this->store(['person_code' => $this->mother->person_code, 'type' => 'BREASTFEEDING'])
            ->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_closed_record_does_not_block_a_new_one(): void
    {
        $this->record($this->mother, HealthRecordType::PREGNANCY, ['ended_at' => '2026-01-01']);

        $this->store(['person_code' => $this->mother->person_code, 'type' => 'PREGNANCY'])
            ->assertCreated();
    }

    // ------------------------------------------------------------ edit/close

    public function test_edit_corrects_allowed_fields_only(): void
    {
        $record = $this->record($this->father, HealthRecordType::DISABILITY);

        $this->actingAs($this->user())
            ->patchJson("/api/v1/health-records/{$record->uuid}", [
                'disability_type_id' => $this->disabilityTypeId('HEARING'),
                'details' => 'تصحيح',
                'started_at' => '2020-05-01',
            ])->assertOk()
            ->assertJsonPath('data.disability_type.code', 'HEARING')
            ->assertJsonPath('data.details', 'تصحيح')
            ->assertJsonPath('data.started_at', '2020-05-01');

        // Fields of another type are refused; person/type are fixed.
        $this->actingAs($this->user())
            ->patchJson("/api/v1/health-records/{$record->uuid}", ['condition_name' => 'سكري'])
            ->assertStatus(422)->assertJsonValidationErrors('condition_name');

        $this->actingAs($this->user())
            ->patchJson("/api/v1/health-records/{$record->uuid}", [
                'details' => 'x',
                'type' => 'PREGNANCY',
                'person_id' => $this->mother->id,
                'ended_at' => '2026-01-01',
            ])->assertOk();

        $fresh = $record->fresh();
        $this->assertSame(HealthRecordType::DISABILITY, $fresh->type);
        $this->assertSame($this->father->id, $fresh->person_id);
        $this->assertNull($fresh->ended_at);
    }

    public function test_edit_cannot_create_a_duplicate(): void
    {
        $this->record($this->father, HealthRecordType::CHRONIC_DISEASE, ['condition_name' => 'سكري']);
        $other = $this->record($this->father, HealthRecordType::CHRONIC_DISEASE, ['condition_name' => 'ربو']);

        $this->actingAs($this->user())
            ->patchJson("/api/v1/health-records/{$other->uuid}", ['condition_name' => 'سكرى'])
            ->assertStatus(422)->assertJsonValidationErrors('condition_name');
    }

    public function test_edit_keeps_deactivated_disability_type_but_cannot_newly_choose_one(): void
    {
        $record = $this->record($this->father, HealthRecordType::DISABILITY);
        DisabilityType::whereIn('code', ['MOTOR', 'VISUAL'])->update(['is_active' => false]);

        $this->actingAs($this->user())
            ->patchJson("/api/v1/health-records/{$record->uuid}", [
                'disability_type_id' => $this->disabilityTypeId('MOTOR'),
                'details' => 'ok',
            ])->assertOk();

        $this->actingAs($this->user())
            ->patchJson("/api/v1/health-records/{$record->uuid}", ['disability_type_id' => $this->disabilityTypeId('VISUAL')])
            ->assertStatus(422)->assertJsonValidationErrors('disability_type_id');
    }

    public function test_close_pregnancy(): void
    {
        $record = $this->record($this->mother, HealthRecordType::PREGNANCY, ['started_at' => '2026-01-10']);

        $this->actingAs($this->user())
            ->postJson("/api/v1/health-records/{$record->uuid}/close")
            ->assertOk()
            ->assertJsonPath('data.ended_at', '2026-09-24')
            ->assertJsonPath('data.is_active', false);

        // Closing twice is refused; nothing is deleted.
        $this->actingAs($this->user())
            ->postJson("/api/v1/health-records/{$record->uuid}/close")
            ->assertStatus(422)->assertJsonValidationErrors('ended_at');

        $this->assertSame(1, PersonHealthRecord::count());
    }

    public function test_close_breastfeeding_with_explicit_date(): void
    {
        $record = $this->record($this->mother, HealthRecordType::BREASTFEEDING, ['started_at' => '2026-01-10']);

        $this->actingAs($this->user())
            ->postJson("/api/v1/health-records/{$record->uuid}/close", ['ended_at' => '2026-08-01'])
            ->assertOk()
            ->assertJsonPath('data.ended_at', '2026-08-01');

        // End date may not precede the start or lie in the future.
        $other = $this->record($this->mother, HealthRecordType::PREGNANCY, ['started_at' => '2026-05-01']);
        $this->actingAs($this->user())
            ->postJson("/api/v1/health-records/{$other->uuid}/close", ['ended_at' => '2026-04-01'])
            ->assertStatus(422)->assertJsonValidationErrors('ended_at');
        $this->actingAs($this->user())
            ->postJson("/api/v1/health-records/{$other->uuid}/close", ['ended_at' => '2026-12-01'])
            ->assertStatus(422)->assertJsonValidationErrors('ended_at');
    }

    public function test_there_is_no_delete_endpoint(): void
    {
        $record = $this->record($this->father, HealthRecordType::CHRONIC_DISEASE);

        $this->actingAs($this->user('SUPER_ADMIN'))
            ->deleteJson("/api/v1/health-records/{$record->uuid}")
            ->assertStatus(405);

        $this->assertSame(1, PersonHealthRecord::count());
    }

    // ----------------------------------------------------------- permissions

    public function test_permissions_per_role(): void
    {
        $record = $this->record($this->mother, HealthRecordType::CHRONIC_DISEASE);

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER'] as $role) {
            $this->index($role)->assertOk();
        }

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY'] as $i => $role) {
            $this->store([
                'person_code' => $this->father->person_code,
                'type' => 'CHRONIC_DISEASE',
                'condition_name' => "مرض {$i}",
            ], $role)->assertCreated();
            $this->actingAs($this->user($role))
                ->patchJson("/api/v1/health-records/{$record->uuid}", ['details' => $role])
                ->assertOk();
        }

        foreach (['REVIEWER', 'SOCIAL_WORKER'] as $role) {
            $this->store([
                'person_code' => $this->father->person_code,
                'type' => 'CHRONIC_DISEASE',
                'condition_name' => 'ممنوع',
            ], $role)->assertStatus(403);
            $this->actingAs($this->user($role))
                ->patchJson("/api/v1/health-records/{$record->uuid}", ['details' => 'x'])
                ->assertStatus(403);
            $this->actingAs($this->user($role))
                ->postJson("/api/v1/health-records/{$record->uuid}/close")
                ->assertStatus(403);
        }

        $this->actingAs($this->user('ADMINISTRATOR'))
            ->postJson("/api/v1/health-records/{$record->uuid}/close")
            ->assertOk();
    }

    public function test_abilities_reflect_the_viewer(): void
    {
        $this->index('DATA_ENTRY')->assertJsonPath('abilities', ['create' => true, 'update' => true, 'close' => true]);
        $this->index('REVIEWER')->assertJsonPath('abilities', ['create' => false, 'update' => false, 'close' => false]);
    }

    public function test_reports_viewer_and_family_user_denied(): void
    {
        $record = $this->record($this->mother, HealthRecordType::PREGNANCY);

        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->index($role)->assertStatus(403);
            $this->store(['person_code' => $this->mother->person_code, 'type' => 'BREASTFEEDING'], $role)
                ->assertStatus(403);
            $this->actingAs($this->user($role))
                ->patchJson("/api/v1/health-records/{$record->uuid}", ['details' => 'x'])->assertStatus(403);
            $this->actingAs($this->user($role))
                ->postJson("/api/v1/health-records/{$record->uuid}/close")->assertStatus(403);
        }
    }

    public function test_requires_authentication(): void
    {
        $this->getJson("/api/v1/families/{$this->family->family_code}/health-records")->assertStatus(401);
    }

    // --------------------------------------------------------------- privacy

    public function test_no_national_id_or_contact_data_in_health_responses(): void
    {
        $this->mother->update(['national_id' => '900000001', 'mobile' => '0590000000']);
        $this->record($this->mother, HealthRecordType::PREGNANCY);

        $response = $this->index()->assertOk();

        $this->assertStringNotContainsString('900000001', $response->getContent());
        $this->assertStringNotContainsString('national_id', $response->getContent());
        $this->assertStringNotContainsString('0590000000', $response->getContent());
    }

    public function test_health_data_not_in_generic_family_or_person_responses(): void
    {
        $this->record($this->mother, HealthRecordType::CHRONIC_DISEASE, ['condition_name' => 'مرض سري']);
        $user = $this->user('SUPER_ADMIN');

        foreach ([
            '/api/v1/families',
            "/api/v1/families/{$this->family->family_code}",
            "/api/v1/people/{$this->mother->person_code}",
        ] as $url) {
            $body = $this->actingAs($user)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('مرض سري', $body, $url);
            $this->assertStringNotContainsString('health', $body, $url);
        }
    }

    // ---------------------------------------------------- derived indicators

    public function test_counts_use_distinct_active_persons(): void
    {
        // One person with two chronic diseases counts once; closed records don't count.
        $this->record($this->father, HealthRecordType::CHRONIC_DISEASE, ['condition_name' => 'سكري']);
        $this->record($this->father, HealthRecordType::CHRONIC_DISEASE, ['condition_name' => 'ضغط']);
        $this->record($this->mother, HealthRecordType::CHRONIC_DISEASE, ['condition_name' => 'ربو', 'ended_at' => '2026-01-01']);
        $this->record($this->father, HealthRecordType::DISABILITY);
        $this->record($this->father, HealthRecordType::DISABILITY, ['disability_type_id' => $this->disabilityTypeId('HEARING')]);
        $this->record($this->mother, HealthRecordType::PREGNANCY);
        $this->record($this->mother, HealthRecordType::BREASTFEEDING, ['ended_at' => '2026-06-01']);

        $this->index()->assertOk()
            ->assertJsonPath('summary.chronic_disease_persons', 1)
            ->assertJsonPath('summary.disability_persons', 1)
            ->assertJsonPath('summary.pregnant', 1)
            ->assertJsonPath('summary.breastfeeding', 0)
            ->assertJsonCount(7, 'data')
            // Active records first.
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_under_two_and_recent_birth_derive_from_birth_date(): void
    {
        // Reference date 2026-09-24.
        $this->member(['birth_date' => '2026-03-01']);  // under 2, recent
        $this->member(['birth_date' => '2025-09-24']);  // under 2; exactly 12 months → not recent
        $this->member(['birth_date' => '2025-09-25']);  // under 2, recent
        $this->member(['birth_date' => '2024-09-25']);  // under 2 (1 day before 2nd birthday)
        $this->member(['birth_date' => '2024-09-24']);  // reached 2 → neither
        $this->member(['birth_date' => null]);          // missing DOB → neither

        $this->index()->assertOk()
            ->assertJsonPath('reference_date', '2026-09-24')
            ->assertJsonPath('summary.under_two', 4)
            ->assertJsonPath('summary.recent_births', 2);
    }

    public function test_inactive_members_and_deceased_are_excluded(): void
    {
        $former = $this->member(['gender' => 'FEMALE', 'birth_date' => '2026-01-01'], active: false);
        $this->record($former, HealthRecordType::PREGNANCY);
        $deceased = $this->member(['gender' => 'MALE', 'birth_date' => '2026-02-01', 'life_status' => 'DECEASED']);
        $this->record($deceased, HealthRecordType::DISABILITY);

        $response = $this->index()->assertOk()
            ->assertJsonPath('summary.pregnant', 0)
            ->assertJsonPath('summary.disability_persons', 0)
            ->assertJsonPath('summary.under_two', 0)
            ->assertJsonPath('summary.recent_births', 0);

        // Records of former members are not listed; the deceased member's still are.
        $codes = collect($response->json('data'))->pluck('person.person_code')->all();
        $this->assertNotContains($former->person_code, $codes);
        $this->assertContains($deceased->person_code, $codes);
    }

    // ------------------------------------------------------------ regression

    public function test_existing_family_endpoints_unaffected(): void
    {
        $this->record($this->mother, HealthRecordType::PREGNANCY);
        $user = $this->user('SUPER_ADMIN');

        $this->actingAs($user)->getJson("/api/v1/families/{$this->family->family_code}")
            ->assertOk()->assertJsonPath('data.member_count', 2);
        $this->actingAs($user)->patchJson("/api/v1/families/{$this->family->family_code}", ['notes' => 'x'])
            ->assertOk();
        $this->actingAs($user)->patchJson("/api/v1/people/{$this->mother->person_code}", ['full_name' => 'اسم'])
            ->assertOk();
    }

    public function test_disability_types_reference_endpoint(): void
    {
        DisabilityType::where('code', 'OTHER')->update(['is_active' => false]);

        $response = $this->actingAs($this->user())->getJson('/api/v1/reference/disability-types')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.code', 'MOTOR')
            ->assertJsonPath('data.0.name', 'حركية');
        $this->assertNotContains('OTHER', collect($response->json('data'))->pluck('code'));

        $this->actingAs($this->user('REPORTS_VIEWER'))->getJson('/api/v1/reference/disability-types')
            ->assertStatus(403);
    }

    public function test_disability_type_seeding_is_idempotent_and_keeps_deactivation(): void
    {
        DisabilityType::where('code', 'OTHER')->update(['is_active' => false]);
        $this->seed(DisabilityTypeSeeder::class);

        $this->assertSame(7, DisabilityType::count());
        $this->assertFalse(DisabilityType::where('code', 'OTHER')->value('is_active'));
        $this->assertSame(
            ['MOTOR', 'VISUAL', 'HEARING', 'SPEECH_COMMUNICATION', 'INTELLECTUAL', 'MULTIPLE', 'OTHER'],
            DisabilityType::orderBy('sort_order')->pluck('code')->all()
        );
    }
}
