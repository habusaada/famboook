<?php

namespace Tests\Feature\ChangeRequests\PersonCorrection;

use App\Actions\UpdatePersonAction;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\Gender;
use App\Enums\HealthRecordType;
use App\Enums\MaritalStatus;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\ChangeRequests\Handlers\PersonCorrectionHandler;
use App\Support\ChangeRequests\Handlers\ResidenceUpdateHandler;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PERSON_CORRECTION end to end through the Family and Staff APIs, with the
 * real handler registered through the test-only registry (it is also in
 * ChangeRequestTypes::PRODUCTION). Synthetic data only.
 */
class PersonCorrectionTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const FAMILY_URI = '/api/v1/family/change-requests';

    private const STAFF_URI = '/api/v1/change-requests';

    private FamilyAccessResult $context;

    private User $reviewer;

    private Person $member;

    private FamilyMembership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        config(['change_requests.family_submission_mode' => 'GENERAL']);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::PERSON_CORRECTION->value => new PersonCorrectionHandler,
            ChangeRequestType::RESIDENCE_UPDATE->value => new ResidenceUpdateHandler,
            ChangeRequestType::ADD_FAMILY_MEMBER->value => new AddFamilyMemberHandler,
        ]));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead()['user']);
        $this->member = Person::factory()->create([
            'full_name' => 'سارة الاختبار', 'gender' => Gender::FEMALE, 'marital_status' => MaritalStatus::SINGLE,
            'birth_date' => '2005-03-01', 'national_id' => '402234567', 'mobile' => '0591112233',
        ]);
        $this->membership = FamilyMembership::factory()->create([
            'family_id' => $this->context->family->id, 'person_id' => $this->member->id, 'is_household_head' => false, 'is_active' => true,
        ]);
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
    }

    private function ref(?FamilyMembership $membership = null): string
    {
        $membership ??= $this->membership;

        return HouseholdMemberReference::of($membership->family_id, $membership->id);
    }

    /** The member's current values, as the form sends them, with overrides. */
    private function proposal(array $overrides = []): array
    {
        return [
            'member_ref' => $this->ref(), 'full_name' => 'سارة الاختبار', 'birth_date' => '2005-03-01',
            'gender' => 'FEMALE', 'marital_status' => 'SINGLE', ...$overrides,
        ];
    }

    private function family(string $method, string $uri = '', array $body = [], ?FamilyAccessResult $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->context)->user->fresh())->json($method, self::FAMILY_URI.$uri, $body);
    }

    private function staff(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->actingAs($this->reviewer->fresh())->json($method, self::STAFF_URI.$uri, $body);
    }

    private function submit(array $data, ?string $reference = null, ?FamilyAccessResult $as = null): TestResponse
    {
        return $this->family('POST', '', ['type' => 'PERSON_CORRECTION', 'client_reference' => $reference ?? (string) Str::uuid(), 'data' => $data], $as);
    }

    private function submitted(array $overrides = ['full_name' => 'سارة محمد الاختبار']): ChangeRequest
    {
        return ChangeRequest::where('uuid', $this->submit($this->proposal($overrides))->assertCreated()->json('data.id'))->sole();
    }

    private function underReview(array $overrides = ['full_name' => 'سارة محمد الاختبار']): ChangeRequest
    {
        $request = $this->submitted($overrides);
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();

        return $request;
    }

    private function approved(array $overrides = ['full_name' => 'سارة محمد الاختبار']): ChangeRequest
    {
        $request = $this->underReview($overrides);
        $this->staff('POST', "/{$request->uuid}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');

        return $request->fresh();
    }

    // ---------------------------------------------------------------- submission and validation

    public function test_a_submission_stores_only_the_changed_fields_for_the_member_and_changes_nothing(): void
    {
        $before = $this->member->fresh()->only(['full_name', 'birth_date', 'gender', 'marital_status', 'national_id', 'mobile']);

        $request = ChangeRequest::where('uuid', $this->submit($this->proposal(['full_name' => '  سارة محمد الاختبار ', 'marital_status' => 'MARRIED']), null)
            ->assertCreated()->json('data.id'))->sole();

        $this->assertEquals(['full_name' => 'سارة محمد الاختبار', 'marital_status' => 'MARRIED'], $request->submitted_data);
        $this->assertSame([$this->membership->id, $this->member->id], [$request->target_membership_id, $request->person_id]);
        $this->assertEquals($before, $this->member->fresh()->only(array_keys($before)));
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::PERSON_UPDATED)->count());
    }

    public function test_the_household_head_may_correct_their_own_data(): void
    {
        $headMembership = FamilyMembership::where('family_id', $this->context->family->id)->where('is_household_head', true)->sole();
        $head = $headMembership->person;
        $data = [
            'member_ref' => $this->ref($headMembership), 'full_name' => $head->full_name.' المصحح', 'birth_date' => $head->birth_date?->toDateString(),
            'gender' => $head->gender->value, 'marital_status' => $head->marital_status->value,
        ];

        $this->submit($data)->assertCreated();
    }

    public function test_invalid_values_unknown_fields_and_unknown_members_are_refused(): void
    {
        $cases = [
            'blank name' => [$this->proposal(['full_name' => '  ']), 'full_name'],
            'long name' => [$this->proposal(['full_name' => str_repeat('س', 256)]), 'full_name'],
            'future birth date' => [$this->proposal(['birth_date' => now()->addDay()->toDateString()]), 'birth_date'],
            'bad date' => [$this->proposal(['birth_date' => '01/03/2005']), 'birth_date'],
            'bad gender' => [$this->proposal(['gender' => 'OTHER']), 'gender'],
            'bad marital' => [$this->proposal(['marital_status' => 'ENGAGED']), 'marital_status'],
            'missing field' => [array_diff_key($this->proposal(['full_name' => 'س']), ['gender' => 1]), 'gender'],
            'national id' => [$this->proposal(['national_id' => '409999999']), 'national_id'],
            'mobile' => [$this->proposal(['mobile' => '0599999999']), 'mobile'],
            'life status' => [$this->proposal(['life_status' => 'DECEASED']), 'life_status'],
            'person id' => [$this->proposal(['person_id' => $this->member->id]), 'person_id'],
            'malformed ref' => [$this->proposal(['member_ref' => (string) $this->membership->id]), 'member_ref'],
        ];
        foreach ($cases as $label => [$data, $key]) {
            $this->assertArrayHasKey($key, $this->submit($data)->assertUnprocessable()->json('errors'), $label);
        }

        // Unchanged: refused.
        $this->submit($this->proposal())->assertUnprocessable()->assertJsonValidationErrors('data');

        // A well-formed reference to another Family's member, or an ended membership: not found.
        $foreign = FamilyMembership::factory()->create(['family_id' => Family::factory()->create()->id, 'is_active' => true]);
        $this->submit($this->proposal(['member_ref' => $this->ref($foreign), 'full_name' => 'س']))->assertNotFound()->assertJsonPath('code', 'CHANGE_REQUEST_NOT_FOUND');
        $ended = FamilyMembership::factory()->create(['family_id' => $this->context->family->id, 'is_active' => false]);
        $this->submit($this->proposal(['member_ref' => $this->ref($ended), 'full_name' => 'س']))->assertNotFound();

        $this->assertSame(0, ChangeRequest::count());
        $this->assertSame('سارة الاختبار', $this->member->fresh()->full_name);
    }

    public function test_the_health_rule_on_gender_is_kept(): void
    {
        PersonHealthRecord::factory()->create(['person_id' => $this->member->id, 'type' => HealthRecordType::PREGNANCY->value, 'condition_name' => null]);

        $this->submit($this->proposal(['gender' => 'MALE']))->assertUnprocessable()->assertJsonValidationErrors('gender');
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_a_new_health_record_after_approval_blocks_the_gender_apply(): void
    {
        $request = $this->approved(['gender' => 'MALE']);
        PersonHealthRecord::factory()->create(['person_id' => $this->member->id, 'type' => HealthRecordType::BREASTFEEDING->value, 'condition_name' => null]);

        $this->staff('POST', "/{$request->uuid}/apply")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertSame(Gender::FEMALE, $this->member->fresh()->gender);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    // ---------------------------------------------------------------- review, approval, apply, rejection

    public function test_approve_changes_nothing_and_apply_corrects_only_the_proposed_fields(): void
    {
        $request = $this->approved(['full_name' => 'سارة محمد الاختبار', 'birth_date' => '2005-04-01', 'marital_status' => 'MARRIED']);
        $this->assertSame('سارة الاختبار', $this->member->fresh()->full_name, 'approval changes nothing');
        $this->staff('GET', "/{$request->uuid}")->assertJsonPath('data.approval_attestations', []);

        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');

        $fresh = $this->member->fresh();
        $this->assertSame(['سارة محمد الاختبار', '2005-04-01', MaritalStatus::MARRIED, Gender::FEMALE, '402234567', '0591112233'],
            [$fresh->full_name, $fresh->birth_date->toDateString(), $fresh->marital_status, $fresh->gender, $fresh->national_id, $fresh->mobile]);
        $this->assertSame($this->reviewer->id, $fresh->updated_by);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::PERSON_UPDATED)->count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());

        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::PERSON_UPDATED)->count());
    }

    public function test_a_rejected_request_changes_nothing_and_the_family_sees_why(): void
    {
        $request = $this->underReview();
        $this->staff('POST', "/{$request->uuid}/reject", ['rejection_reason_code' => 'CANNOT_VERIFY', 'public_message' => 'يرجى إحضار الوثيقة'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->assertSame('سارة الاختبار', $this->member->fresh()->full_name);
        $this->family('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.rejection.reason_code', 'CANNOT_VERIFY')->assertJsonPath('data.rejection.message', 'يرجى إحضار الوثيقة');
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::PERSON_UPDATED)->count());
    }

    public function test_staff_and_family_see_the_member_and_the_old_and_proposed_values(): void
    {
        $request = $this->submitted(['full_name' => 'سارة محمد الاختبار', 'gender' => 'MALE', 'marital_status' => 'MARRIED']);
        $rows = [
            ['label' => 'الفرد المعني', 'current' => 'سارة الاختبار', 'proposed' => 'سارة الاختبار'],
            ['label' => 'الاسم الكامل', 'current' => 'سارة الاختبار', 'proposed' => 'سارة محمد الاختبار'],
            ['label' => 'الجنس', 'current' => 'أنثى', 'proposed' => 'ذكر'],
            ['label' => 'الحالة الاجتماعية', 'current' => 'أعزب/عزباء', 'proposed' => 'متزوج/ة'],
        ];

        foreach ([$this->staff('GET', "/{$request->uuid}"), $this->family('GET', "/{$request->uuid}")] as $response) {
            $response->assertOk()->assertJsonPath('data.presentation', ['rows' => $rows]);
            foreach (['402234567', '0591112233', 'submitted_data', $this->ref()] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent(), $leak);
            }
        }
    }

    // ---------------------------------------------------------------- duplicates, stale data, isolation

    public function test_one_open_correction_per_member_and_idempotent_replays(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->submit($this->proposal(['full_name' => 'سارة أ']), $reference)->assertCreated();
        $this->submit($this->proposal(['full_name' => 'سارة أ']), $reference)->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->submit($this->proposal(['full_name' => 'سارة ب']), $reference)->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_IDEMPOTENCY_CONFLICT');
        $this->submit($this->proposal(['full_name' => 'سارة ب']))->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_ALREADY_OPEN');

        // Another member is independent.
        $other = FamilyMembership::factory()->create(['family_id' => $this->context->family->id, 'is_active' => true]);
        $person = $other->person;
        $this->submit([
            'member_ref' => $this->ref($other), 'full_name' => 'اسم آخر', 'birth_date' => $person->birth_date?->toDateString(),
            'gender' => $person->gender->value, 'marital_status' => $person->marital_status->value,
        ])->assertCreated();
        $this->assertSame(2, ChangeRequest::count());
    }

    public function test_a_registry_change_after_submission_or_approval_blocks_the_request(): void
    {
        $request = $this->underReview();
        app(UpdatePersonAction::class)->handle($this->member, ['marital_status' => 'MARRIED'], $this->reviewer->id);
        $this->staff('POST', "/{$request->uuid}/approve")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');

        $this->staff('POST', "/{$request->uuid}/reject", ['rejection_reason_code' => 'NO_LONGER_APPLICABLE'])->assertOk();
        $second = $this->approved(['full_name' => 'سارة ج']);
        app(UpdatePersonAction::class)->handle($this->member->fresh(), ['full_name' => 'سارة د'], $this->reviewer->id);
        $this->staff('POST', "/{$request->uuid}/apply")->assertStatus(409);
        $this->staff('POST', "/{$second->uuid}/apply")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertSame('سارة د', $this->member->fresh()->full_name, 'the newer registry value is kept');
    }

    public function test_a_member_who_left_the_family_cannot_be_corrected(): void
    {
        $request = $this->underReview();
        $this->membership->forceFill(['is_active' => false, 'ended_at' => now()->toDateString()])->save();

        $this->staff('POST', "/{$request->uuid}/approve")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_NOT_APPLICABLE');
        $this->assertSame('سارة الاختبار', $this->member->fresh()->full_name);
    }

    public function test_another_family_cannot_see_act_on_or_target_this_family(): void
    {
        $request = $this->submitted();
        $stranger = app(FamilyAccessResolver::class)->familyContext($this->activatedHead('223456789')['user']);

        $this->family('GET', "/{$request->uuid}", as: $stranger)->assertNotFound();
        $this->family('POST', "/{$request->uuid}/cancel", as: $stranger)->assertNotFound();
        // The stranger names this Family's member: not found inside their own Family.
        $this->submit($this->proposal(['full_name' => 'س']), null, $stranger)->assertNotFound();
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }

    public function test_only_authorized_staff_approve_and_apply(): void
    {
        $request = $this->underReview();
        $dataEntry = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('DATA_ENTRY', 'web')));

        $this->actingAs($dataEntry)->postJson(self::STAFF_URI."/{$request->uuid}/approve")->assertForbidden();
        $this->actingAs($this->context->user->fresh())->postJson(self::STAFF_URI."/{$request->uuid}/approve")->assertForbidden();
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_it_is_a_production_type(): void
    {
        $this->assertSame(PersonCorrectionHandler::class, ChangeRequestTypes::PRODUCTION['PERSON_CORRECTION'] ?? null);
        $this->assertTrue(ChangeRequestTypes::production()->has(ChangeRequestType::PERSON_CORRECTION));
    }
}
