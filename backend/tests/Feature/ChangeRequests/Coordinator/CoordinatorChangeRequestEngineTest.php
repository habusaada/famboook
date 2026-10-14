<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\CancelChangeRequestAction;
use App\Actions\ChangeRequests\RejectChangeRequestAction;
use App\Actions\ChangeRequests\ResubmitChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\FamilyStatus;
use App\Enums\SubmissionChannel;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\CoordinatorScopeAssignment;
use App\Models\FamilyActivity;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\ChangeRequestTransitions;
use App\Support\ChangeRequests\FamilyChangeRequestActions;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use App\Support\FamilyAuth\CoordinatorScopes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\Support\ChangeRequests\CoordinatorRequestFixtures;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.3b: the Change Request engine through the COORDINATOR channel —
 * actor, permissions, switch, scope, channel ownership, audit and replays —
 * and that the family channel and the Staff workflow are unchanged. The
 * engine is driven through its Domain Actions (no coordinator route exists
 * yet). Synthetic data only.
 */
class CoordinatorChangeRequestEngineTest extends TestCase
{
    use CoordinatorFixtures, CoordinatorRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoordinatorRequests();
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
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

    private function returned(ChangeRequest $request): ChangeRequest
    {
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ReturnChangeRequestForClarificationAction::class)->handle($request, $this->reviewer, 'أرفق ما يثبت العلاقة.');

