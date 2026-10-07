<?php

namespace Tests\Feature\ChangeRequests\Engine;

use App\Actions\ChangeRequests\CancelChangeRequestAction;
use App\Actions\ChangeRequests\ResubmitChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\FamilyActivityType;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\FamilyActivity;
use App\Models\WorkflowEvent;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * Start review, return for clarification, resubmit and cancel (PWA-5b).
 * None changes the proposal or records FamilyActivity. Synthetic data only.
 */
class ReviewWorkflowTest extends TestCase
{
    use ChangeRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpChangeRequestEngine();
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

    /** @return array<string, mixed> the proposal columns that must never change */
    private function proposal(ChangeRequest $request): array
    {
        return $request->fresh()->only(['submitted_data', 'person_id', 'target_membership_id', 'base_fingerprint', 'base_key_version', 'reason', 'type']);
    }

    private function onlySubmittedActivity(): void
    {
        $this->assertSame(
            [FamilyActivityType::CHANGE_REQUEST_SUBMITTED],
            FamilyActivity::where('event_type', 'like', 'CHANGE_REQUEST_%')->pluck('event_type')->all(),
        );
    }

    public function test_the_full_clarification_loop_keeps_the_proposal_immutable(): void
    {
        $context = $this->headContext();
        $request = $this->submit($context);
        $before = $this->proposal($request);
        $reviewer = $this->staff();

        $out = app(StartChangeRequestReviewAction::class)->handle($request, $reviewer);
        $this->assertSame(S::UNDER_REVIEW, $out->request->status);
        $this->assertSame($reviewer->id, $out->request->reviewed_by);
        $this->assertNotNull($out->request->reviewed_at);

        $out = app(ReturnChangeRequestForClarificationAction::class)->handle($request, $reviewer, ' يرجى توضيح رقم الاستمارة ', 'ملاحظة داخلية');
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $out->request->status);
        $returned = ChangeRequest::find($request->id)->workflowEvents->last();
        $this->assertSame('يرجى توضيح رقم الاستمارة', $returned->public_message);
        $this->assertSame('ملاحظة داخلية', $returned->internal_note);

        $out = app(ResubmitChangeRequestAction::class)->handle($context, $request, 'الرقم الصحيح موجود في الاستمارة الورقية.');
        $this->assertSame(S::RESUBMITTED, $out->request->status);
        $resubmitted = ChangeRequest::find($request->id)->workflowEvents->last();
        $this->assertSame('الرقم الصحيح موجود في الاستمارة الورقية.', $resubmitted->public_message);
        $this->assertNull($resubmitted->internal_note);

        $second = $this->staff('ADMINISTRATOR');
        $out = app(StartChangeRequestReviewAction::class)->handle($request, $second);
        $this->assertSame(S::UNDER_REVIEW, $out->request->status);
        $this->assertSame($second->id, $out->request->reviewed_by);

