<?php

namespace Tests\Feature\ChangeRequests\Engine;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\RejectChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Enums\ChangeRequestRejectionReason as R;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\FamilyActivityType;
use App\Enums\FamilyStatus;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\FamilyActivity;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\ChangeRequests\FakeChangeRequestHandler;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * Approve, reject and APPLY (PWA-5b): APPROVED ≠ APPLIED; the registry write
 * and APPLIED commit together or not at all; failures are recorded after the
 * rollback; retries and replays are exact. Synthetic data only.
 */
class ApproveApplyTest extends TestCase
{
    use ChangeRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private FamilyAccessResult $context;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpChangeRequestEngine();
        $this->context = $this->headContext();
        $this->context->family->forceFill(['paper_form_no' => 'PF-OLD'])->save();
        $this->reviewer = $this->staff();
    }

    private function refusedWith(callable $call, string $code): void
    {
        try {
            $call();
        } catch (ChangeRequestException $e) {
            $this->assertSame($code, $e->reason);

            return;
        }
        $this->fail("Expected {$code}.");
    }

    private function underReview(string $value = 'PF-NEW'): ChangeRequest
    {
        $request = $this->submit($this->context, ['paper_form_no' => $value]);
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);

        return $request;
    }

    private function approved(string $value = 'PF-NEW'): ChangeRequest
    {
        $request = $this->underReview($value);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);

        return $request;
    }

    private function paperForm(): ?string
    {
        return $this->context->family->fresh()->paper_form_no;
    }

    private function activities(FamilyActivityType $type): int
    {
        return FamilyActivity::where('event_type', $type)->count();
    }

    // ---------------------------------------------------------------- approve

    public function test_approval_authorizes_but_changes_no_canonical_data(): void
    {
        $request = $this->underReview();

        $out = app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);

        $this->assertSame(S::APPROVED, $out->request->status);
        $this->assertSame($this->reviewer->id, $out->request->approved_by);
        $this->assertNotNull($out->request->approved_at);
        $this->assertSame('PF-OLD', $this->paperForm());
        $this->assertSame(0, FakeChangeRequestHandler::$applied);
        $this->assertSame(0, $this->activities(FamilyActivityType::FAMILY_UPDATED));
        $this->assertSame(0, $this->activities(FamilyActivityType::CHANGE_REQUEST_APPLIED));
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPROVED)->count());

        $this->assertTrue(app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer)->replayed);
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPROVED)->count());
    }

    public function test_approval_refuses_a_stale_base_a_failed_precondition_and_the_wrong_state(): void
    {
        $stale = $this->underReview();
        $this->context->family->forceFill(['paper_form_no' => 'PF-CHANGED-MEANWHILE'])->save();
        $this->refusedWith(fn () => app(ApproveChangeRequestAction::class)->handle($stale, $this->reviewer), ChangeRequestException::BASE_CHANGED);
        $this->assertSame(S::UNDER_REVIEW, $stale->fresh()->status);

        $stale->forceFill(['status' => S::CANCELLED, 'cancelled_by' => $this->context->user->id, 'cancelled_at' => now()])->save();
        $request = $this->underReview('PF-X');
        FakeChangeRequestHandler::$preconditionFails = true;
        $this->refusedWith(fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::PRECONDITION_FAILED);
        FakeChangeRequestHandler::$preconditionFails = false;

        $submitted = ChangeRequest::factory()->create(['type' => 'OTHER']);
        $this->refusedWith(fn () => app(ApproveChangeRequestAction::class)->handle($submitted, $this->reviewer), ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame(0, WorkflowEvent::where('event_type', WorkflowEventType::APPROVED)->count());
    }

    public function test_a_family_that_is_no_longer_active_cannot_be_approved_for(): void
    {
        $request = $this->underReview();
        $this->context->family->forceFill(['status' => FamilyStatus::ARCHIVED])->save();

        $this->refusedWith(fn () => app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::NOT_APPLICABLE);
    }

    // ---------------------------------------------------------------- reject

    public function test_rejection_under_review_records_the_reason_message_and_activity_once(): void
    {
        $request = $this->underReview();

        $out = app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, R::CANNOT_VERIFY, ' تعذّر التحقق ', 'داخلي');

        $this->assertSame(S::REJECTED, $out->request->status);
        $this->assertSame(R::CANNOT_VERIFY, $out->request->rejection_reason_code);
        $this->assertSame('تعذّر التحقق', $out->request->rejection_reason);
        $event = WorkflowEvent::where('event_type', WorkflowEventType::REJECTED)->sole();
        $this->assertSame('CANNOT_VERIFY', $event->reason_code);
        $this->assertSame('داخلي', $event->internal_note);
        $this->assertSame(1, $this->activities(FamilyActivityType::CHANGE_REQUEST_REJECTED));
        $this->assertSame(['request_type' => 'OTHER'], FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_REJECTED)->sole()->metadata);

        $this->assertTrue(app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, R::CANNOT_VERIFY)->replayed);
        $this->assertSame(1, $this->activities(FamilyActivityType::CHANGE_REQUEST_REJECTED));
        $this->refusedWith(fn () => app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, R::OTHER, 'x'), ChangeRequestException::INVALID_TRANSITION);
    }

    public function test_other_needs_a_family_visible_message_and_submitted_requests_are_not_rejected(): void
    {
        $request = $this->underReview();
        try {
            app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, R::OTHER);
            $this->fail('OTHER without a message was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('message', $e->errors());
        }

        FakeChangeRequestHandler::$conflictKeys = false; // a second open request on purpose
        $submitted = $this->submit($this->context, ['paper_form_no' => 'PF-S'], null);
        $this->refusedWith(fn () => app(RejectChangeRequestAction::class)->handle($submitted, $this->reviewer, R::CANNOT_VERIFY), ChangeRequestException::INVALID_TRANSITION);
    }

    public function test_an_approved_request_is_never_rejected_as_a_generic_undo(): void
    {
        $request = $this->approved();

        foreach (R::cases() as $reason) {
            $this->refusedWith(
                fn () => app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, $reason, 'رسالة'),
                ChangeRequestException::INVALID_TRANSITION,
            );
        }
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    public function test_an_approved_request_is_rejected_as_no_longer_applicable_only_after_a_refused_apply(): void
    {
        // An unexpected system failure does not qualify: retry instead.
        $request = $this->approved();
        FakeChangeRequestHandler::$failAfterWrite = 'unexpected';
        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::APPLY_FAILED);
        $this->refusedWith(fn () => app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, R::NO_LONGER_APPLICABLE), ChangeRequestException::INVALID_TRANSITION);

        // A refusal does — and only as NO_LONGER_APPLICABLE.
        FakeChangeRequestHandler::$failAfterWrite = null;
        FakeChangeRequestHandler::$preconditionFails = true;
        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::PRECONDITION_FAILED);
        $this->refusedWith(fn () => app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, R::DATA_CHANGED, 'x'), ChangeRequestException::INVALID_TRANSITION);

        $out = app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, R::NO_LONGER_APPLICABLE);
        $this->assertSame(S::REJECTED, $out->request->status);
        $this->assertNotNull($out->request->approved_at);
        $this->assertSame(1, $this->activities(FamilyActivityType::CHANGE_REQUEST_REJECTED));
    }

    // ---------------------------------------------------------------- apply

    public function test_apply_changes_the_registry_once_through_the_domain_action(): void
    {
        $request = $this->approved();

        $out = app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);

        $this->assertFalse($out->replayed);
        $this->assertSame(S::APPLIED, $out->request->status);
        $this->assertSame($this->reviewer->id, $out->request->applied_by);
        $this->assertSame('PF-NEW', $this->paperForm());
        $this->assertSame(1, FakeChangeRequestHandler::$applied);
        // The canonical action's own activity AND the request's milestone.
        $this->assertSame(1, $this->activities(FamilyActivityType::FAMILY_UPDATED));
        $this->assertSame(1, $this->activities(FamilyActivityType::CHANGE_REQUEST_APPLIED));
        $this->assertSame(['request_type' => 'OTHER'], FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->sole()->metadata);
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());

        // A duplicate apply is a replay: nothing runs or is written again.
        $again = app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        $this->assertTrue($again->replayed);
        $this->assertSame(1, FakeChangeRequestHandler::$applied);
        $this->assertSame(1, $this->activities(FamilyActivityType::FAMILY_UPDATED));
        $this->assertSame(1, $this->activities(FamilyActivityType::CHANGE_REQUEST_APPLIED));
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());
    }

    public function test_apply_only_from_approved(): void
    {
        $request = $this->underReview();

        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame('PF-OLD', $this->paperForm());
        // A transition refusal is not an apply attempt.
        $this->assertSame(0, $request->fresh()->apply_failure_count);
        $this->assertSame(0, WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->count());
    }

    public function test_an_unexpected_failure_rolls_everything_back_and_is_recorded_safely(): void
    {
        Log::spy();
        $request = $this->approved('PF-SECRET-0590000000');
        FakeChangeRequestHandler::$failAfterWrite = 'unexpected';

        try {
            app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
            $this->fail('The failing apply succeeded');
        } catch (ChangeRequestException $e) {
            $this->assertSame(ChangeRequestException::APPLY_FAILED, $e->reason);
            $this->assertStringNotContainsString('0590000000', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        // The canonical write happened inside the transaction — and was rolled back.
        $this->assertSame(1, FakeChangeRequestHandler::$applied);
        $this->assertSame('PF-OLD', $this->paperForm());
        $this->assertSame(0, $this->activities(FamilyActivityType::FAMILY_UPDATED));
        $this->assertSame(0, $this->activities(FamilyActivityType::CHANGE_REQUEST_APPLIED));
        $this->assertSame(0, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());

        $fresh = $request->fresh();
        $this->assertSame(S::APPROVED, $fresh->status);
        $this->assertNull($fresh->applied_at);
        $this->assertSame(1, $fresh->apply_failure_count);
        $this->assertNotNull($fresh->last_apply_failed_at);
        $failed = WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->sole();
        $this->assertSame('APPLY_FAILED', $failed->reason_code);
        $this->assertSame(S::APPROVED, $failed->from_status);
        $this->assertSame(S::APPROVED, $failed->to_status);
        $this->assertNull($failed->public_message);
        $this->assertNull($failed->metadata);
        $this->assertStringNotContainsString('0590000000', json_encode(WorkflowEvent::all()->toArray(), JSON_UNESCAPED_UNICODE));

        // Logged with the request uuid and the exception class — never its message.
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($request) {
            return $context === ['change_request' => $request->uuid, 'exception' => \RuntimeException::class]
                && ! str_contains($message, '0590000000');
        });
    }

    public function test_a_refusal_after_the_canonical_write_also_rolls_back(): void
    {
        $request = $this->approved();
        FakeChangeRequestHandler::$failAfterWrite = 'refusal';

        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::NOT_APPLICABLE);

        $this->assertSame('PF-OLD', $this->paperForm());
        $this->assertSame(S::APPROVED, $request->fresh()->status);
        $this->assertSame('NOT_APPLICABLE', WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->sole()->reason_code);
    }

    public function test_a_stale_base_refuses_apply_without_overwriting_newer_data(): void
    {
        $request = $this->approved();
        $this->context->family->forceFill(['paper_form_no' => 'PF-NEWER'])->save();

        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::BASE_CHANGED);

        $this->assertSame('PF-NEWER', $this->paperForm());
        $this->assertSame(0, FakeChangeRequestHandler::$applied);
        $this->assertSame('BASE_CHANGED', WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->sole()->reason_code);
    }

    public function test_a_retry_after_a_failure_applies_exactly_once(): void
    {
        $request = $this->approved();
        FakeChangeRequestHandler::$failAfterWrite = 'unexpected';
        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::APPLY_FAILED);
        $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer), ChangeRequestException::APPLY_FAILED);
        $this->assertSame(2, $request->fresh()->apply_failure_count);

        FakeChangeRequestHandler::$failAfterWrite = null;
        $out = app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);

        $this->assertSame(S::APPLIED, $out->request->status);
        $this->assertSame('PF-NEW', $this->paperForm());
        $this->assertSame(1, $this->activities(FamilyActivityType::FAMILY_UPDATED));
        $this->assertSame(1, $this->activities(FamilyActivityType::CHANGE_REQUEST_APPLIED));
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());
        $this->assertSame(2, WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->count());
        $this->assertSame(
            ['SUBMITTED', 'REVIEW_STARTED', 'APPROVED', 'APPLY_FAILED', 'APPLY_FAILED', 'APPLIED'],
            WorkflowEvent::orderBy('id')->get()->map(fn ($e) => $e->event_type->value)->all(),
        );
    }

    public function test_only_staff_holding_apply_can_apply_or_approve(): void
    {
        $request = $this->approved();

        foreach ([$this->context->user, $this->staff('DATA_ENTRY'), $this->staff('SOCIAL_WORKER'), $this->headContext('523456789', ['FAMILY_USER', 'COORDINATOR'])->user] as $actor) {
            $this->refusedWith(fn () => app(ApplyChangeRequestAction::class)->handle($request, $actor), ChangeRequestException::ACTOR_NOT_ALLOWED);
        }
        $this->assertSame('PF-OLD', $this->paperForm());
        $this->assertSame(0, $request->fresh()->apply_failure_count);

        FakeChangeRequestHandler::$conflictKeys = false; // a second open request on purpose
        $review = $this->underReview('PF-Y');
        $this->refusedWith(fn () => app(ApproveChangeRequestAction::class)->handle($review, $this->context->user), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->refusedWith(fn () => app(RejectChangeRequestAction::class)->handle($review, $this->context->user, R::OTHER, 'x'), ChangeRequestException::ACTOR_NOT_ALLOWED);
    }
}
