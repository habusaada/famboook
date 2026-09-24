<?php

namespace Tests\Feature\Activity;

use App\Enums\FamilyActivityType;
use App\Enums\HealthRecordType;
use App\Models\DisabilityType;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\FamilyResidence;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\FamilyActivityLog;
use Database\Seeders\DisabilityTypeSeeder;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Family Activity Log V1 (docs/03-BUSINESS-RULES.md §97a): system-generated,
 * append-only, family-scoped, written inside the Domain Action transaction,
 * exposed read-only through GET /api/v1/families/{family}/activities.
 *
 * All identity, phone and health values below are synthetic.
 */
class FamilyActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const NATIONAL_ID = '9990001112';

    private const MOBILE = '0799990001';

    private const ALT_MOBILE = '0788880002';

    private const CONDITION = 'مرض اختباري مزمن';

    private const DETAILS = 'تفاصيل صحية اختبارية سرية';

    private Family $family;

    private Person $head;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-24 10:00:00');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->seed(DisabilityTypeSeeder::class);

        // A pre-existing family: created directly, not through a Domain Action.
        $this->family = Family::factory()->create(['registration_date' => '2026-09-01']);
        FamilyResidence::factory()->create([
            'family_id' => $this->family->id,
            'city' => 'مدينة أ',
            'displacement_status' => 'NOT_DISPLACED',
        ]);
        $this->head = Person::factory()->create(['gender' => 'MALE', 'birth_date' => '1980-01-01']);
        FamilyMembership::factory()->householdHead()->create([
            'family_id' => $this->family->id,
            'person_id' => $this->head->id,
        ]);

        $this->user = $this->user('DATA_ENTRY', 'مدخل بيانات تجريبي');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?string $name = null): User
    {
        $user = User::factory()->create($name ? ['name' => $name] : []);
        $user->assignRole($role);

        return $user;
    }

    private function activities(?Family $family = null, ?User $as = null, array $query = [])
    {
        $code = ($family ?? $this->family)->family_code;
        $qs = $query ? '?'.http_build_query($query) : '';

        return $this->actingAs($as ?? $this->user)->getJson("/api/v1/families/{$code}/activities{$qs}");
    }

    private function registerPayload(): array
    {
        return [
            'registration_date' => '2026-09-20',
            'registration_source' => 'MANUAL_ENTRY',
            'notes' => 'ملاحظة تجريبية',
            'household_head' => [
                'full_name' => 'رب أسرة تجريبي',
                'national_id' => self::NATIONAL_ID,
                'gender' => 'MALE',
                'birth_date' => '1982-03-14',
                'mobile' => self::MOBILE,
                'alternate_mobile' => self::ALT_MOBILE,
            ],
            'residence' => [
                'governorate' => 'محافظة تجريبية',
                'city' => 'مدينة تجريبية',
                'displacement_status' => 'NOT_DISPLACED',
            ],
        ];
    }

    private function addMember(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson("/api/v1/families/{$this->family->family_code}/members", [
            'full_name' => 'فرد تجريبي',
            'national_id' => self::NATIONAL_ID,
            'gender' => 'FEMALE',
            'birth_date' => '2000-05-05',
            'mobile' => self::MOBILE,
            'alternate_mobile' => self::ALT_MOBILE,
            'relationship_type_id' => RelationshipType::where('code', 'SON')->value('id'),
            ...$overrides,
        ]);
    }

    private function member(array $attributes = []): Person
    {
        $person = Person::factory()->create(['gender' => 'FEMALE', 'birth_date' => '1990-01-01', ...$attributes]);
        FamilyMembership::factory()->create(['family_id' => $this->family->id, 'person_id' => $person->id]);

        return $person;
    }

    private function createChronic(Person $person)
    {
        return $this->actingAs($this->user)->postJson("/api/v1/families/{$this->family->family_code}/health-records", [
            'person_code' => $person->person_code,
            'type' => 'CHRONIC_DISEASE',
            'condition_name' => self::CONDITION,
            'details' => self::DETAILS,
        ]);
    }

    /** Raw stored rows + a response body must never carry these values. */
    private function assertNoSensitiveValues(string $haystack): void
    {
        foreach ([self::NATIONAL_ID, self::MOBILE, self::ALT_MOBILE, self::CONDITION, self::DETAILS] as $value) {
            $this->assertStringNotContainsString($value, $haystack);
        }
        foreach (['national_id', 'mobile', 'condition_name', 'details', 'disability_type', 'email'] as $key) {
            $this->assertStringNotContainsString("\"{$key}\"", $haystack);
        }
    }

    private function storedActivitiesJson(): string
    {
        return json_encode(DB::table('family_activities')->get(), JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> */
    private function eventTypes(Family $family): array
    {
        return FamilyActivity::where('family_id', $family->id)->orderBy('id')
            ->pluck('event_type')->map->value->all();
    }

    // ------------------------------------------------------------ family

    public function test_family_registration_writes_family_created(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/families', $this->registerPayload())
            ->assertCreated();

        $family = Family::where('family_code', $response->json('data.family_code'))->firstOrFail();
        $this->assertSame(['FAMILY_CREATED'], $this->eventTypes($family));

        $activity = FamilyActivity::firstWhere('family_id', $family->id);
        $this->assertSame('family', $activity->subject_type);
        $this->assertSame($family->id, $activity->subject_id);
        $this->assertNull($activity->metadata);
        $this->assertNoSensitiveValues($this->storedActivitiesJson());
    }

    public function test_failed_family_registration_leaves_no_family_and_no_activity(): void
    {
        $families = Family::count();

        // Fails AFTER the activity row is inserted: everything rolls back.
        FamilyActivity::created(fn () => throw new \RuntimeException('simulated failure'));

        $this->actingAs($this->user)->postJson('/api/v1/families', $this->registerPayload())
            ->assertServerError();

        $this->assertSame($families, Family::count());
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_invalid_family_registration_creates_no_activity(): void
    {
        $payload = $this->registerPayload();
        unset($payload['residence']);

        $this->actingAs($this->user)->postJson('/api/v1/families', $payload)->assertUnprocessable();

        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_family_correction_writes_family_updated(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", ['paper_form_no' => 'PF-TEST-1'])
            ->assertOk();

        $this->assertSame(['FAMILY_UPDATED'], $this->eventTypes($this->family));
        $this->assertStringNotContainsString('PF-TEST-1', $this->storedActivitiesJson());
    }

    public function test_family_save_without_changes_writes_no_activity(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", ['registration_date' => '2026-09-01'])
            ->assertOk();

        $this->assertSame([], $this->eventTypes($this->family));
    }

    // ------------------------------------------------------------ members / persons

    public function test_add_member_writes_family_member_added_without_identity_or_phone(): void
    {
        $response = $this->addMember()->assertCreated();

        $person = Person::where('person_code', $response->json('data.person_code'))->firstOrFail();
        $this->assertSame(['FAMILY_MEMBER_ADDED'], $this->eventTypes($this->family));

        $activity = FamilyActivity::firstWhere('family_id', $this->family->id);
        $this->assertSame('person', $activity->subject_type);
        $this->assertSame($person->id, $activity->subject_id);
        $this->assertNoSensitiveValues($this->storedActivitiesJson());

        $body = $this->activities()->assertOk()
            ->assertJsonPath('data.0.event_type', 'FAMILY_MEMBER_ADDED')
            ->assertJsonPath('data.0.subject.person.full_name', 'فرد تجريبي')
            ->assertJsonPath('data.0.subject.person.person_code', $person->person_code)
            ->getContent();
        $this->assertNoSensitiveValues($body);
    }

    public function test_failed_add_member_rolls_back_member_and_activity(): void
    {
        $persons = Person::count();
        FamilyActivity::created(fn () => throw new \RuntimeException('simulated failure'));

        $this->addMember()->assertServerError();

        $this->assertSame($persons, Person::count());
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_person_correction_writes_person_updated_without_values(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/people/{$this->head->person_code}", [
                'full_name' => 'اسم مصحح تجريبي',
                'mobile' => self::MOBILE,
            ])
            ->assertOk();

        $this->assertSame(['PERSON_UPDATED'], $this->eventTypes($this->family));
        $activity = FamilyActivity::firstWhere('family_id', $this->family->id);
        $this->assertSame('person', $activity->subject_type);
        $this->assertSame($this->head->id, $activity->subject_id);
        $this->assertNull($activity->metadata);
        $this->assertNoSensitiveValues($this->storedActivitiesJson());
    }

    public function test_rejected_person_correction_creates_no_activity(): void
    {
        $mother = $this->member();
        PersonHealthRecord::factory()->create(['person_id' => $mother->id, 'type' => 'PREGNANCY']);

        // A woman with an active pregnancy cannot become MALE (domain rule).
        $this->actingAs($this->user)
            ->patchJson("/api/v1/people/{$mother->person_code}", ['gender' => 'MALE'])
            ->assertUnprocessable();

        $this->assertSame(0, FamilyActivity::count());
    }

    // ------------------------------------------------------------ residence

    public function test_address_correction_writes_residence_updated(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}/residence", ['city' => 'مدينة ب'])
            ->assertOk();

        $this->assertSame(['RESIDENCE_UPDATED'], $this->eventTypes($this->family));
        $this->assertStringNotContainsString('مدينة ب', $this->storedActivitiesJson());
    }

    public function test_displacement_correction_writes_displacement_updated(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}/residence", [
                'displacement_status' => 'DISPLACED',
                'displacement_location_text' => 'مكان نزوح تجريبي',
            ])
            ->assertOk();

        $this->assertSame(['DISPLACEMENT_UPDATED'], $this->eventTypes($this->family));
        $this->assertStringNotContainsString('مكان نزوح تجريبي', $this->storedActivitiesJson());
    }

    public function test_residence_request_touching_both_groups_writes_both_events(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}/residence", [
                'city' => 'مدينة ج',
                'original_residence_text' => 'سكن أصلي تجريبي',
            ])
            ->assertOk();

        $this->assertSame(['RESIDENCE_UPDATED', 'DISPLACEMENT_UPDATED'], $this->eventTypes($this->family));
    }

    // ------------------------------------------------------------ health

    public function test_health_create_update_close_write_events_with_broad_type_only(): void
    {
        $person = $this->member();

        $id = $this->createChronic($person)->assertCreated()->json('data.id');
        $this->actingAs($this->user)
            ->patchJson("/api/v1/health-records/{$id}", ['details' => self::DETAILS.' محدّثة'])
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/v1/health-records/{$id}/close", ['ended_at' => '2026-09-24'])
            ->assertOk();

        $this->assertSame(
            ['HEALTH_RECORD_CREATED', 'HEALTH_RECORD_UPDATED', 'HEALTH_RECORD_CLOSED'],
            $this->eventTypes($this->family)
        );

        foreach (FamilyActivity::all() as $activity) {
            $this->assertSame('health_record', $activity->subject_type);
            $this->assertSame(['health_record_type' => 'CHRONIC_DISEASE'], $activity->metadata);
        }
        $this->assertNoSensitiveValues($this->storedActivitiesJson());

        $body = $this->activities()->assertOk()
            ->assertJsonPath('data.0.event_type', 'HEALTH_RECORD_CLOSED')
            ->assertJsonPath('data.0.metadata.health_record_type', 'CHRONIC_DISEASE')
            ->assertJsonPath('data.0.subject.type', 'health_record')
            ->assertJsonPath('data.0.subject.person.person_code', $person->person_code)
            ->getContent();
        $this->assertNoSensitiveValues($body);
    }

    public function test_disability_activity_does_not_carry_the_disability_type(): void
    {
        $person = $this->member();

        $this->actingAs($this->user)->postJson("/api/v1/families/{$this->family->family_code}/health-records", [
            'person_code' => $person->person_code,
            'type' => 'DISABILITY',
            'disability_type_id' => DisabilityType::where('code', 'VISUAL')->value('id'),
        ])->assertCreated();

        $stored = $this->storedActivitiesJson();
        $this->assertStringContainsString('DISABILITY', $stored);
        $this->assertStringNotContainsString('VISUAL', $stored);
        $this->assertStringNotContainsString('بصرية', $stored);
    }

    public function test_rejected_health_create_writes_no_activity(): void
    {
        // Pregnancy is FEMALE-only: the household head is MALE.
        $this->actingAs($this->user)->postJson("/api/v1/families/{$this->family->family_code}/health-records", [
            'person_code' => $this->head->person_code,
            'type' => 'PREGNANCY',
        ])->assertUnprocessable();

        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_closing_an_already_closed_record_writes_no_activity(): void
    {
        $record = PersonHealthRecord::factory()->create([
            'person_id' => $this->head->id,
            'type' => HealthRecordType::CHRONIC_DISEASE->value,
            'condition_name' => self::CONDITION,
            'ended_at' => '2026-09-01',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/health-records/{$record->uuid}/close")
            ->assertUnprocessable();

        $this->assertSame(0, FamilyActivity::count());
    }

    // ------------------------------------------------------------ actor

    public function test_actor_is_the_authenticated_user_and_exposes_name_only(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", ['notes' => 'تعديل'])
            ->assertOk();

        $this->assertSame($this->user->id, FamilyActivity::first()->actor_user_id);

        $viewer = $this->user('REVIEWER');
        $body = $this->activities(as: $viewer)->assertOk()
            ->assertJsonPath('data.0.actor.name', 'مدخل بيانات تجريبي')
            ->assertJsonPath('data.0.actor', ['name' => 'مدخل بيانات تجريبي'])
            ->getContent();
        $this->assertStringNotContainsString($this->user->email, $body);
    }

    // ------------------------------------------------------------ reading

    public function test_activity_is_family_scoped(): void
    {
        $other = Family::factory()->create();
        $otherHead = Person::factory()->create();
        FamilyMembership::factory()->householdHead()->create(['family_id' => $other->id, 'person_id' => $otherHead->id]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$other->family_code}", ['notes' => 'أخرى'])->assertOk();
        $this->actingAs($this->user)
            ->patchJson("/api/v1/people/{$otherHead->person_code}", ['full_name' => 'اسم آخر'])->assertOk();
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", ['notes' => 'هذه'])->assertOk();

        $this->assertSame(['FAMILY_UPDATED', 'PERSON_UPDATED'], $this->eventTypes($other));

        $this->activities()->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_type', 'FAMILY_UPDATED')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_newest_first_ordering(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", ['notes' => 'أول'])->assertOk();

        Carbon::setTestNow('2026-09-24 11:00:00');
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}/residence", ['city' => 'مدينة د'])->assertOk();

        // Same second: the later insert still comes first.
        $this->actingAs($this->user)
            ->patchJson("/api/v1/people/{$this->head->person_code}", ['full_name' => 'اسم جديد'])->assertOk();

        $response = $this->activities()->assertOk();
        $this->assertSame(
            ['PERSON_UPDATED', 'RESIDENCE_UPDATED', 'FAMILY_UPDATED'],
            array_column($response->json('data'), 'event_type')
        );
        $this->assertSame(Carbon::parse('2026-09-24 11:00:00')->toIso8601String(), $response->json('data.0.occurred_at'));
    }

    public function test_pagination_uses_standard_shape(): void
    {
        DB::transaction(function () {
            for ($i = 0; $i < 25; $i++) {
                FamilyActivity::create([
                    'family_id' => $this->family->id,
                    'event_type' => FamilyActivityType::FAMILY_UPDATED,
                ]);
            }
        });

        $this->activities()->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data', 'links' => ['next'], 'meta' => ['current_page']]);

        $this->activities(query: ['page' => 2])->assertOk()->assertJsonCount(5, 'data');
        $this->activities(query: ['per_page' => 1000])->assertOk()->assertJsonPath('meta.per_page', 50);
    }

    public function test_existing_families_have_no_fabricated_history(): void
    {
        // Pre-existing records of every kind, created without Domain Actions.
        PersonHealthRecord::factory()->create([
            'person_id' => $this->head->id,
            'type' => 'CHRONIC_DISEASE',
            'condition_name' => self::CONDITION,
        ]);

        $this->activities()->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
        $this->assertSame(0, FamilyActivity::count());
    }

    // ------------------------------------------------------------ authorization

    public function test_permitted_staff_roles_can_read(): void
    {
        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'DATA_ENTRY', 'REVIEWER', 'SOCIAL_WORKER'] as $role) {
            $this->activities(as: $this->user($role))->assertOk();
        }
    }

    public function test_unauthorized_roles_receive_403(): void
    {
        foreach (['REPORTS_VIEWER', 'FAMILY_USER'] as $role) {
            $this->activities(as: $this->user($role))->assertForbidden();
        }

        $this->activities(as: User::factory()->create())->assertForbidden();
    }

    public function test_unauthenticated_request_receives_401(): void
    {
        $this->getJson("/api/v1/families/{$this->family->family_code}/activities")->assertUnauthorized();
    }

    public function test_health_events_are_hidden_without_health_record_view(): void
    {
        $person = $this->member();
        $this->createChronic($person)->assertCreated();
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", ['notes' => 'x'])->assertOk();

        $role = Role::create(['name' => 'ACTIVITY_ONLY_TEST', 'guard_name' => 'web']);
        $role->givePermissionTo('activity-log.view');

        $this->activities(as: $this->user('ACTIVITY_ONLY_TEST'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_type', 'FAMILY_UPDATED');
    }

    // ------------------------------------------------------------ immutability

    public function test_no_mutation_activity_routes_exist(): void
    {
        $activityRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'activit'));

        $this->assertNotEmpty($activityRoutes);
        foreach ($activityRoutes as $route) {
            $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods(), $route->uri());
        }

        $admin = $this->user('SUPER_ADMIN');
        $url = "/api/v1/families/{$this->family->family_code}/activities";
        $this->actingAs($admin)->postJson($url, ['event_type' => 'FAMILY_CREATED'])->assertMethodNotAllowed();
        $this->actingAs($admin)->patchJson($url, [])->assertMethodNotAllowed();
        $this->actingAs($admin)->deleteJson($url)->assertMethodNotAllowed();
        $this->assertSame(0, FamilyActivity::count());
    }

    public function test_activity_records_cannot_be_updated_or_deleted(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/families/{$this->family->family_code}", ['notes' => 'x'])->assertOk();
        $activity = FamilyActivity::firstOrFail();

        try {
            $activity->update(['event_type' => FamilyActivityType::FAMILY_CREATED]);
            $this->fail('Expected update to be refused.');
        } catch (\LogicException) {
        }

        try {
            $activity->delete();
            $this->fail('Expected delete to be refused.');
        } catch (\LogicException) {
        }

        $this->assertSame('FAMILY_UPDATED', $activity->fresh()->event_type->value);
    }

    public function test_metadata_outside_the_allow_list_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DB::transaction(fn () => FamilyActivityLog::record(
            $this->family->id,
            FamilyActivityType::PERSON_UPDATED,
            $this->head,
            $this->user->id,
            ['national_id' => self::NATIONAL_ID],
        ));
    }
}
