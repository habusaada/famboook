<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Actions\RegisterFamilyAction;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\RegistrationSource;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\Branch;
use App\Models\ChangeRequest;
use App\Models\Clan;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\Person;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use App\Support\ChangeRequests\Handlers\RegisterFamilyHandler;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Support\ChangeRequests\CoordinatorRequestFixtures;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.5: REGISTER_FAMILY (FAMILY_ADD) with the REAL handler — a
 * Coordinator proposes a NEW Family placed in their scope through
 * POST /api/v1/family/coordinator/requests; Staff approve and APPLY it, and
 * only APPLY creates the Family (RegisterFamilyAction). Placement, scope,
 * identity, idempotency, rollback, privacy and channel isolation.
 * Synthetic data and National IDs only.
 */
class RegisterFamilyRequestTest extends TestCase
{
    use CoordinatorFixtures, CoordinatorRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/coordinator';

    /** A synthetic National ID for the proposed head. */
    private const HEAD_NID = '412345678';

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoordinatorRequests();
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::ADD_FAMILY_MEMBER->value => new AddFamilyMemberHandler,
            ChangeRequestType::REGISTER_FAMILY->value => new RegisterFamilyHandler,
        ]));
        config(['change_requests.coordinator_limits' => ['submit_user_minute' => 100, 'submit_user_hour' => 100, 'action_user_minute' => 100, 'read_user_minute' => 100]]);
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
    }

    /** @return array<string, mixed> a valid FAMILY_ADD proposal */
    private function proposal(array $head = [], array $residence = []): array
    {
        return [
            'household_head' => [
                'full_name' => 'رب أسرة مقترح', 'national_id' => self::HEAD_NID, 'gender' => 'MALE',
                'marital_status' => 'MARRIED', 'birth_date' => '1980-01-01', 'mobile' => '0591234500', ...$head,
            ],
            'residence' => ['original_residence_text' => 'بلدة تجريبية', ...$residence],
        ];
    }

    private function propose(array $head = [], ?Clan $clan = null, ?Branch $branch = null, ?User $as = null, ?string $reference = null, array $residence = [], ?array $placement = null): TestResponse
    {
        $this->actingAs(($as ?? $this->coordinatorUser)->fresh());

        return $this->postJson(self::URI.'/requests', [
            'type' => 'REGISTER_FAMILY',
            'client_reference' => $reference ?? (string) Str::uuid(),
            'placement' => $placement ?? ['clan_code' => ($clan ?? $this->scopeClan)->code, 'branch_code' => $branch?->code],
            'data' => $this->proposal($head, $residence),
        ]);
    }

    private function proposed(array $head = [], ?Clan $clan = null, ?Branch $branch = null): ChangeRequest
    {
        return ChangeRequest::where('uuid', $this->propose($head, $clan, $branch)->assertCreated()->json('data.id'))->sole();
    }

    private function evidence(string $typed = self::HEAD_NID): ChangeRequestApprovalEvidence
    {
        return new ChangeRequestApprovalEvidence([ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED], $typed);
    }

    private function approved(ChangeRequest $request): ChangeRequest
    {
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, $this->evidence());

        return $request->fresh();
    }

    private function refused(Closure $call, string $code): void
    {
        try {
            $call();
        } catch (ChangeRequestException $e) {
            $this->assertSame($code, $e->reason);

            return;
        }
        $this->fail("Expected {$code}.");
    }

    private function placementRefused(TestResponse $response): array
    {
        $response->assertUnprocessable()->assertJsonValidationErrors('placement');

        return $response->json();
    }

    // ------------------------------------------------------------- submission

    public function test_a_coordinator_proposes_a_new_family_and_nothing_is_created(): void
    {
        $families = Family::count();
        $response = $this->propose()->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertExactJson(['data' => [
            'id' => $response->json('data.id'), 'request_code' => $response->json('data.request_code'), 'status' => 'SUBMITTED', 'replayed' => false,
        ]]);

        $request = ChangeRequest::where('uuid', $response->json('data.id'))->sole();
        $this->assertNull($request->family_id);
        $this->assertSame('COORDINATOR', $request->submission_channel->value);
        $this->assertSame(ChangeRequestType::REGISTER_FAMILY, $request->type);
        $this->assertSame($this->scopeClan->id, $request->proposed_clan_id);
        $this->assertNull($request->proposed_branch_id);
        // jsonb does not keep key order: compare as maps.
        $this->assertEquals([
            'household_head' => ['full_name' => 'رب أسرة مقترح', 'national_id' => self::HEAD_NID, 'gender' => 'MALE',
                'marital_status' => 'MARRIED', 'birth_date' => '1980-01-01', 'mobile' => '0591234500'],
            'residence' => ['original_residence_text' => 'بلدة تجريبية'],
        ], $request->submitted_data);
        $this->assertSame($families, Family::count());
        $this->assertFalse(Person::where('national_id', self::HEAD_NID)->exists());
        $this->assertSame(0, FamilyActivity::where('subject_type', $request->getMorphClass())->count());
        $this->assertSame([['SUBMITTED', 'COORDINATOR']], $request->workflowEvents()->get()
            ->map(fn ($e) => [$e->event_type->value, $e->actor_side->value])->all());
    }

    public function test_only_the_original_residence_and_known_fields_are_stored(): void
    {
        $request = ChangeRequest::where('uuid', $this->propose(
            ['national_id' => ' 412-345-678 ', 'mobile' => null, 'current_address' => 'X'],
            residence: ['city' => 'مدينة', 'displacement_status' => 'DISPLACED'],
        )->assertCreated()->json('data.id'))->sole();

        $this->assertSame(self::HEAD_NID, $request->submitted_data['household_head']['national_id'], 'stored canonically');
        $this->assertSame(['original_residence_text'], array_keys($request->submitted_data['residence']));
        $this->assertArrayNotHasKey('current_address', $request->submitted_data['household_head']);
    }

    public function test_the_proposal_is_validated(): void
    {
        $this->propose(['national_id' => null])->assertUnprocessable()->assertJsonValidationErrors('household_head.national_id');
        $this->propose(['national_id' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('household_head.national_id');
        $this->propose(['national_id' => '999999999'])->assertUnprocessable()->assertJsonValidationErrors('household_head.national_id');
        $this->propose(['full_name' => '   '])->assertUnprocessable()->assertJsonValidationErrors('household_head.full_name');
        $this->propose(['gender' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('household_head.gender');
        $this->propose(['birth_date' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('household_head.birth_date');
        $this->propose(['mobile' => '123'])->assertUnprocessable()->assertJsonValidationErrors('household_head.mobile');
        $this->propose(residence: ['original_residence_text' => ''])->assertUnprocessable()->assertJsonValidationErrors('residence.original_residence_text');
        $this->actingAs($this->coordinatorUser->fresh())->postJson(self::URI.'/requests', [
            'type' => 'REGISTER_FAMILY', 'client_reference' => (string) Str::uuid(), 'data' => $this->proposal(),
        ])->assertUnprocessable()->assertJsonValidationErrors('placement');
        $this->assertSame(0, ChangeRequest::count());
    }

    // -------------------------------------------------------------- placement

    public function test_clan_scope_covers_its_clan_with_or_without_a_branch(): void
    {
        $branch = $this->branch($this->scopeClan, $this->group($this->scopeClan));
        $this->propose()->assertCreated();
        $this->propose(['national_id' => '423456789'], branch: $branch)->assertCreated();
        $this->assertSame([null, $branch->id], ChangeRequest::orderBy('id')->pluck('proposed_branch_id')->all());
    }

    public function test_branch_group_and_branch_scopes_and_branchless_restriction(): void
    {
        $group = $this->group($this->scopeClan);
        $inGroup = $this->branch($this->scopeClan, $group);
        $first = $this->branch($this->scopeClan);
        $second = $this->branch($this->scopeClan);
        $third = $this->branch($this->scopeClan);

        $groupCoordinator = $this->coordinator('511111110');
        $this->assign($groupCoordinator, $group);
        $this->propose(branch: $inGroup, as: $groupCoordinator)->assertCreated();
        $this->placementRefused($this->propose(['national_id' => '423456789'], branch: $first, as: $groupCoordinator));
        $this->placementRefused($this->propose(['national_id' => '423456789'], as: $groupCoordinator));

        // Multiple BRANCH assignments: each covered; branchless and others are not.
        $branchCoordinator = $this->coordinator('611111110');
        $this->assign($branchCoordinator, $first);
        $this->assign($branchCoordinator, $second);
        $this->propose(['national_id' => '423456789'], branch: $first, as: $branchCoordinator)->assertCreated();
        $this->propose(['national_id' => '433456789'], branch: $second, as: $branchCoordinator)->assertCreated();
        $this->placementRefused($this->propose(['national_id' => '443456789'], branch: $third, as: $branchCoordinator));
        $this->placementRefused($this->propose(['national_id' => '443456789'], as: $branchCoordinator));
        $this->assertSame(3, ChangeRequest::count());
    }

    public function test_every_unusable_placement_gets_the_same_answer(): void
    {
        $otherClan = $this->clan('OTHER_CLAN');
        $foreignBranch = $this->branch($otherClan);
        $inactiveBranch = $this->branch($this->scopeClan, active: false);
        $inactiveClan = $this->clan('DORMANT_CLAN', active: false);
        $this->assign($this->coordinatorUser, $inactiveClan);
        $hiddenClan = $this->clan('HIDDEN_CLAN');

        $answers = [
            'unknown clan' => $this->placementRefused($this->propose(placement: ['clan_code' => 'NO_SUCH_CLAN'])),
            'unknown branch' => $this->placementRefused($this->propose(placement: ['clan_code' => $this->scopeClan->code, 'branch_code' => 'NO_SUCH_BRANCH'])),
            'branch of another clan' => $this->placementRefused($this->propose(placement: ['clan_code' => $this->scopeClan->code, 'branch_code' => $foreignBranch->code])),
            'inactive branch' => $this->placementRefused($this->propose(branch: $inactiveBranch)),
            'inactive clan' => $this->placementRefused($this->propose(clan: $inactiveClan)),
            'clan outside the scope' => $this->placementRefused($this->propose(clan: $hiddenClan)),
            'branch outside the scope' => $this->placementRefused($this->propose(clan: $otherClan, branch: $foreignBranch)),
        ];
        foreach ($answers as $case => $answer) {
            $this->assertSame($answers['unknown clan'], $answer, $case);
        }
        $this->assertSame(0, ChangeRequest::count());
    }

    // ------------------------------------------------------------- identity

    public function test_an_existing_national_id_gets_the_same_answer_and_is_refused_at_approval(): void
    {
        Person::factory()->create(['national_id' => self::HEAD_NID]);
        $held = $this->propose()->assertCreated();
        $free = $this->propose(['national_id' => '423456789'])->assertCreated();
        $this->assertSame(array_keys($held->json('data')), array_keys($free->json('data')));

        // Staff see the match; the Coordinator never does.
        $request = ChangeRequest::where('uuid', $held->json('data.id'))->sole();
        $coordinatorRows = $this->actingAs($this->coordinatorUser->fresh())->getJson(self::URI.'/requests/'.$request->uuid)->assertOk()->json('data.presentation.rows');
        $this->assertNotContains('مطابقة رقم الهوية في السجل', array_column($coordinatorRows, 'label'));
        $staffRows = $this->actingAs($this->reviewer->fresh())->getJson('/api/v1/change-requests/'.$request->uuid)->assertOk()->json('data.presentation.rows');
        $this->assertContains('مطابقة رقم الهوية في السجل', array_column($staffRows, 'label'));

        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        $this->refused(fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, $this->evidence()), ChangeRequestException::PRECONDITION_FAILED);
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_a_soft_deleted_holder_also_blocks_approval(): void
    {
        Person::factory()->create(['national_id' => self::HEAD_NID])->delete();
        $request = $this->proposed();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        $this->refused(fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, $this->evidence()), ChangeRequestException::PRECONDITION_FAILED);
    }

    public function test_approval_needs_both_attestations_and_the_typed_national_id(): void
    {
        $request = $this->proposed();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);

        foreach ([
            ChangeRequestApprovalEvidence::none(),
            new ChangeRequestApprovalEvidence([ChangeRequestAttestation::IDENTITY_VERIFIED], self::HEAD_NID),
            $this->evidence('423456789'),
        ] as $evidence) {
            try {
                app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, $evidence);
                $this->fail('Approved without the required evidence.');
            } catch (ValidationException) {
                $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
            }
        }
    }

    // ----------------------------------------------------------------- APPLY

    public function test_apply_registers_the_family_in_the_exact_placement(): void
    {
        $branch = $this->branch($this->scopeClan);
        $request = $this->approved($this->proposed(clan: $this->scopeClan, branch: $branch));
        $this->assertFalse(Person::where('national_id', self::HEAD_NID)->exists(), 'APPROVED ≠ APPLIED');

        app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $request->refresh();
        $family = Family::with(['householdHeadMembership.person', 'currentResidence'])->findOrFail($request->created_family_id);

        $this->assertSame(S::APPLIED, $request->status);
        $this->assertNull($request->family_id);
        $this->assertSame([$this->scopeClan->id, $branch->id], [$family->clan_id, $family->branch_id]);
        $this->assertSame(RegistrationSource::COORDINATOR_REQUEST, $family->registration_source);
        $this->assertSame(now()->toDateString(), $family->registration_date->toDateString());
        $head = $family->householdHeadMembership->person;
        $this->assertSame([self::HEAD_NID, 'رب أسرة مقترح', '0591234500'], [$head->national_id, $head->full_name, $head->mobile]);
        $this->assertSame('بلدة تجريبية', $family->currentResidence->original_residence_text);
        $this->assertNull($family->currentResidence->city);
        $this->assertSame(RegistrationSource::COORDINATOR_REQUEST->value, $family->currentResidence->getRawOriginal('source'));
        $this->assertSame([FamilyActivityType::FAMILY_CREATED, FamilyActivityType::CHANGE_REQUEST_APPLIED],
            FamilyActivity::where('family_id', $family->id)
                ->whereIn('event_type', [FamilyActivityType::FAMILY_CREATED, FamilyActivityType::CHANGE_REQUEST_APPLIED])
                ->orderBy('id')->pluck('event_type')->all());

        // The Coordinator now sees the created Family's code.
        $this->actingAs($this->coordinatorUser->fresh())->getJson(self::URI.'/requests/'.$request->uuid)->assertOk()
            ->assertJsonPath('data.status', 'APPLIED')
            ->assertJsonPath('data.created_family_code', $family->family_code)
            ->assertJsonPath('data.family_code', $family->family_code)
            ->assertJsonPath('data.summary', 'رب أسرة مقترح');
        $this->actingAs($this->reviewer->fresh())->getJson('/api/v1/change-requests/'.$request->uuid)->assertOk()
            ->assertJsonPath('data.created_family_code', $family->family_code)->assertJsonPath('data.family', null);
    }

    public function test_apply_twice_creates_one_family(): void
    {
        $request = $this->approved($this->proposed());
        app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $families = Family::count();

        $this->assertTrue(app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer)->replayed);
        $this->assertSame($families, Family::count());
        $this->assertSame(1, Person::where('national_id', self::HEAD_NID)->count());
        $this->assertSame(1, $request->workflowEvents()->where('event_type', WorkflowEventType::APPLIED)->count());
    }

    public function test_a_national_id_taken_after_approval_refuses_apply_and_writes_nothing(): void
    {
        $request = $this->approved($this->proposed());
        Person::factory()->create(['national_id' => self::HEAD_NID]);
        $families = Family::count();

        $this->refused(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::BASE_CHANGED);
        $request->refresh();
        $this->assertSame([S::APPROVED, 1, null], [$request->status, $request->apply_failure_count, $request->created_family_id]);
        $this->assertSame($families, Family::count());
    }

    public function test_a_family_created_outside_the_proposed_placement_is_rolled_back(): void
    {
        Log::spy();
        $elsewhere = $this->branch($this->scopeClan);
        $request = $this->approved($this->proposed());
        // A defective registration that ignores the reviewed placement.
        $this->app->bind(RegisterFamilyAction::class, fn () => new class($elsewhere->code) extends RegisterFamilyAction
        {
            public function __construct(private readonly string $branchCode)
            {
                parent::__construct();
            }

            public function handle(array $data, ?int $actingUserId, ?RegistrationSource $residenceSource = null): Family
            {
                return parent::handle([...$data, 'branch_code' => $this->branchCode], $actingUserId, $residenceSource);
            }
        });
        $families = Family::count();
        $persons = Person::count();

        $this->refused(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::APPLY_FAILED);
        $request->refresh();
        $this->assertSame([S::APPROVED, null, 1], [$request->status, $request->created_family_id, $request->apply_failure_count]);
        $this->assertSame($families, Family::count(), 'the Family is rolled back');
        $this->assertSame($persons, Person::count(), 'the head is rolled back');
    }

    // ------------------------------------------------------ replay, conflict

    public function test_submission_is_idempotent_and_conflicts_are_per_coordinator(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->propose(reference: $reference)->assertCreated();
        $this->propose(reference: $reference)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.id', $first->json('data.id'));
        $this->propose(['full_name' => 'اسم آخر'], reference: $reference)->assertStatus(409)->assertJsonPath('code', ChangeRequestException::IDEMPOTENCY_CONFLICT);
        $branch = $this->branch($this->scopeClan);
        $this->propose(branch: $branch, reference: $reference)->assertStatus(409)->assertJsonPath('code', ChangeRequestException::IDEMPOTENCY_CONFLICT);
        $this->propose()->assertStatus(409)->assertJsonPath('code', ChangeRequestException::ALREADY_OPEN);

        // Another Coordinator proposing the same head is never told about the first.
        $colleague = $this->coordinator('921111110');
        $this->assign($colleague, $this->scopeClan);
        $this->propose(as: $colleague)->assertCreated();
        $this->assertSame(2, ChangeRequest::count());
    }

    public function test_the_second_of_two_competing_proposals_cannot_be_applied(): void
    {
        $colleague = $this->coordinator('921111110');
        $this->assign($colleague, $this->scopeClan);
        $first = $this->approved($this->proposed());
        $second = ChangeRequest::where('uuid', $this->propose(as: $colleague)->assertCreated()->json('data.id'))->sole();
        $second = $this->approved($second);

        app(ApplyChangeRequestAction::class)->handle($first, $this->reviewer);
        $this->refused(fn () => app(ApplyChangeRequestAction::class)->handle($second, $this->reviewer), ChangeRequestException::BASE_CHANGED);
        $this->assertSame(1, Person::where('national_id', self::HEAD_NID)->count());
        $this->assertNull($second->fresh()->created_family_id);
    }

    // ---------------------------------------------- gate, scope, channels

    public function test_the_switch_off_pilot_and_general(): void
    {
        config(['change_requests.coordinator_submission_mode' => 'OFF']);
        $this->propose()->assertStatus(503)->assertJsonPath('code', ChangeRequestException::SUBMISSION_DISABLED);
        config(['change_requests.coordinator_submission_mode' => 'PILOT', 'change_requests.coordinator_pilot_user_ids' => '999999']);
        $this->propose()->assertStatus(503);
        config(['change_requests.coordinator_pilot_user_ids' => (string) $this->coordinatorUser->id]);
        $this->propose()->assertCreated();
        config(['change_requests.coordinator_submission_mode' => 'GENERAL']);
        $this->propose(['national_id' => '423456789'])->assertCreated();
    }

    public function test_only_coordinators_with_the_permission_may_propose_and_staff_alone_decide(): void
    {
        $this->actingAs($this->household->user->fresh())->postJson(self::URI.'/requests', [])->assertForbidden();
        $this->actingAs($this->reviewer->fresh())->postJson(self::URI.'/requests', [])->assertForbidden();
        Role::findByName('COORDINATOR', 'web')->revokePermissionTo('coordinator-request.submit');
        $this->propose()->assertForbidden();
        Role::findByName('COORDINATOR', 'web')->givePermissionTo('coordinator-request.submit');

        $request = $this->proposed();
        $coordinator = $this->coordinatorUser->fresh();
        $this->refused(fn () => app(StartChangeRequestReviewAction::class)->handle($request, $coordinator), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->actingAs($coordinator)->postJson('/api/v1/change-requests/'.$request->uuid.'/start-review')->assertForbidden();
        $this->approved($request);
        $this->refused(fn () => app(ApplyChangeRequestAction::class)->handle($request, $coordinator), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    public function test_a_revoked_scope_hides_the_proposal_from_the_coordinator_only(): void
    {
        $request = $this->proposed();
        CoordinatorScopeAssignment::where('user_id', $this->coordinatorUser->id)
            ->update(['revoked_at' => now(), 'revoked_by' => $this->reviewer->id, 'revoke_reason' => 'ADMINISTRATIVE']);
        $this->assign($this->coordinatorUser, $this->clan('OTHER_CLAN'));

        $this->actingAs($this->coordinatorUser->fresh());
        $this->getJson(self::URI.'/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::URI.'/requests/'.$request->uuid)->assertNotFound();
        $this->postJson(self::URI.'/requests/'.$request->uuid.'/cancel')->assertNotFound();
        $this->placementRefused($this->propose(['national_id' => '423456789']));

        $this->actingAs($this->reviewer->fresh())->getJson('/api/v1/change-requests/'.$request->uuid)->assertOk()
            ->assertJsonPath('data.family', null)->assertJsonPath('data.placement.clan.code', 'SCOPE_CLAN');
    }

    public function test_the_two_submission_paths_never_cross(): void
    {
        // REGISTER_FAMILY never through the family route or the PERSON_ADD route.
        $this->actingAs($this->household->user->fresh())->postJson('/api/v1/family/change-requests', [
            'type' => 'REGISTER_FAMILY', 'client_reference' => (string) Str::uuid(), 'data' => $this->proposal(),
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->actingAs($this->coordinatorUser->fresh())->postJson(self::URI.'/families/'.$this->household->family->family_code.'/requests', [
            'type' => 'REGISTER_FAMILY', 'client_reference' => (string) Str::uuid(), 'data' => $this->proposal(),
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->refused(fn () => app(SubmitChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $this->household->family,
            new ChangeRequestSubmission(ChangeRequestType::REGISTER_FAMILY, $this->proposal(), null, (string) Str::uuid())), ChangeRequestException::TYPE_UNAVAILABLE);
        // And PERSON_ADD never through the proposal route.
        $this->actingAs($this->coordinatorUser->fresh())->postJson(self::URI.'/requests', [
            'type' => 'ADD_FAMILY_MEMBER', 'client_reference' => (string) Str::uuid(), 'placement' => ['clan_code' => 'SCOPE_CLAN'], 'data' => $this->member(),
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->refused(fn () => app(SubmitChangeRequestAction::class)->handleCoordinatorProposal($this->coordinatorUser->fresh(), $this->scopeClan, null,
            new ChangeRequestSubmission(ChangeRequestType::ADD_FAMILY_MEMBER, $this->member(), null, (string) Str::uuid())), ChangeRequestException::TYPE_UNAVAILABLE);
        $this->assertSame(0, ChangeRequest::count());
    }

    // ---------------------------------------------------------------- privacy

    public function test_the_coordinator_views_are_safe(): void
    {
        $request = $this->proposed();
        $this->actingAs($this->coordinatorUser->fresh());
        $list = $this->getJson(self::URI.'/requests')->assertOk();
        $row = $list->json('data.0');
        $this->assertSame(['REGISTER_FAMILY', 'رب أسرة مقترح', null, null], [$row['type'], $row['summary'], $row['family_code'], $row['created_family_code']]);
        $this->assertSame('SCOPE_CLAN', $row['placement']['clan']['code']);
        $this->assertSame(['cancel'], $row['available_actions']);

        $detail = $this->getJson(self::URI.'/requests/'.$request->uuid)->assertOk();
        $json = $detail->getContent();
        foreach ([self::HEAD_NID, '0591234500', '"family_id"', '"proposed_clan_id"', '"submitted_data"', '"client_reference"', '"base_fingerprint"'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
        $this->assertContains('*****5678', array_column($detail->json('data.presentation.rows'), 'proposed'));
        $this->assertContains('بلدة تجريبية', array_column($detail->json('data.presentation.rows'), 'proposed'));
        // The placement by public code and name only — never an internal id.
        $this->assertSame(['clan' => ['code' => 'SCOPE_CLAN', 'name' => $this->scopeClan->name], 'branch' => null], $detail->json('data.placement'));
    }

    public function test_staff_registration_is_unchanged(): void
    {
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('SUPER_ADMIN', 'web')));
        $this->actingAs($admin)->postJson('/api/v1/families', [
            'registration_date' => '2026-09-01', 'registration_source' => 'MANUAL_ENTRY', 'clan_code' => $this->scopeClan->code,
            'household_head' => ['full_name' => 'رب أسرة', 'national_id' => '433456789', 'gender' => 'MALE'],
            'residence' => ['city' => 'مدينة', 'original_residence_text' => 'بلدة'],
        ])->assertCreated();
        $family = Family::with('currentResidence')->latest('id')->first();
        $this->assertSame(RegistrationSource::MANUAL_ENTRY, $family->registration_source);
        $this->assertNull($family->currentResidence->source, 'Staff registration still states no residence source');
    }
}
