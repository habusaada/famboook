<?php

namespace Tests\Feature\ChangeRequests\DeathReport;

use App\Actions\RecordPersonDeathAction;
use App\Actions\UpdatePersonAction;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\LifeStatus;
use App\Enums\LifeStatusVerificationMethod;
use App\Enums\MaritalStatus;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\ChangeRequests\Handlers\DeathReportHandler;
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
 * DEATH_REPORT end to end through the Family and Staff APIs, with the real
 * handler registered through the test-only registry (it is also in
 * ChangeRequestTypes::PRODUCTION). Synthetic data only.
 */
class DeathReportTest extends TestCase
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
            ChangeRequestType::DEATH_REPORT->value => new DeathReportHandler,
            ChangeRequestType::PERSON_CORRECTION->value => new PersonCorrectionHandler,
            ChangeRequestType::RESIDENCE_UPDATE->value => new ResidenceUpdateHandler,
            ChangeRequestType::ADD_FAMILY_MEMBER->value => new AddFamilyMemberHandler,
        ]));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->context = app(FamilyAccessResolver::class)->familyContext($this->activatedHead()['user']);
        $this->member = Person::factory()->create([
            'full_name' => 'جدّة الاختبار', 'birth_date' => '1940-05-01', 'life_status' => LifeStatus::ALIVE, 'death_date' => null,
            'marital_status' => MaritalStatus::WIDOWED,
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

    private function proposal(array $overrides = []): array
    {
        return ['member_ref' => $this->ref(), 'death_date' => '2026-09-20', 'death_date_unknown' => false, ...$overrides];
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
        return $this->family('POST', '', ['type' => 'DEATH_REPORT', 'client_reference' => $reference ?? (string) Str::uuid(), 'data' => $data], $as);
    }

    private function submitted(array $overrides = []): ChangeRequest
    {
        return ChangeRequest::where('uuid', $this->submit($this->proposal($overrides))->assertCreated()->json('data.id'))->sole();
    }

    private function underReview(array $overrides = []): ChangeRequest
    {
        $request = $this->submitted($overrides);
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();

        return $request;
    }

    private function approved(array $overrides = [], string $method = 'STAFF_CALLBACK'): ChangeRequest
    {
        $request = $this->underReview($overrides);
        $this->staff('POST', "/{$request->uuid}/approve", ['verification_method' => $method])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        return $request->fresh();
    }

    private function deaths(): int
    {
        return FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->count();
    }

    // ---------------------------------------------------------------- submission and validation

    public function test_a_submission_stores_the_reported_date_and_changes_nothing(): void
    {
        $request = $this->submitted();

        $this->assertEquals(['death_date' => '2026-09-20'], $request->submitted_data);
        $this->assertSame([$this->membership->id, $this->member->id], [$request->target_membership_id, $request->person_id]);
        $this->assertSame(LifeStatus::ALIVE, $this->member->fresh()->life_status, 'the reported date is not canonical until applied');
        $this->assertNull($this->member->fresh()->death_date);
        $this->assertSame(0, $this->deaths());
    }

    public function test_an_unknown_date_is_stored_as_null_and_never_invented(): void
    {
        $request = $this->submitted(['death_date' => null, 'death_date_unknown' => true]);

        $this->assertSame(['death_date' => null], $request->submitted_data);
    }

    public function test_invalid_dates_unknown_fields_and_unknown_members_are_refused(): void
    {
        $cases = [
            'no date and not unknown' => [$this->proposal(['death_date' => null]), 'death_date'],
            'date and unknown' => [$this->proposal(['death_date_unknown' => true]), 'death_date'],
            'missing date key' => [array_diff_key($this->proposal(), ['death_date' => 1]), 'death_date'],
            'missing unknown flag' => [array_diff_key($this->proposal(), ['death_date_unknown' => 1]), 'death_date_unknown'],
            'future date' => [$this->proposal(['death_date' => now()->addDay()->toDateString()]), 'death_date'],
            'bad format' => [$this->proposal(['death_date' => '20/09/2026']), 'death_date'],
            'impossible date' => [$this->proposal(['death_date' => '2026-02-30']), 'death_date'],
            'before birth' => [$this->proposal(['death_date' => '1939-12-31']), 'death_date'],
            'life status' => [$this->proposal(['life_status' => 'DECEASED']), 'life_status'],
            'verification method' => [$this->proposal(['verification_method' => 'IN_PERSON']), 'verification_method'],
            'person id' => [$this->proposal(['person_id' => $this->member->id]), 'person_id'],
            'malformed ref' => [$this->proposal(['member_ref' => (string) $this->membership->id]), 'member_ref'],
        ];
        foreach ($cases as $label => [$data, $key]) {
            $this->assertArrayHasKey($key, $this->submit($data)->assertUnprocessable()->json('errors'), $label);
        }
        $this->submit($this->proposal(['death_date' => now()->addDay()->toDateString()]))
            ->assertJsonPath('errors.death_date.0', 'تاريخ الوفاة لا يمكن أن يكون في المستقبل.');
        $this->submit($this->proposal(['death_date' => '1939-12-31']))
            ->assertJsonPath('errors.death_date.0', 'تاريخ الوفاة لا يمكن أن يسبق تاريخ الميلاد.');

        // A well-formed reference to another Family's member, or an ended membership: not found.
        $foreign = FamilyMembership::factory()->create(['family_id' => Family::factory()->create()->id, 'is_active' => true]);
        $this->submit($this->proposal(['member_ref' => $this->ref($foreign)]))->assertNotFound()->assertJsonPath('code', 'CHANGE_REQUEST_NOT_FOUND');
        $ended = FamilyMembership::factory()->create(['family_id' => $this->context->family->id, 'is_active' => false]);
        $this->submit($this->proposal(['member_ref' => $this->ref($ended)]))->assertNotFound();

        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_the_household_head_cannot_be_reported(): void
    {
        $headMembership = FamilyMembership::where('family_id', $this->context->family->id)->where('is_household_head', true)->sole();

        $this->submit($this->proposal(['member_ref' => $this->ref($headMembership), 'death_date' => null, 'death_date_unknown' => true]))
            ->assertUnprocessable()->assertJsonPath('errors.member_ref.0', 'لا يمكن الإبلاغ عن وفاة رب الأسرة من خلال البوابة. تواصل مع فريق السجل.');
        $this->assertSame(0, ChangeRequest::count());
        $this->assertNotSame(LifeStatus::DECEASED, $headMembership->person->fresh()->life_status);
    }

    public function test_an_already_deceased_member_cannot_be_reported(): void
    {
        app(RecordPersonDeathAction::class)->handle($this->member, '2026-01-01', LifeStatusVerificationMethod::IN_PERSON, $this->reviewer->id);

        $this->submit($this->proposal())->assertUnprocessable()->assertJsonPath('errors.member_ref.0', 'وفاة هذا الفرد مسجّلة مسبقًا في السجل.');
        $this->assertSame(0, ChangeRequest::count());
        $this->assertSame('2026-01-01', $this->member->fresh()->death_date->toDateString());
    }

    public function test_a_member_with_unknown_life_status_may_be_reported(): void
    {
        $this->member->forceFill(['life_status' => LifeStatus::UNKNOWN])->save();

        $request = $this->approved();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');
        $this->assertSame(LifeStatus::DECEASED, $this->member->fresh()->life_status);
    }

    // ---------------------------------------------------------------- approval and the verification method (FP-ADR-077)

    public function test_approval_requires_a_known_verification_method_and_records_only_its_code(): void
    {
        $request = $this->underReview();
        $this->staff('GET', "/{$request->uuid}")->assertJsonPath('data.approval_requires_verification_method', true)
            ->assertJsonPath('data.approval_attestations', [])->assertJsonPath('data.approval_verification_method', null);

        $this->staff('POST', "/{$request->uuid}/approve")->assertUnprocessable()->assertJsonValidationErrors('verification_method');
        $this->staff('POST', "/{$request->uuid}/approve", ['verification_method' => 'GUESS'])->assertUnprocessable()->assertJsonValidationErrors('verification_method');
        $this->staff('POST', "/{$request->uuid}/approve", ['verification_method' => 'IN_PERSON', 'attestations' => ['IDENTITY_VERIFIED']])
            ->assertUnprocessable()->assertJsonValidationErrors('attestations');
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);

        $this->staff('POST', "/{$request->uuid}/approve", ['verification_method' => 'AUTHORIZED_RECORD_REVIEW'])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $event = WorkflowEvent::where('workflowable_id', $request->id)->where('event_type', WorkflowEventType::APPROVED)->sole();
        $this->assertSame(['verification_method' => 'AUTHORIZED_RECORD_REVIEW'], $event->metadata);
        $this->staff('GET', "/{$request->uuid}")->assertJsonPath('data.approval_verification_method', 'AUTHORIZED_RECORD_REVIEW');
        $this->assertSame(LifeStatus::ALIVE, $this->member->fresh()->life_status, 'approval changes nothing');
        $this->assertSame(0, $this->deaths());
    }

    public function test_other_types_take_no_verification_method(): void
    {
        $other = ChangeRequest::where('uuid', $this->family('POST', '', [
            'type' => 'PERSON_CORRECTION', 'client_reference' => (string) Str::uuid(),
            'data' => ['member_ref' => $this->ref(), 'full_name' => 'جدّة مصححة', 'birth_date' => '1940-05-01',
                'gender' => $this->member->gender->value, 'marital_status' => 'WIDOWED'],
        ])->assertCreated()->json('data.id'))->sole();
        $this->staff('POST', "/{$other->uuid}/start-review")->assertOk();
        $this->staff('GET', "/{$other->uuid}")->assertJsonPath('data.approval_requires_verification_method', false);

        $this->staff('POST', "/{$other->uuid}/approve", ['verification_method' => 'IN_PERSON'])->assertUnprocessable()->assertJsonValidationErrors('verification_method');
        $this->staff('POST', "/{$other->uuid}/approve")->assertOk();
        $this->assertNull(WorkflowEvent::where('workflowable_id', $other->id)->where('event_type', WorkflowEventType::APPROVED)->sole()->metadata);
    }

    // ---------------------------------------------------------------- apply and rejection

    public function test_apply_records_the_death_through_the_domain_action_with_the_approved_method(): void
    {
        $headMembership = FamilyMembership::where('family_id', $this->context->family->id)->where('is_household_head', true)->sole();
        $request = $this->approved([], 'IN_PERSON');

        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');

        $fresh = $this->member->fresh();
        $this->assertSame([LifeStatus::DECEASED, '2026-09-20', $this->reviewer->id], [$fresh->life_status, $fresh->death_date->toDateString(), $fresh->updated_by]);
        // Nothing else changes: the membership stays, the head stays, the marital status stays.
        $this->assertTrue($this->membership->fresh()->is_active);
        $this->assertFalse($this->membership->fresh()->is_household_head);
        $this->assertTrue($headMembership->fresh()->is_household_head);
        $this->assertSame(MaritalStatus::WIDOWED, $fresh->marital_status);

        $death = FamilyActivity::where('event_type', FamilyActivityType::PERSON_DEATH_RECORDED)->sole();
        $this->assertSame(['verification_method' => 'IN_PERSON'], $death->metadata);
        $this->assertSame($this->reviewer->id, $death->actor_user_id);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());

        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, $this->deaths());
    }

    public function test_an_unknown_date_is_applied_as_null(): void
    {
        $request = $this->approved(['death_date' => null, 'death_date_unknown' => true]);
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk();

        $this->assertSame(LifeStatus::DECEASED, $this->member->fresh()->life_status);
        $this->assertNull($this->member->fresh()->death_date);
    }

    public function test_a_rejected_report_changes_nothing_and_the_family_sees_why(): void
    {
        $request = $this->underReview();
        $this->staff('POST', "/{$request->uuid}/reject", ['rejection_reason_code' => 'CANNOT_VERIFY', 'public_message' => 'يرجى مراجعة المكتب'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->assertSame(LifeStatus::ALIVE, $this->member->fresh()->life_status);
        $this->family('GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.rejection.reason_code', 'CANNOT_VERIFY');
        $this->assertSame(0, $this->deaths());
    }

    public function test_staff_and_family_see_the_member_and_the_reported_death(): void
    {
        $request = $this->submitted();
        $rows = [
            ['label' => 'الفرد المعني', 'current' => 'جدّة الاختبار', 'proposed' => 'جدّة الاختبار'],
            ['label' => 'تاريخ الميلاد', 'current' => '1940-05-01', 'proposed' => '1940-05-01'],
            ['label' => 'الحالة الحياتية', 'current' => 'على قيد الحياة', 'proposed' => 'متوفى/ة'],
            ['label' => 'تاريخ الوفاة', 'current' => null, 'proposed' => '2026-09-20'],
        ];
        foreach ([$this->staff('GET', "/{$request->uuid}"), $this->family('GET', "/{$request->uuid}")] as $response) {
            $response->assertOk()->assertJsonPath('data.presentation', ['rows' => $rows]);
            foreach (['submitted_data', 'verification_method":"', $this->ref()] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent(), $leak);
            }
        }

        $other = FamilyMembership::factory()->create(['family_id' => $this->context->family->id, 'is_active' => true]);
        $unknown = $this->submitted(['member_ref' => $this->ref($other), 'death_date' => null, 'death_date_unknown' => true]);
        $this->assertSame('غير معروف', $this->staff('GET', "/{$unknown->uuid}")->json('data.presentation.rows.3.proposed'));
    }

    // ---------------------------------------------------------------- duplicates, stale data, isolation

    public function test_one_open_report_per_member_and_idempotent_replays(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->submit($this->proposal(), $reference)->assertCreated();
        $this->submit($this->proposal(), $reference)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.id', $first->json('data.id'));
        $this->submit($this->proposal(['death_date' => '2026-09-21']), $reference)->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_IDEMPOTENCY_CONFLICT');
        $this->submit($this->proposal(['death_date' => '2026-09-21']))->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_ALREADY_OPEN');

        // After the first is applied, the member is deceased: no new report.
        $request = ChangeRequest::where('uuid', $first->json('data.id'))->sole();
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();
        $this->staff('POST', "/{$request->uuid}/approve", ['verification_method' => 'IN_PERSON'])->assertOk();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk();
        $this->submit($this->proposal())->assertUnprocessable()->assertJsonValidationErrors('member_ref');
        $this->assertSame(1, ChangeRequest::count());
    }

    public function test_a_death_recorded_by_staff_after_approval_blocks_the_apply(): void
    {
        $request = $this->approved();
        app(RecordPersonDeathAction::class)->handle($this->member, '2026-09-01', LifeStatusVerificationMethod::IN_PERSON, $this->reviewer->id);

        $this->staff('POST', "/{$request->uuid}/apply")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertSame('2026-09-01', $this->member->fresh()->death_date->toDateString(), 'the newer registry value is kept');
        $this->assertSame(S::APPROVED, $request->fresh()->status);
        $this->assertSame(1, $this->deaths());
    }

    public function test_a_registry_change_after_submission_or_approval_blocks_the_request(): void
    {
        $request = $this->underReview();
        app(UpdatePersonAction::class)->handle($this->member, ['birth_date' => '1941-05-01'], $this->reviewer->id);
        $this->staff('POST', "/{$request->uuid}/approve", ['verification_method' => 'IN_PERSON'])->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');

        $this->staff('POST', "/{$request->uuid}/reject", ['rejection_reason_code' => 'NO_LONGER_APPLICABLE'])->assertOk();
        $second = $this->approved();
        $this->member->forceFill(['life_status' => LifeStatus::UNKNOWN])->save();
        $this->staff('POST', "/{$second->uuid}/apply")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertSame(LifeStatus::UNKNOWN, $this->member->fresh()->life_status);
        $this->assertSame(0, $this->deaths());
    }

    public function test_a_member_who_became_head_or_left_cannot_be_reported_dead_by_the_family(): void
    {
        $request = $this->approved();
        FamilyMembership::where('family_id', $this->context->family->id)->where('is_household_head', true)->sole()
            ->forceFill(['is_household_head' => false])->save();
        $this->membership->forceFill(['is_household_head' => true])->save();
        $this->staff('POST', "/{$request->uuid}/apply")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertSame(LifeStatus::ALIVE, $this->member->fresh()->life_status);

        $this->membership->forceFill(['is_household_head' => false, 'is_active' => false, 'ended_at' => now()->toDateString()])->save();
        $this->staff('POST', "/{$request->uuid}/apply")->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_NOT_APPLICABLE');
        $this->assertSame(LifeStatus::ALIVE, $this->member->fresh()->life_status);
        $this->assertSame(0, $this->deaths());
    }

    public function test_another_family_cannot_see_act_on_or_target_this_family(): void
    {
        $request = $this->submitted();
        $stranger = app(FamilyAccessResolver::class)->familyContext($this->activatedHead('223456789')['user']);

        $this->family('GET', "/{$request->uuid}", as: $stranger)->assertNotFound();
        $this->family('POST', "/{$request->uuid}/cancel", as: $stranger)->assertNotFound();
        $this->submit($this->proposal(), null, $stranger)->assertNotFound();
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }

    public function test_only_authorized_staff_approve_and_apply(): void
    {
        $request = $this->underReview();
        $dataEntry = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('DATA_ENTRY', 'web')));

        $this->actingAs($dataEntry)->postJson(self::STAFF_URI."/{$request->uuid}/approve", ['verification_method' => 'IN_PERSON'])->assertForbidden();
        $this->actingAs($this->context->user->fresh())->postJson(self::STAFF_URI."/{$request->uuid}/approve", ['verification_method' => 'IN_PERSON'])->assertForbidden();
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);

        $this->staff('POST', "/{$request->uuid}/approve", ['verification_method' => 'IN_PERSON'])->assertOk();
        $this->actingAs($dataEntry)->postJson(self::STAFF_URI."/{$request->uuid}/apply")->assertForbidden();
        $this->assertSame(LifeStatus::ALIVE, $this->member->fresh()->life_status);
    }

    public function test_it_is_a_production_type(): void
    {
        $this->assertSame(DeathReportHandler::class, ChangeRequestTypes::PRODUCTION['DEATH_REPORT'] ?? null);
        $this->assertTrue(ChangeRequestTypes::production()->has(ChangeRequestType::DEATH_REPORT));
    }
}
