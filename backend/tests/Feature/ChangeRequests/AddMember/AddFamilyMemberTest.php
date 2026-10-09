<?php

namespace Tests\Feature\ChangeRequests\AddMember;

use App\Actions\AddFamilyMemberAction;
use App\Enums\ChangeRequestApplyFailure;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\Gender;
use App\Enums\LifeStatus;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\RelationshipType;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\ChangeRequests\Handlers\ResidenceUpdateHandler;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use Database\Seeders\RelationshipTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * ADD_FAMILY_MEMBER end to end (docs/11 FP-ADR-076) through the Family and
 * Staff APIs, with the REAL handler registered through the test-only
 * registry (it is NOT in ChangeRequestTypes::PRODUCTION). Synthetic data
 * and synthetic National IDs only.
 */
class AddFamilyMemberTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const FAMILY_URI = '/api/v1/family/change-requests';

    private const STAFF_URI = '/api/v1/change-requests';

    /** A synthetic member National ID (the head fixture uses 123456789). */
    private const NID = '401234567';

    private FamilyAccessResult $context;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        config(['change_requests.family_submission_mode' => 'GENERAL']);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RelationshipTypeSeeder::class);
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::ADD_FAMILY_MEMBER->value => new AddFamilyMemberHandler,
            ChangeRequestType::RESIDENCE_UPDATE->value => new ResidenceUpdateHandler,
        ]));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->context = $this->contextOf($this->activatedHead()['user']);
        $this->reviewer = $this->staffUser('REVIEWER');
    }

    private function contextOf(User $user): FamilyAccessResult
    {
        return app(FamilyAccessResolver::class)->familyContext($user);
    }

    private function staffUser(string $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName($role, 'web')));
    }

    private function member(array $overrides = []): array
    {
        return [
            'full_name' => 'فرد تجريبي جديد', 'national_id' => self::NID, 'gender' => 'FEMALE', 'relationship' => 'DAUGHTER',
            'birth_date' => '2010-05-01', 'marital_status' => 'SINGLE', 'mobile' => '0591112233',
            ...$overrides,
        ];
    }

    private function family(string $method, string $uri = '', array $body = [], ?FamilyAccessResult $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->context)->user->fresh())->json($method, self::FAMILY_URI.$uri, $body);
    }

    private function staff(string $method, string $uri, array $body = [], ?User $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->reviewer)->fresh())->json($method, self::STAFF_URI.$uri, $body);
    }

    private function submit(array $data, ?FamilyAccessResult $as = null, ?string $reference = null): TestResponse
    {
        return $this->family('POST', '', [
            'type' => 'ADD_FAMILY_MEMBER', 'client_reference' => $reference ?? (string) Str::uuid(), 'data' => $data,
        ], $as);
    }

    private function submitted(array $data = [], ?FamilyAccessResult $as = null): ChangeRequest
    {
        $id = $this->submit($this->member($data), $as)->assertCreated()->json('data.id');

        return ChangeRequest::where('uuid', $id)->sole();
    }

    private function evidence(string $typed = self::NID): array
    {
        return ['attestations' => ['IDENTITY_VERIFIED', 'RELATIONSHIP_VERIFIED'], 'verified_national_id' => $typed];
    }

    private function underReview(array $data = []): ChangeRequest
    {
        $request = $this->submitted($data);
        $this->staff('POST', "/{$request->uuid}/start-review")->assertOk();

        return $request;
    }

    private function approved(array $data = []): ChangeRequest
    {
        $request = $this->underReview($data);
        $this->staff('POST', "/{$request->uuid}/approve", $this->evidence($data['national_id'] ?? self::NID))->assertOk()->assertJsonPath('data.status', 'APPROVED');

        return $request->fresh();
    }

    /** A registry Person created row by row (synthetic), optionally an active member of a Family. */
    private function person(?string $nationalId, ?Family $activeIn = null, array $attributes = []): Person
    {
        $person = Person::factory()->create(['national_id' => $nationalId, 'full_name' => 'شخص مسجّل سابقًا', ...$attributes]);
        if ($activeIn !== null) {
            FamilyMembership::factory()->create(['family_id' => $activeIn->id, 'person_id' => $person->id, 'is_household_head' => false, 'is_active' => true]);
        }

        return $person;
    }

    private function membersOf(Family $family): int
    {
        return FamilyMembership::where('family_id', $family->id)->where('is_active', true)->count();
    }

    // ================================================================ submission

    public function test_a_submission_stores_the_canonical_proposal_and_changes_nothing(): void
    {
        $before = [Person::count(), FamilyMembership::count()];

        $response = $this->submit($this->member(['national_id' => ' ٤٠١-٢٣٤-٥٦٧ ', 'full_name' => '  فرد تجريبي جديد ', 'mobile' => '059 111 2233']))
            ->assertCreated()->assertJsonPath('data.status', 'SUBMITTED');
        $request = ChangeRequest::where('uuid', $response->json('data.id'))->sole();

        $this->assertEquals([
            'full_name' => 'فرد تجريبي جديد', 'national_id' => self::NID, 'gender' => 'FEMALE', 'relationship' => 'DAUGHTER',
            'birth_date' => '2010-05-01', 'marital_status' => 'SINGLE', 'mobile' => '0591112233',
        ], $request->submitted_data);
        $this->assertSame($this->context->family->id, $request->family_id);
        $this->assertNull($request->person_id);
        $this->assertNull($request->target_membership_id);
        $this->assertSame($before, [Person::count(), FamilyMembership::count()]);
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_MEMBER_ADDED)->count());
    }

    public function test_the_national_id_is_required_and_strict_even_for_a_newborn(): void
    {
        $cases = [
            'missing' => array_diff_key($this->member(), ['national_id' => 1]),
            'blank' => $this->member(['national_id' => '   ']),
            'null' => $this->member(['national_id' => null]),
            'eight digits' => $this->member(['national_id' => '40123456']),
            'ten digits' => $this->member(['national_id' => '4012345678']),
            'letters' => $this->member(['national_id' => 'A01234567']),
            'placeholder zeros' => $this->member(['national_id' => '000000000']),
            'placeholder nines' => $this->member(['national_id' => '999999999']),
            'newborn without id' => $this->member(['national_id' => '', 'birth_date' => now()->toDateString(), 'relationship' => 'SON']),
        ];
        foreach ($cases as $label => $data) {
            $response = $this->submit($data)->assertUnprocessable();
            $this->assertArrayHasKey('national_id', $response->json('errors'), $label);
        }
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_the_other_fields_are_validated_and_unknown_fields_refused(): void
    {
        $cases = [
            'name' => [$this->member(['full_name' => ' ']), 'full_name'],
            'gender' => [$this->member(['gender' => 'OTHER']), 'gender'],
            'head relationship' => [$this->member(['relationship' => 'HEAD']), 'relationship'],
            'unknown relationship' => [$this->member(['relationship' => 'COUSIN']), 'relationship'],
            'future birth date' => [$this->member(['birth_date' => now()->addDay()->toDateString()]), 'birth_date'],
            'marital' => [$this->member(['marital_status' => 'ENGAGED']), 'marital_status'],
            'mobile' => [$this->member(['mobile' => '12345']), 'mobile'],
            'family id' => [$this->member(['family_id' => 1]), 'family_id'],
            'person id' => [$this->member(['person_id' => 1]), 'person_id'],
            'head flag' => [$this->member(['is_household_head' => true]), 'is_household_head'],
            'life status' => [$this->member(['life_status' => 'DECEASED']), 'life_status'],
        ];
        foreach ($cases as $label => [$data, $key]) {
            $response = $this->submit($data)->assertUnprocessable();
            $this->assertArrayHasKey($key, $response->json('errors'), $label);
        }

        RelationshipType::where('code', 'OTHER')->update(['is_active' => false]);
        $this->submit($this->member(['relationship' => 'OTHER']))->assertUnprocessable()->assertJsonValidationErrors('relationship');
        $this->assertSame(0, ChangeRequest::count());

        // Optional fields may be left out.
        $this->submit(array_diff_key($this->member(), ['birth_date' => 1, 'marital_status' => 1, 'mobile' => 1]))->assertCreated();
    }

    public function test_the_family_never_learns_whether_the_id_is_known(): void
    {
        $elsewhere = Family::factory()->create();
        $ids = [
            'unknown' => '401234567',
            'unattached person' => '402234567',
            'active elsewhere' => '403234567',
            'own member' => '404234567',
            'ambiguous legacy' => '405234567',
        ];
        $this->person($ids['unattached person']);
        $this->person($ids['active elsewhere'], $elsewhere);
        $this->person($ids['own member'], $this->context->family);
        $this->person('405-234-567');
        $this->person('٤٠٥٢٣٤٥٦٧');

        $shapes = [];
        foreach ($ids as $label => $id) {
            $response = $this->submit($this->member(['national_id' => $id]))->assertCreated();
            $shapes[$label] = array_keys($response->json('data'));
            $detail = $this->family('GET', '/'.$response->json('data.id'))->assertOk();
            $labels = array_column($detail->json('data.presentation.rows'), 'label');
            $this->assertNotContains('مطابقة رقم الهوية في السجل', $labels, $label);
            foreach (['PER-', $elsewhere->family_code, 'شخص مسجّل سابقًا', $id] as $leak) {
                $this->assertStringNotContainsString($leak, $detail->getContent(), "{$label}: {$leak}");
            }
        }
        $this->assertCount(1, array_unique($shapes, SORT_REGULAR));
    }

    public function test_one_open_request_per_family_and_national_id(): void
    {
        $first = $this->submitted();
        $this->submit($this->member(['full_name' => 'اسم آخر']))->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_ALREADY_OPEN');
        $this->submit($this->member(['national_id' => '402234567']))->assertCreated();

        $this->family('POST', "/{$first->uuid}/cancel")->assertOk();
        $this->submit($this->member())->assertCreated();
    }

    public function test_the_same_client_reference_replays(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->submit($this->member(), reference: $reference)->assertCreated();
        $this->submit($this->member(), reference: $reference)->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, ChangeRequest::count());
    }

    // ================================================================ scenario A — a new Person

    public function test_scenario_a_creates_the_person_and_membership_only_at_apply(): void
    {
        $head = FamilyMembership::where('family_id', $this->context->family->id)->where('is_household_head', true)->sole();
        $request = $this->approved();
        $this->assertNull(Person::where('national_id', self::NID)->first(), 'approval changes nothing');

        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');

        $person = Person::where('national_id', self::NID)->sole();
        $this->assertSame(['فرد تجريبي جديد', Gender::FEMALE, LifeStatus::ALIVE, '0591112233', '2010-05-01'],
            [$person->full_name, $person->gender, $person->life_status, $person->mobile, $person->birth_date->toDateString()]);
        $membership = FamilyMembership::where('person_id', $person->id)->sole();
        $this->assertSame([$this->context->family->id, true, false, 'DAUGHTER'],
            [$membership->family_id, $membership->is_active, $membership->is_household_head, $membership->relationshipType->code]);
        $this->assertTrue($head->fresh()->is_household_head && $head->fresh()->is_active);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_MEMBER_ADDED)->count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());

        // A second APPLY is a replay: no second Person or membership.
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_MEMBER_ADDED)->count());
    }

    // ================================================================ scenario B — an existing Person

    public function test_scenario_b_attaches_an_unattached_person_without_changing_their_record(): void
    {
        $old = Family::factory()->create();
        $person = $this->person(self::NID, null, ['full_name' => 'الاسم كما في السجل', 'gender' => Gender::MALE, 'mobile' => null]);
        $history = FamilyMembership::factory()->create(['family_id' => $old->id, 'person_id' => $person->id, 'is_active' => false, 'ended_at' => '2024-01-01']);
        $snapshot = $person->fresh()->only(['full_name', 'national_id', 'gender', 'birth_date', 'mobile', 'marital_status', 'life_status']);

        $request = $this->approved();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');

        $this->assertSame(1, Person::where('national_id', self::NID)->count(), 'no duplicate Person');
        $this->assertEquals($snapshot, $person->fresh()->only(array_keys($snapshot)), 'the registry record is not overwritten');
        $active = FamilyMembership::where('person_id', $person->id)->where('is_active', true)->sole();
        $this->assertSame([$this->context->family->id, false, 'DAUGHTER'], [$active->family_id, $active->is_household_head, $active->relationshipType->code]);
        $this->assertFalse($history->fresh()->is_active, 'history is preserved');
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_MEMBER_ADDED)->where('family_id', $this->context->family->id)->count());
    }

    public function test_a_single_legacy_equivalent_id_is_reused_as_stored(): void
    {
        $person = $this->person('٤٠١-٢٣٤ ٥٦٧');

        $request = $this->approved();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk();

        $this->assertSame('٤٠١-٢٣٤ ٥٦٧', $person->fresh()->national_id, 'legacy value never rewritten');
        $this->assertNull(Person::where('national_id', self::NID)->first(), 'no second Person');
        $this->assertSame($this->context->family->id, $person->activeMembership()->sole()->family_id);
    }

    public function test_a_person_active_in_another_family_is_never_transferred(): void
    {
        $other = Family::factory()->create();
        $person = $this->person(self::NID, $other);
        $request = $this->underReview();

        $summary = collect($this->staff('GET', "/{$request->uuid}")->assertOk()->json('data.presentation.rows'))->firstWhere('label', 'مطابقة رقم الهوية في السجل');
        $this->assertStringContainsString('عضو نشط في أسرة أخرى', $summary['current']);
        $this->assertStringContainsString($other->family_code, $summary['current']);

        $this->staff('POST', "/{$request->uuid}/approve", $this->evidence())->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
        $this->assertSame($other->id, $person->activeMembership()->sole()->family_id);
    }

    public function test_an_id_already_in_the_own_family_cannot_be_approved(): void
    {
        $this->person(self::NID, $this->context->family);
        $request = $this->underReview();
        $count = $this->membersOf($this->context->family);

        $this->staff('POST', "/{$request->uuid}/approve", $this->evidence())->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertSame($count, $this->membersOf($this->context->family));
    }

    public function test_ambiguous_legacy_duplicates_are_never_resolved_automatically(): void
    {
        $a = $this->person('401-234-567');
        $b = $this->person('٤٠١٢٣٤٥٦٧');
        $request = $this->underReview();

        $summary = collect($this->staff('GET', "/{$request->uuid}")->json('data.presentation.rows'))->firstWhere('label', 'مطابقة رقم الهوية في السجل');
        $this->assertStringContainsString('أكثر من سجل مطابق', $summary['current']);
        $this->staff('POST', "/{$request->uuid}/approve", $this->evidence())->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');

        $this->assertSame(['401-234-567', '٤٠١٢٣٤٥٦٧'], [$a->fresh()->national_id, $b->fresh()->national_id]);
        $this->assertSame(0, FamilyMembership::whereIn('person_id', [$a->id, $b->id])->count());
    }

    // ================================================================ stale base and concurrency outcomes

    public function test_a_registry_change_after_submission_blocks_approval(): void
    {
        $request = $this->underReview();
        // Staff register the same person elsewhere in the meantime.
        app(AddFamilyMemberAction::class)->handle(Family::factory()->create(), [
            'full_name' => 'تسجيل موازٍ', 'national_id' => self::NID, 'gender' => 'FEMALE', 'relationship_type_id' => RelationshipType::where('code', 'DAUGHTER')->value('id'),
        ], $this->reviewer->id);

        $this->staff('POST', "/{$request->uuid}/approve", $this->evidence())->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_a_registry_change_after_approval_blocks_apply_and_creates_nothing(): void
    {
        $request = $this->approved();
        $this->person(self::NID);

        $this->staff('POST', "/{$request->uuid}/apply")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
        $this->assertSame(S::APPROVED, $request->fresh()->status);
        $this->assertSame(ChangeRequestApplyFailure::BASE_CHANGED->value, WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->sole()->reason_code);
    }

    public function test_two_families_cannot_both_add_the_same_new_person(): void
    {
        $otherContext = $this->contextOf($this->activatedHead('223456789')['user']);
        $mine = $this->approved();
        $theirs = $this->submitted([], $otherContext);
        $this->staff('POST', "/{$theirs->uuid}/start-review")->assertOk();
        $this->staff('POST', "/{$theirs->uuid}/approve", $this->evidence())->assertOk();

        $this->staff('POST', "/{$mine->uuid}/apply")->assertOk();
        $this->staff('POST', "/{$theirs->uuid}/apply")->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');

        $person = Person::where('national_id', self::NID)->sole();
        $this->assertSame($this->context->family->id, $person->activeMembership()->sole()->family_id);
    }

    public function test_an_apply_failure_rolls_everything_back_and_a_retry_succeeds(): void
    {
        $this->app->bind(AddFamilyMemberAction::class, fn () => new class extends AddFamilyMemberAction
        {
            public function handle(Family $family, array $data, ?int $actingUserId): FamilyMembership
            {
                parent::handle($family, $data, $actingUserId);

                throw new RuntimeException('SQLSTATE secret '.$data['national_id']);
            }
        });
        $request = $this->approved();

        $response = $this->staff('POST', "/{$request->uuid}/apply")->assertStatus(500)->assertJsonPath('code', 'CHANGE_REQUEST_APPLY_FAILED');
        $this->assertStringNotContainsString(self::NID, $response->getContent());
        $this->assertSame(0, Person::where('national_id', self::NID)->count());
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_MEMBER_ADDED)->count());
        $this->assertSame(1, $request->fresh()->apply_failure_count);

        $this->app->offsetUnset(AddFamilyMemberAction::class);
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');
        $this->assertSame(1, Person::where('national_id', self::NID)->count());
    }

    // ================================================================ reviewer attestations and authorization

    public function test_approval_requires_both_attestations_and_a_matching_typed_id(): void
    {
        $request = $this->underReview();
        $uri = "/{$request->uuid}/approve";

        $this->staff('GET', "/{$request->uuid}")->assertJsonPath('data.approval_attestations', ['IDENTITY_VERIFIED', 'RELATIONSHIP_VERIFIED']);
        $this->staff('POST', $uri)->assertUnprocessable()->assertJsonValidationErrors('attestations');
        $this->staff('POST', $uri, ['attestations' => ['IDENTITY_VERIFIED'], 'verified_national_id' => self::NID])->assertUnprocessable()->assertJsonValidationErrors('attestations');
        $this->staff('POST', $uri, ['attestations' => ['IDENTITY_VERIFIED', 'UNKNOWN_CODE'], 'verified_national_id' => self::NID])->assertUnprocessable();
        $this->staff('POST', $uri, ['attestations' => ['IDENTITY_VERIFIED', 'RELATIONSHIP_VERIFIED']])->assertUnprocessable()->assertJsonValidationErrors('verified_national_id');
        $wrong = $this->staff('POST', $uri, $this->evidence('409999999'))->assertUnprocessable()->assertJsonValidationErrors('verified_national_id');
        $this->assertStringNotContainsString(self::NID, $wrong->getContent());
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);

        // The typed value from the document may carry Arabic digits or separators.
        $this->staff('POST', $uri, $this->evidence('٤٠١ ٢٣٤ ٥٦٧'))->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $event = WorkflowEvent::where('event_type', WorkflowEventType::APPROVED)->sole();
        $this->assertSame(['identity' => 'IDENTITY_VERIFIED', 'relationship' => 'RELATIONSHIP_VERIFIED'], $event->metadata);
        $this->assertStringNotContainsString(self::NID, json_encode($event->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_other_types_take_no_evidence(): void
    {
        $residenceId = $this->family('POST', '', ['type' => 'RESIDENCE_UPDATE', 'client_reference' => (string) Str::uuid(), 'data' => ['x' => 1]])->status();
        $this->assertSame(422, $residenceId, 'a family without a residence cannot propose one (sanity)');

        // The attestation hint is empty for a type without attestations, and evidence is refused.
        $fake = ChangeRequest::factory()->create(['type' => ChangeRequestType::RESIDENCE_UPDATE, 'family_id' => $this->context->family->id, 'submitted_data' => ['city' => 'غزة']]);
        $this->staff('GET', "/{$fake->uuid}")->assertJsonPath('data.approval_attestations', []);
        $this->staff('POST', "/{$fake->uuid}/start-review")->assertOk();
        $this->staff('POST', "/{$fake->uuid}/approve", $this->evidence())->assertUnprocessable();
        $this->assertSame(S::UNDER_REVIEW, $fake->fresh()->status);
    }

    public function test_only_authorized_staff_review_approve_and_apply(): void
    {
        $request = $this->underReview();
        foreach (['DATA_ENTRY', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->staff('POST', "/{$request->uuid}/approve", $this->evidence(), $this->staffUser($role))->assertForbidden();
        }
        $this->family('GET', '')->assertOk();
        $this->actingAs($this->context->user->fresh())->postJson(self::STAFF_URI."/{$request->uuid}/approve", $this->evidence())->assertForbidden();
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_another_family_cannot_see_or_act_on_the_request(): void
    {
        $request = $this->submitted();
        $stranger = $this->contextOf($this->activatedHead('223456789')['user']);

        $this->family('GET', "/{$request->uuid}", as: $stranger)->assertNotFound();
        $this->family('POST', "/{$request->uuid}/cancel", as: $stranger)->assertNotFound();
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }

    // ================================================================ privacy

    public function test_the_national_id_is_masked_and_shown_only_where_permitted(): void
    {
        $request = $this->underReview();

        $family = $this->family('GET', "/{$request->uuid}")->assertOk();
        $this->assertContains(['label' => 'رقم الهوية', 'current' => null, 'proposed' => '*****4567'], $family->json('data.presentation.rows'));

        // REVIEWER has no person.national-id.view-masked: no ID row at all.
        $reviewer = $this->staff('GET', "/{$request->uuid}")->assertOk();
        $this->assertNotContains('رقم الهوية', array_column($reviewer->json('data.presentation.rows'), 'label'));

        $admin = $this->staff('GET', "/{$request->uuid}", as: $this->staffUser('ADMINISTRATOR'))->assertOk();
        $this->assertContains(['label' => 'رقم الهوية', 'current' => null, 'proposed' => '*****4567'], $admin->json('data.presentation.rows'));

        foreach ([$family, $reviewer, $admin, $this->staff('GET', '')] as $response) {
            $this->assertStringNotContainsString(self::NID, $response->getContent());
            $this->assertStringNotContainsString('0591112233', $response->getContent());
            $this->assertStringNotContainsString('submitted_data', $response->getContent());
        }
    }

    public function test_nothing_is_logged(): void
    {
        Log::spy();
        $request = $this->approved();
        $this->staff('POST', "/{$request->uuid}/apply")->assertOk();

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_the_family_reads_relationship_options_from_the_registry(): void
    {
        RelationshipType::where('code', 'OTHER')->update(['is_active' => false]);

        $response = $this->family('GET', '/relationship-types')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['SPOUSE', 'SON', 'DAUGHTER', 'FATHER', 'MOTHER'], array_column($response->json('data'), 'code'));
        $this->assertSame(['code', 'name'], array_keys($response->json('data.0')));

        $this->app['auth']->forgetGuards();
        $this->json('GET', self::FAMILY_URI.'/relationship-types')->assertUnauthorized();
        $this->actingAs($this->reviewer)->json('GET', self::FAMILY_URI.'/relationship-types')->assertForbidden();
    }

    public function test_the_handler_is_not_a_production_type(): void
    {
        $this->assertArrayNotHasKey('ADD_FAMILY_MEMBER', ChangeRequestTypes::PRODUCTION);
        $this->assertFalse(ChangeRequestTypes::production()->has(ChangeRequestType::ADD_FAMILY_MEMBER));
    }
}