        return $request->fresh();
    }

    private function familySubmits(array $data = [], ?string $reference = null): ChangeRequest
    {
        return app(SubmitChangeRequestAction::class)->handle($this->household, new ChangeRequestSubmission(
            ChangeRequestType::ADD_FAMILY_MEMBER, $this->member($data), null, $reference ?? (string) Str::uuid(),
        ))->request;
    }

    /** @return list<array{string, string, ?int}> event, actor side, actor */
    private function timeline(ChangeRequest $request): array
    {
        return $request->workflowEvents()->get()
            ->map(fn (WorkflowEvent $e) => [$e->event_type->value, $e->actor_side->value, $e->actor_user_id])->all();
    }

    // ------------------------------------------------------------- submission

    public function test_a_coordinator_submits_person_add_for_a_family_in_scope(): void
    {
        $outcome = $this->coordinatorSubmits();
        $request = $outcome->request->fresh();
        $coordinatorPerson = app(FamilyAccessResolver::class)->familyContext($this->coordinatorUser)->person;

        $this->assertFalse($outcome->replayed);
        $this->assertSame(S::SUBMITTED, $request->status);
        $this->assertSame(SubmissionChannel::COORDINATOR, $request->submission_channel);
        $this->assertSame(ChangeRequestType::ADD_FAMILY_MEMBER, $request->type);
        $this->assertSame($this->household->family->id, $request->family_id);
        $this->assertSame($this->coordinatorUser->id, $request->submitted_by);
        $this->assertSame($coordinatorPerson->id, $request->submitted_by_person_id);
        $this->assertNull($request->proposed_clan_id);
        $this->assertNull($request->created_family_id);
        $this->assertSame([['SUBMITTED', 'COORDINATOR', $this->coordinatorUser->id]], $this->timeline($request));
        $this->assertSame(1, FamilyActivity::where('family_id', $this->household->family->id)
            ->where('event_type', FamilyActivityType::CHANGE_REQUEST_SUBMITTED)->where('actor_user_id', $this->coordinatorUser->id)->count());
        // Nothing canonical changed: no new Person.
        $this->assertFalse(Person::where('national_id', self::MEMBER_NID)->exists());
    }

    public function test_the_whole_staff_workflow_applies_a_coordinator_person_add(): void
    {
        $request = $this->coordinatorRequest();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, new ChangeRequestApprovalEvidence(
            [ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED], self::MEMBER_NID,
        ));
        $this->assertFalse(Person::where('national_id', self::MEMBER_NID)->exists(), 'APPROVED ≠ APPLIED');
        app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);

        $request->refresh();
        $this->assertSame(S::APPLIED, $request->status);
        $this->assertNull($request->created_family_id);
        $person = Person::where('national_id', self::MEMBER_NID)->sole();
        $this->assertTrue($this->household->family->memberships()->where('person_id', $person->id)->exists());
        $this->assertSame([
            ['SUBMITTED', 'COORDINATOR', $this->coordinatorUser->id],
            ['REVIEW_STARTED', 'STAFF', $this->reviewer->id],
            ['APPROVED', 'STAFF', $this->reviewer->id],
            ['APPLIED', 'STAFF', $this->reviewer->id],
        ], $this->timeline($request));
    }

    public function test_only_coordinator_submittable_types_may_be_submitted_by_a_coordinator(): void
    {
        // OTHER (the test handler) is family-submittable but does not opt in.
        $this->refused(fn () => app(SubmitChangeRequestAction::class)->handleCoordinator($this->coordinatorUser, $this->household->family,
            new ChangeRequestSubmission(ChangeRequestType::OTHER, ['paper_form_no' => 'PF-1'], null, (string) Str::uuid())), ChangeRequestException::TYPE_UNAVAILABLE);
        // REGISTER_FAMILY is never submitted through this path.
        $this->refused(fn () => $this->coordinatorSubmits(type: ChangeRequestType::REGISTER_FAMILY), ChangeRequestException::TYPE_UNAVAILABLE);
        $this->assertSame(0, ChangeRequest::count());
    }

    // ------------------------------------------------------------- the switch

    public function test_the_coordinator_switch_off_pilot_and_general(): void
    {
        $cases = [
            ['OFF', '', false],
            ['', '', false],
            ['nonsense', (string) $this->coordinatorUser->id, false],
            ['PILOT', '', false],
            ['PILOT', '999999', false],
            ['PILOT', $this->coordinatorUser->id.',x', false],
            ['PILOT', ' 7 , '.$this->coordinatorUser->id.' ', true],
            [' general ', '', true],
        ];
        foreach ($cases as $i => [$mode, $ids, $allowed]) {
            config(['change_requests.coordinator_submission_mode' => $mode, 'change_requests.coordinator_pilot_user_ids' => $ids]);
            $submit = fn () => $this->coordinatorSubmits(data: ['national_id' => sprintf('40%07d', $i + 1)]);
            if ($allowed) {
                $this->assertSame(S::SUBMITTED, $submit()->request->status, "{$mode}/{$ids}");
            } else {
                $this->refused($submit, ChangeRequestException::SUBMISSION_DISABLED);
            }
        }
        $this->assertSame(2, ChangeRequest::count());
    }

    public function test_the_coordinator_and_family_switches_are_independent(): void
    {
        config(['change_requests.coordinator_submission_mode' => 'OFF', 'change_requests.family_submission_mode' => 'GENERAL']);
        $this->assertSame(S::SUBMITTED, $this->familySubmits()->status);
        $this->refused(fn () => $this->coordinatorSubmits(data: ['national_id' => '402345678']), ChangeRequestException::SUBMISSION_DISABLED);

        config(['change_requests.coordinator_submission_mode' => 'GENERAL', 'change_requests.family_submission_mode' => 'OFF']);
        $this->assertSame(S::SUBMITTED, $this->coordinatorSubmits(data: ['national_id' => '402345678'])->request->status);
        $this->refused(fn () => $this->familySubmits(['national_id' => '403456789']), ChangeRequestException::SUBMISSION_DISABLED);
    }

    public function test_the_switch_gates_new_submissions_only(): void
    {
        $request = $this->returned($this->coordinatorRequest());
        config(['change_requests.coordinator_submission_mode' => 'OFF']);

        app(ResubmitChangeRequestAction::class)->handleCoordinator($this->coordinatorUser, $request, 'أرفقت الوثيقة.');
        app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser, $request);
        $this->assertSame(S::CANCELLED, $request->fresh()->status);
    }

    // ------------------------------------------------------------ permissions

    public function test_coordinator_request_permissions_are_required_and_change_request_permissions_never_suffice(): void
    {
        $role = Role::findByName('COORDINATOR', 'web');
        $this->assertTrue($this->coordinatorUser->fresh()->can('change-request.submit'), 'held through FAMILY_USER');

        $role->revokePermissionTo('coordinator-request.submit');
        $this->refused(fn () => $this->coordinatorSubmits(), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $role->givePermissionTo('coordinator-request.submit');
        $request = $this->returned($this->coordinatorRequest());

        $role->revokePermissionTo('coordinator-request.resubmit');
        $this->refused(fn () => app(ResubmitChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request, 'رد'), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $role->revokePermissionTo('coordinator-request.cancel');
        $this->refused(fn () => app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $request->fresh()->status);
    }

    public function test_only_a_coordinator_right_now_may_use_the_coordinator_channel(): void
    {
        $familyOnly = $this->householdIn($this->scopeClan, '323456789');
        $staff = $this->reviewer;
        $unscoped = $this->coordinator('511111110');
        $inactive = $this->coordinator('611111110');
        $this->assign($inactive, $this->scopeClan);
        $inactive->forceFill(['is_active' => false])->save();
        $revoked = $this->coordinator('711111110');
        $this->assign($revoked, $this->scopeClan, revoked: true);

        foreach (['household head' => $familyOnly->user, 'Staff' => $staff, 'no scope' => $unscoped, 'inactive' => $inactive, 'revoked scope' => $revoked] as $who => $user) {
            $this->refused(fn () => $this->coordinatorSubmits(as: $user), ChangeRequestException::ACTOR_NOT_ALLOWED);
        }
        $this->assertSame(0, ChangeRequest::count());
    }

    // ------------------------------------------------------------------ scope

    public function test_a_family_outside_the_scope_does_not_exist_for_the_coordinator(): void
    {
        $elsewhere = $this->householdIn($this->clan('OTHER_CLAN'), '823456789');
        $this->refused(fn () => $this->coordinatorSubmits($elsewhere->family), ChangeRequestException::NOT_FOUND);

        // Their own household (AL_BREEM) is not in the SCOPE_CLAN scope either.
        $own = app(FamilyAccessResolver::class)->familyContext($this->coordinatorUser)->family;
        $this->refused(fn () => $this->coordinatorSubmits($own), ChangeRequestException::NOT_FOUND);

        // An inactive Family leaves every scope.
        $this->household->family->forceFill(['status' => FamilyStatus::INACTIVE])->save();
        $this->refused(fn () => $this->coordinatorSubmits(), ChangeRequestException::NOT_FOUND);
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_branch_and_group_scopes_cover_only_their_families(): void
    {
        $group = $this->group($this->scopeClan);
        $inGroup = $this->branch($this->scopeClan, $group);
        $other = $this->branch($this->scopeClan);
        $branchCoordinator = $this->coordinator('911111110');
        $this->assign($branchCoordinator, $group);
        $grouped = $this->householdIn($this->scopeClan, '923456789', $inGroup);
        $outside = $this->householdIn($this->scopeClan, '933456789', $other);

        $this->assertSame(S::SUBMITTED, $this->coordinatorSubmits($grouped->family, as: $branchCoordinator)->request->status);
        $this->refused(fn () => $this->coordinatorSubmits($outside->family, as: $branchCoordinator), ChangeRequestException::NOT_FOUND);
        $this->refused(fn () => $this->coordinatorSubmits($this->household->family, as: $branchCoordinator), ChangeRequestException::NOT_FOUND);
    }

    public function test_a_revoked_scope_ends_the_coordinators_access_but_never_staffs(): void
    {
        $open = $this->coordinatorRequest();
        $returned = $this->returned($this->coordinatorRequest(['national_id' => '402345678']));
        $scopes = app(CoordinatorScopes::class);
        $this->assertSame(2, $scopes->requests($scopes->context($this->coordinatorUser))->count());

        // Narrowed: another assignment remains, these Families are no longer covered.
        CoordinatorScopeAssignment::where('user_id', $this->coordinatorUser->id)
            ->update(['revoked_at' => now(), 'revoked_by' => $this->reviewer->id, 'revoke_reason' => 'ADMINISTRATIVE']);
        $this->assign($this->coordinatorUser, $this->clan('OTHER_CLAN'));
        $context = $scopes->context($this->coordinatorUser->fresh());
        $this->assertTrue($context->allowed());
        $this->assertSame(0, $scopes->requests($context)->count());
        $this->refused(fn () => app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $open), ChangeRequestException::NOT_FOUND);
        $this->refused(fn () => app(ResubmitChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $returned, 'رد'), ChangeRequestException::NOT_FOUND);
        $this->refused(fn () => app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $returned), ChangeRequestException::NOT_FOUND);

        // Nothing is deleted; Staff keep the history and continue the review.
        $this->assertSame(S::SUBMITTED, $open->fresh()->status);
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $returned->fresh()->status);
        $this->assertCount(3, $this->timeline($returned));
        app(StartChangeRequestReviewAction::class)->handle($open, $this->reviewer);
        $this->assertSame(S::UNDER_REVIEW, $open->fresh()->status);

        // A fully revoked Coordinator is no Coordinator at all.
        CoordinatorScopeAssignment::where('user_id', $this->coordinatorUser->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by' => $this->reviewer->id, 'revoke_reason' => 'ADMINISTRATIVE']);
        $this->refused(fn () => app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $returned), ChangeRequestException::ACTOR_NOT_ALLOWED);
    }

    // ---------------------------------------------------------- channel rules

    public function test_the_household_sees_but_never_cancels_or_answers_a_coordinator_request(): void
    {
        $request = $this->returned($this->coordinatorRequest());

        $this->refused(fn () => app(CancelChangeRequestAction::class)->handle($this->household, $request), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->refused(fn () => app(ResubmitChangeRequestAction::class)->handle($this->household, $request, 'رد'), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->assertSame([], FamilyChangeRequestActions::for($request, $this->household->user));
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $request->fresh()->status);

        // D6 / D8: it is in the Family's own history, with its channel.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($this->household->user->fresh())->getJson('/api/v1/family/change-requests/'.$request->uuid)
            ->assertOk()->assertJsonPath('data.submission_channel', 'COORDINATOR')->assertJsonPath('data.available_actions', []);
    }

    public function test_a_coordinator_never_acts_on_family_channel_or_another_coordinators_requests(): void
    {
        $familyRequest = $this->familySubmits();
        $this->refused(fn () => app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser, $familyRequest), ChangeRequestException::NOT_FOUND);

        $colleague = $this->coordinator('921111110');
        $this->assign($colleague, $this->scopeClan);
        $request = $this->returned($this->coordinatorRequest(['national_id' => '402345678']));
        $this->refused(fn () => app(CancelChangeRequestAction::class)->handleCoordinator($colleague, $request), ChangeRequestException::NOT_FOUND);
        $this->refused(fn () => app(ResubmitChangeRequestAction::class)->handleCoordinator($colleague, $request, 'رد'), ChangeRequestException::NOT_FOUND);
        $this->assertSame(S::SUBMITTED, $familyRequest->fresh()->status);
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $request->fresh()->status);
    }

    public function test_family_requests_keep_their_own_flow(): void
    {
        $request = $this->returned($this->familySubmits());
        app(ResubmitChangeRequestAction::class)->handle($this->household, $request, 'أرفقت الوثيقة.');
        app(CancelChangeRequestAction::class)->handle($this->household, $request->fresh());

        $this->assertSame(SubmissionChannel::FAMILY, $request->fresh()->submission_channel);
        $this->assertSame(['SUBMITTED', 'REVIEW_STARTED', 'RETURNED', 'RESUBMITTED', 'CANCELLED'], array_column($this->timeline($request), 0));
        $this->assertSame(['FAMILY', 'STAFF', 'STAFF', 'FAMILY', 'FAMILY'], array_column($this->timeline($request), 1));
    }

    // ---------------------------------------------------------- Staff only

    public function test_a_coordinator_never_reviews_approves_rejects_or_applies(): void
    {
        $request = $this->coordinatorRequest();
        $coordinator = $this->coordinatorUser->fresh();
        $this->refused(fn () => app(StartChangeRequestReviewAction::class)->handle($request, $coordinator), ChangeRequestException::ACTOR_NOT_ALLOWED);
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        $this->refused(fn () => app(ApproveChangeRequestAction::class)->handle($request, $coordinator), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->refused(fn () => app(RejectChangeRequestAction::class)->handle($request, $coordinator, ChangeRequestRejectionReason::OTHER, 'لا'), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->refused(fn () => app(ReturnChangeRequestForClarificationAction::class)->handle($request, $coordinator, 'وضح'), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->refused(fn () => app(ApplyChangeRequestAction::class)->handle($request, $coordinator), ChangeRequestException::ACTOR_NOT_ALLOWED);

        // No COORDINATOR transition reaches a Staff status, and the recorder refuses one.
        foreach (ChangeRequestTransitions::coordinator() as $transition) {
            $this->assertContains($transition->to, [S::SUBMITTED, S::RESUBMITTED, S::CANCELLED]);
            $this->assertStringStartsWith('coordinator-request.', $transition->permission);
        }
        DB::beginTransaction();
        try {
            $request->fresh()->forceFill(['status' => S::APPROVED, 'approved_by' => $this->reviewer->id, 'approved_at' => now()])->save();
            WorkflowEventRecorder::record($request->fresh(), S::UNDER_REVIEW, WorkflowEventType::APPROVED, WorkflowActorSide::COORDINATOR, $this->coordinatorUser->id);
            $this->fail('A coordinator APPROVED event was recorded.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_the_recorder_keeps_each_side_on_its_own_channel(): void
    {
        $coordinatorRequest = $this->coordinatorRequest();
        $familyRequest = $this->familySubmits(['national_id' => '402345678']);

        foreach ([[$coordinatorRequest, WorkflowActorSide::FAMILY], [$familyRequest, WorkflowActorSide::COORDINATOR]] as [$request, $side]) {
            DB::beginTransaction();
            try {
                $request->forceFill(['status' => S::CANCELLED, 'cancelled_by' => $this->household->user->id, 'cancelled_at' => now()])->save();
                WorkflowEventRecorder::record($request, S::SUBMITTED, WorkflowEventType::CANCELLED, $side, $this->household->user->id);
                $this->fail('A cross-channel event was recorded.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
        }
    }

    // ------------------------------------------------- audit and replays

    public function test_the_coordinators_own_transitions_are_audited_and_replays_write_nothing(): void
    {
        $request = $this->returned($this->coordinatorRequest());
        $resubmit = fn () => app(ResubmitChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request, 'أرفقت الوثيقة.');
        $this->assertFalse($resubmit()->replayed);
        $this->assertTrue($resubmit()->replayed);

        $cancel = fn () => app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request);
        $this->assertFalse($cancel()->replayed);
        $this->assertTrue($cancel()->replayed);
        $this->refused(fn () => app(ResubmitChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request, 'رد آخر'), ChangeRequestException::INVALID_TRANSITION);

        $request->refresh();
        $this->assertSame(S::CANCELLED, $request->status);
        $this->assertSame($this->coordinatorUser->id, $request->cancelled_by);
        $this->assertSame([
            ['SUBMITTED', 'COORDINATOR', $this->coordinatorUser->id],
            ['REVIEW_STARTED', 'STAFF', $this->reviewer->id],
            ['RETURNED', 'STAFF', $this->reviewer->id],
            ['RESUBMITTED', 'COORDINATOR', $this->coordinatorUser->id],
            ['CANCELLED', 'COORDINATOR', $this->coordinatorUser->id],
        ], $this->timeline($request));
        $this->assertSame('أرفقت الوثيقة.', $request->workflowEvents()->where('event_type', WorkflowEventType::RESUBMITTED)->value('public_message'));
    }

    public function test_a_coordinator_cannot_cancel_after_approval(): void
    {
        $request = $this->coordinatorRequest();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, new ChangeRequestApprovalEvidence(
            [ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED], self::MEMBER_NID,
        ));
        $this->refused(fn () => app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request), ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    public function test_submission_idempotency_and_open_conflicts_span_both_channels(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->coordinatorSubmits(reference: $reference);
        $again = $this->coordinatorSubmits(reference: $reference);
        $this->assertTrue($again->replayed);
        $this->assertSame($first->request->id, $again->request->id);
        $this->refused(fn () => $this->coordinatorSubmits(data: ['full_name' => 'اسم مختلف'], reference: $reference), ChangeRequestException::IDEMPOTENCY_CONFLICT);

        // One open proposal per Family and National ID, whoever submitted it.
        $this->refused(fn () => $this->familySubmits(), ChangeRequestException::ALREADY_OPEN);
        $this->familySubmits(['national_id' => '402345678']);
        $this->refused(fn () => $this->coordinatorSubmits(data: ['national_id' => '402345678']), ChangeRequestException::ALREADY_OPEN);
        $this->assertSame(2, ChangeRequest::count());
    }

    public function test_a_reference_never_crosses_channels_for_the_same_account(): void
    {
        // The Coordinator's own household uses the family channel with the same account.
        $own = app(FamilyAccessResolver::class)->familyContext($this->coordinatorUser);
        $reference = (string) Str::uuid();
        app(SubmitChangeRequestAction::class)->handle($own, new ChangeRequestSubmission(ChangeRequestType::OTHER, ['paper_form_no' => 'PF-OWN'], null, $reference));

        $this->refused(fn () => $this->coordinatorSubmits(reference: $reference), ChangeRequestException::IDEMPOTENCY_CONFLICT);
        $this->assertSame(1, ChangeRequest::count());
    }
}