        $this->assertSame($before, $this->proposal($request));
        $this->assertSame(1, ChangeRequest::count());
        $this->assertSame(
            ['SUBMITTED', 'REVIEW_STARTED', 'RETURNED', 'RESUBMITTED', 'REVIEW_STARTED'],
            WorkflowEvent::orderBy('id')->get()->map(fn ($e) => $e->event_type->value)->all(),
        );
        $this->onlySubmittedActivity();
    }

    public function test_start_review_is_a_replay_for_the_same_reviewer_and_refused_otherwise(): void
    {
        $request = $this->submit($this->headContext());
        $reviewer = $this->staff();

        app(StartChangeRequestReviewAction::class)->handle($request, $reviewer);
        $replay = app(StartChangeRequestReviewAction::class)->handle($request, $reviewer);
        $this->assertTrue($replay->replayed);
        $this->assertSame(2, WorkflowEvent::count());

        $this->refusedWith(fn () => app(StartChangeRequestReviewAction::class)->handle($request, $this->staff()), ChangeRequestException::INVALID_TRANSITION);
    }

    public function test_return_requires_a_family_visible_message_and_the_right_state(): void
    {
        $context = $this->headContext();
        $request = $this->submit($context);
        $reviewer = $this->staff();

        // Not under review yet.
        $this->refusedWith(fn () => app(ReturnChangeRequestForClarificationAction::class)->handle($request, $reviewer, 'رسالة'), ChangeRequestException::INVALID_TRANSITION);

        app(StartChangeRequestReviewAction::class)->handle($request, $reviewer);
        foreach ([null, '   ', "\u{200F}"] as $blank) {
            try {
                app(ReturnChangeRequestForClarificationAction::class)->handle($request, $reviewer, $blank, 'ملاحظة');
                $this->fail('A return without a message was accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('message', $e->errors());
            }
        }
        try {
            app(ReturnChangeRequestForClarificationAction::class)->handle($request, $reviewer, str_repeat('م', 2001));
            $this->fail('An overlong message was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('message', $e->errors());
        }
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);
    }

    public function test_resubmission_needs_a_response_the_right_state_and_the_owning_family(): void
    {
        $context = $this->headContext();
        $request = $this->submit($context);
        $reviewer = $this->staff();

        $this->refusedWith(fn () => app(ResubmitChangeRequestAction::class)->handle($context, $request, 'رد'), ChangeRequestException::INVALID_TRANSITION);

        app(StartChangeRequestReviewAction::class)->handle($request, $reviewer);
        app(ReturnChangeRequestForClarificationAction::class)->handle($request, $reviewer, 'وضّح');

        try {
            app(ResubmitChangeRequestAction::class)->handle($context, $request, '  ');
            $this->fail('A resubmission without a response was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('response', $e->errors());
        }

        // Another family's head never reaches this request.
        $stranger = $this->headContext('223456789');
        $this->refusedWith(fn () => app(ResubmitChangeRequestAction::class)->handle($stranger, $request, 'رد'), ChangeRequestException::NOT_FOUND);

        // Staff cannot answer for the family.
        $this->refusedWith(fn () => app(ResubmitChangeRequestAction::class)->handle(
            FamilyAccessResult::identity($reviewer, $context->link, $context->person, $context->authIdentity)->withFamily($context->membership, $context->family),
            $request, 'رد'), ChangeRequestException::ACTOR_NOT_ALLOWED);

        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $request->fresh()->status);
    }

    public function test_the_family_cancels_from_each_open_pre_approval_state(): void
    {
        $context = $this->headContext();
        $reviewer = $this->staff();
        $review = app(StartChangeRequestReviewAction::class);
        $return = app(ReturnChangeRequestForClarificationAction::class);
        $resubmit = app(ResubmitChangeRequestAction::class);

        $states = [
            'SUBMITTED' => fn ($r) => null,
            'UNDER_REVIEW' => fn ($r) => $review->handle($r, $reviewer),
            'RETURNED_FOR_CLARIFICATION' => function ($r) use ($review, $return, $reviewer) {
                $review->handle($r, $reviewer);
                $return->handle($r, $reviewer, 'وضّح');
            },
            'RESUBMITTED' => function ($r) use ($review, $return, $resubmit, $reviewer, $context) {
                $review->handle($r, $reviewer);
                $return->handle($r, $reviewer, 'وضّح');
                $resubmit->handle($context, $r, 'رد');
            },
        ];
        foreach ($states as $state => $reach) {
            $request = $this->submit($context, ['paper_form_no' => 'PF-'.$state]);
            $reach($request);
            $this->assertSame($state, $request->fresh()->status->value);

            $out = app(CancelChangeRequestAction::class)->handle($context, $request);
            $this->assertSame(S::CANCELLED, $out->request->status);
            $this->assertSame($context->user->id, $out->request->cancelled_by);
            $this->assertTrue(app(CancelChangeRequestAction::class)->handle($context, $request)->replayed);
        }

        $this->assertSame(4, WorkflowEvent::where('event_type', WorkflowEventType::CANCELLED)->count());
        $this->assertSame(4, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_SUBMITTED)->count());
        $this->assertSame(0, FamilyActivity::where('event_type', 'like', 'CHANGE_REQUEST_%')->where('event_type', '!=', 'CHANGE_REQUEST_SUBMITTED')->count());
    }

    public function test_cancel_is_refused_once_approved_or_finished_and_for_other_families(): void
    {
        $context = $this->headContext();
        $request = $this->submit($context);

        $this->refusedWith(fn () => app(CancelChangeRequestAction::class)->handle($this->headContext('323456789'), $request), ChangeRequestException::NOT_FOUND);

        $staff = $this->staff();
        foreach ([
            ['status' => S::APPROVED, 'reviewed_by' => $staff->id, 'reviewed_at' => now(), 'approved_by' => $staff->id, 'approved_at' => now()],
        ] as $state) {
            $request->forceFill($state)->save();
            $this->refusedWith(fn () => app(CancelChangeRequestAction::class)->handle($context, $request), ChangeRequestException::INVALID_TRANSITION);
        }
        $request->forceFill(['status' => S::APPLIED, 'applied_by' => $staff->id, 'applied_at' => now()])->save();
        $this->refusedWith(fn () => app(CancelChangeRequestAction::class)->handle($context, $request), ChangeRequestException::INVALID_TRANSITION);

        $rejected = $this->submit($context, ['paper_form_no' => 'PF-R']);
        $rejected->forceFill(['status' => S::REJECTED, 'reviewed_by' => $staff->id, 'reviewed_at' => now(), 'rejected_by' => $staff->id,
            'rejected_at' => now(), 'rejection_reason_code' => 'OTHER'])->save();
        $this->refusedWith(fn () => app(CancelChangeRequestAction::class)->handle($context, $rejected), ChangeRequestException::INVALID_TRANSITION);
    }

    public function test_a_later_head_of_the_same_family_may_act_on_its_history(): void
    {
        $previous = $this->headContext();
        $request = $this->submit($previous);

        // The same Family, now represented by another eligible head context.
        $current = FamilyAccessResult::identity(
            $this->familyUser(), $previous->link, $previous->person, $previous->authIdentity,
        )->withFamily($previous->membership, $previous->family);

        $this->assertSame(S::CANCELLED, app(CancelChangeRequestAction::class)->handle($current, $request)->request->status);
    }

    public function test_family_users_cannot_review_and_staff_cannot_cancel(): void
    {
        $context = $this->headContext();
        $request = $this->submit($context);

        $this->refusedWith(fn () => app(StartChangeRequestReviewAction::class)->handle($request, $context->user), ChangeRequestException::ACTOR_NOT_ALLOWED);
        $coordinator = $this->headContext('423456789', ['FAMILY_USER', 'COORDINATOR'])->user;
        $this->refusedWith(fn () => app(StartChangeRequestReviewAction::class)->handle($request, $coordinator), ChangeRequestException::ACTOR_NOT_ALLOWED);
        foreach (['DATA_ENTRY', 'SOCIAL_WORKER', 'REPORTS_VIEWER'] as $role) {
            $this->refusedWith(fn () => app(StartChangeRequestReviewAction::class)->handle($request, $this->staff($role)), ChangeRequestException::ACTOR_NOT_ALLOWED);
        }
        $inactive = $this->staff();
        $inactive->forceFill(['is_active' => false])->save();
        $this->refusedWith(fn () => app(StartChangeRequestReviewAction::class)->handle($request, $inactive), ChangeRequestException::ACTOR_NOT_ALLOWED);

        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }
}
