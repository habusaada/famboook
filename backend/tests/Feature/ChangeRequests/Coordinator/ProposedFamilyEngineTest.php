<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\CancelChangeRequestAction;
use App\Actions\ChangeRequests\RejectChangeRequestAction;
use App\Actions\ChangeRequests\ResubmitChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Enums\ChangeRequestApplyFailure;
use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\RegistrationSource;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\ChangeRequests\Handlers\AddFamilyMemberHandler;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\Support\ChangeRequests\CoordinatorRequestFixtures;
use Tests\Support\ChangeRequests\FakeProposedFamilyHandler;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.3b: a request that proposes a NEW Family (REGISTER_FAMILY; no
 * family_id) through the Staff workflow — with the TEST-ONLY
 * FakeProposedFamilyHandler, since the real handler is a later phase. No
 * Family exists before APPLY; APPLY creates it through CreateFamilyAction and
 * records created_family_id atomically; double application, failure
 * rollback, an inactive placement and the null-Family Staff views. Synthetic
 * data only.
 */
class ProposedFamilyEngineTest extends TestCase
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

    private function approved(?ChangeRequest $request = null): ChangeRequest
    {
        $request ??= $this->proposedFamily();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);

        return $request->fresh();
    }

    public function test_approval_creates_nothing_and_apply_creates_the_family_atomically(): void
    {
        $families = Family::count();
        $request = $this->approved();
        $this->assertSame(S::APPROVED, $request->status);
        $this->assertSame($families, Family::count(), 'APPROVED ≠ APPLIED: no Family yet');
        $this->assertNull($request->created_family_id);

        $outcome = app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $request->refresh();
        $created = Family::findOrFail($request->created_family_id);

        $this->assertFalse($outcome->replayed);
        $this->assertSame(S::APPLIED, $request->status);
        $this->assertNull($request->family_id);
        $this->assertSame($this->scopeClan->id, $created->clan_id);
        $this->assertSame(RegistrationSource::COORDINATOR_REQUEST, $created->registration_source);
        $this->assertSame($families + 1, Family::count());
        $this->assertSame(['SUBMITTED', 'REVIEW_STARTED', 'APPROVED', 'APPLIED'],
            $request->workflowEvents()->get()->map(fn (WorkflowEvent $e) => $e->event_type->value)->all());
        // The activity goes to the created Family, after its own FAMILY_CREATED.
        $this->assertSame([FamilyActivityType::FAMILY_CREATED, FamilyActivityType::CHANGE_REQUEST_APPLIED],
            FamilyActivity::where('family_id', $created->id)->orderBy('id')->pluck('event_type')->all());
    }

    public function test_apply_twice_never_creates_a_second_family(): void
    {
        $request = $this->approved();
        app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $count = Family::count();

        $again = app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $this->assertTrue($again->replayed);
        $this->assertSame($count, Family::count());
        $this->assertSame(1, FakeProposedFamilyHandler::$applied);
        $this->assertSame(1, $request->workflowEvents()->where('event_type', WorkflowEventType::APPLIED)->count());
    }

    public function test_a_failed_apply_rolls_the_family_back_and_is_recorded(): void
    {
        Log::spy();
        $request = $this->approved();
        $families = Family::count();
        FakeProposedFamilyHandler::$failAfterCreate = true;

        $this->refused(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::APPLY_FAILED);
        $request->refresh();
        $this->assertSame(S::APPROVED, $request->status);
        $this->assertNull($request->created_family_id);
        $this->assertSame($families, Family::count(), 'the created Family is rolled back');
        $this->assertSame(1, $request->apply_failure_count);
        $this->assertSame(ChangeRequestApplyFailure::APPLY_FAILED->value,
            $request->workflowEvents()->where('event_type', WorkflowEventType::APPLY_FAILED)->value('reason_code'));

        // A retry runs the whole path again.
        FakeProposedFamilyHandler::$failAfterCreate = false;
        app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $this->assertSame(S::APPLIED, $request->fresh()->status);
        $this->assertSame($families + 1, Family::count());
    }

    public function test_an_inactive_placement_or_a_changed_base_is_refused_and_recorded(): void
    {
        $request = $this->approved();
        $this->scopeClan->forceFill(['is_active' => false])->save();
        $this->refused(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::NOT_APPLICABLE);
        $this->scopeClan->forceFill(['is_active' => true])->save();

        FakeProposedFamilyHandler::$base = 'v2';
        $this->refused(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::BASE_CHANGED);
        $request->refresh();
        $this->assertSame(2, $request->apply_failure_count);
        $this->assertNull($request->created_family_id);

        // A refused apply allows the NO_LONGER_APPLICABLE rejection; no Family, no activity.
        $activities = FamilyActivity::count();
        app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, ChangeRequestRejectionReason::NO_LONGER_APPLICABLE);
        $this->assertSame(S::REJECTED, $request->fresh()->status);
        $this->assertSame($activities, FamilyActivity::count());
    }

    public function test_approval_refuses_an_inactive_placement_and_failed_preconditions(): void
    {
        $branch = $this->branch($this->scopeClan);
        $request = $this->proposedFamily(branch: $branch);
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);

        $branch->forceFill(['is_active' => false])->save();
        $this->refused(fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::NOT_APPLICABLE);
        $branch->forceFill(['is_active' => true])->save();
        FakeProposedFamilyHandler::$preconditionFails = true;
        $this->refused(fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::PRECONDITION_FAILED);
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);

        // A plain rejection of a proposal writes no Family activity.
        app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, ChangeRequestRejectionReason::CANNOT_VERIFY);
        $this->assertSame(S::REJECTED, $request->fresh()->status);
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_REJECTED)->count());
    }

    public function test_the_submitting_coordinator_answers_and_withdraws_a_proposal_in_scope(): void
    {
        $request = $this->proposedFamily();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ReturnChangeRequestForClarificationAction::class)->handle($request, $this->reviewer, 'أكمل البيانات.');

        app(ResubmitChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request, 'تمت الإضافة.');
        app(CancelChangeRequestAction::class)->handleCoordinator($this->coordinatorUser->fresh(), $request->fresh());
        $this->assertSame(S::CANCELLED, $request->fresh()->status);
        $this->assertNull($request->fresh()->family_id);
    }

    public function test_a_handler_that_cannot_create_a_family_never_applies_a_proposal(): void
    {
        // A proposal whose type is served by a target-based handler is a defect, never applied.
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::fake([
            ChangeRequestType::REGISTER_FAMILY->value => new AddFamilyMemberHandler,
        ]));
        $request = $this->proposedFamily();
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        $families = Family::count();

        $this->expectException(LogicException::class);
        try {
            app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);
        } finally {
            $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
            $this->assertSame($families, Family::count());
        }
    }

    // ---------------------------------------------------- null-Family views

    public function test_the_staff_queue_and_detail_show_a_proposal_without_a_family(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $branch = $this->branch($this->scopeClan);
        $request = $this->proposedFamily(branch: $branch);
        $this->coordinatorRequest();

        $list = $this->actingAs($this->reviewer)->getJson('/api/v1/change-requests')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $request->uuid);
        $this->assertNull($row['family']);
        $this->assertSame('COORDINATOR', $row['submission_channel']);
        $this->assertSame(['clan' => ['code' => 'SCOPE_CLAN', 'name' => $this->scopeClan->name], 'branch' => ['code' => $branch->code, 'name' => $branch->name]], $row['placement']);
        $this->assertNull($row['created_family_code']);
        $other = collect($list->json('data'))->firstWhere('id', '!=', $request->uuid);
        $this->assertSame($this->household->family->family_code, $other['family']['family_code']);
        $this->assertNull($other['placement']);

        // The family filter never matches a proposal.
        $filtered = $this->actingAs($this->reviewer)->getJson('/api/v1/change-requests?family='.$this->household->family->family_code)->assertOk();
        $this->assertSame([$other['id']], array_column($filtered->json('data'), 'id'));

        $this->actingAs($this->reviewer)->getJson('/api/v1/change-requests/'.$request->uuid)->assertOk()
            ->assertJsonPath('data.family', null)
            ->assertJsonPath('data.submission_channel', 'COORDINATOR')
            ->assertJsonPath('data.placement.clan.code', 'SCOPE_CLAN')
            ->assertJsonPath('data.available_actions', ['start_review'])
            ->assertJsonPath('data.timeline.0.actor_side', 'COORDINATOR');

        // Once APPLIED, the created Family is named.
        app(ApplyChangeRequestAction::class)->handle($this->approved($request), $this->reviewer);
        $code = Family::findOrFail($request->fresh()->created_family_id)->family_code;
        $this->actingAs($this->reviewer)->getJson('/api/v1/change-requests/'.$request->uuid)->assertOk()
            ->assertJsonPath('data.created_family_code', $code)
            ->assertJsonPath('data.family', null);
    }
}
