<?php

namespace Tests\Feature\ChangeRequests;

use App\Enums\ChangeRequestApplyFailure;
use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType as E;
use App\Models\ChangeRequest;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\WorkflowEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;
use Throwable;

/**
 * WorkflowEventRecorder (PWA-5a): the only writer of workflow events. It
 * records what the state machine allows and refuses everything else.
 * Synthetic data only.
 */
class WorkflowEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create();
    }

    /** @param array<string, mixed> $columns */
    private function moveTo(ChangeRequest $request, array $columns): ChangeRequest
    {
        $request->forceFill($columns)->save();

        return $request;
    }

    /** @param array<string, mixed> $args */
    private function record(ChangeRequest $request, ?S $from, E $event, WorkflowActorSide $side, array $args = []): WorkflowEvent
    {
        return DB::transaction(fn () => WorkflowEventRecorder::record(
            $request, $from, $event, $side,
            array_key_exists('actor', $args) ? $args['actor'] : ($side === WorkflowActorSide::FAMILY ? $request->submitted_by : $this->staff->id),
            $args['reason'] ?? null, $args['public'] ?? null, $args['internal'] ?? null, $args['metadata'] ?? [],
        ));
    }

    private function refused(callable $call, string $exception = InvalidArgumentException::class): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            return;
        }
        $this->fail('The recorder accepted an event it must refuse.');
    }

    public function test_it_records_a_submission_with_the_morph_alias_and_no_payload(): void
    {
        $request = ChangeRequest::factory()->create(['submitted_data' => ['governorate' => 'محافظة تجريبية']]);

        $event = $this->record($request, null, E::SUBMITTED, WorkflowActorSide::FAMILY);

        $row = DB::table('workflow_events')->where('id', $event->id)->first();
        $this->assertSame('change_request', $row->workflowable_type);
        $this->assertSame($request->id, (int) $row->workflowable_id);
        $this->assertNull($row->from_status);
        $this->assertSame('SUBMITTED', $row->to_status);
        $this->assertSame('SUBMITTED', $row->event_type);
        $this->assertSame('FAMILY', $row->actor_side);
        $this->assertSame($request->submitted_by, (int) $row->actor_user_id);
        $this->assertNull($row->metadata);
        $this->assertStringNotContainsString('محافظة تجريبية', json_encode((array) $row, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($request->workflowEvents()->first()->is($event));
    }

    public function test_it_records_every_transition_of_a_full_lifecycle_in_order(): void
    {
        $request = ChangeRequest::factory()->create();
        $staff = $this->staff->id;

        $this->record($request, null, E::SUBMITTED, WorkflowActorSide::FAMILY);
        $this->record($this->moveTo($request, ['status' => S::UNDER_REVIEW, 'reviewed_by' => $staff, 'reviewed_at' => now()]), S::SUBMITTED, E::REVIEW_STARTED, WorkflowActorSide::STAFF);
        $this->record($this->moveTo($request, ['status' => S::RETURNED_FOR_CLARIFICATION]), S::UNDER_REVIEW, E::RETURNED, WorkflowActorSide::STAFF, ['public' => 'يرجى توضيح العنوان.', 'internal' => 'ملاحظة داخلية']);
        $this->record($this->moveTo($request, ['status' => S::RESUBMITTED]), S::RETURNED_FOR_CLARIFICATION, E::RESUBMITTED, WorkflowActorSide::FAMILY, ['public' => 'العنوان الصحيح في الحي الشمالي.']);
        $this->record($this->moveTo($request, ['status' => S::UNDER_REVIEW]), S::RESUBMITTED, E::REVIEW_STARTED, WorkflowActorSide::STAFF);
        $this->record($this->moveTo($request, ['status' => S::APPROVED, 'approved_by' => $staff, 'approved_at' => now()]), S::UNDER_REVIEW, E::APPROVED, WorkflowActorSide::STAFF);
        $this->record($request, S::APPROVED, E::APPLY_FAILED, WorkflowActorSide::STAFF, ['reason' => ChangeRequestApplyFailure::APPLY_FAILED]);
        $this->record($this->moveTo($request, ['status' => S::APPLIED, 'applied_by' => $staff, 'applied_at' => now()]), S::APPROVED, E::APPLIED, WorkflowActorSide::STAFF);

        $this->assertSame(
            ['SUBMITTED', 'REVIEW_STARTED', 'RETURNED', 'RESUBMITTED', 'REVIEW_STARTED', 'APPROVED', 'APPLY_FAILED', 'APPLIED'],
            $request->workflowEvents()->get()->map(fn (WorkflowEvent $e) => $e->event_type->value)->all(),
        );
    }

    public function test_it_keeps_the_family_message_and_the_internal_note_apart(): void
    {
        $request = $this->moveTo(ChangeRequest::factory()->underReview($this->staff)->create(), ['status' => S::RETURNED_FOR_CLARIFICATION]);

        $event = $this->record($request, S::UNDER_REVIEW, E::RETURNED, WorkflowActorSide::STAFF, [
            'public' => "  يرجى إرفاق توضيح.\u{202E}\u{0007}  ", 'internal' => 'لا تُعرض للأسرة',
        ]);

        // Control and bidi-override characters are removed; whitespace trimmed.
        $this->assertSame('يرجى إرفاق توضيح.', $event->public_message);
        $this->assertSame('لا تُعرض للأسرة', $event->internal_note);
        // The Staff-only note never leaves through serialization.
        $this->assertArrayNotHasKey('internal_note', $event->toArray());
        $this->assertArrayHasKey('public_message', $event->toArray());
    }

    public function test_messages_follow_the_event_rules(): void
    {
        $returned = $this->moveTo(ChangeRequest::factory()->underReview($this->staff)->create(), ['status' => S::RETURNED_FOR_CLARIFICATION]);
        // A return needs a family-visible message; blank counts as none.
        $this->refused(fn () => $this->record($returned, S::UNDER_REVIEW, E::RETURNED, WorkflowActorSide::STAFF, ['public' => "  \n "]));
        $this->refused(fn () => $this->record($returned, S::UNDER_REVIEW, E::RETURNED, WorkflowActorSide::STAFF, ['public' => str_repeat('ب', 2001)]));

        $resubmitted = $this->moveTo(ChangeRequest::factory()->underReview($this->staff)->create(), ['status' => S::RESUBMITTED]);
        // A family event never carries a Staff note.
        $this->refused(fn () => $this->record($resubmitted, S::RETURNED_FOR_CLARIFICATION, E::RESUBMITTED, WorkflowActorSide::FAMILY, ['public' => 'رد', 'internal' => 'x']));

        $approved = ChangeRequest::factory()->approved($this->staff)->create();
        // An approval carries no family-visible message.
        $this->refused(fn () => $this->record($approved, S::UNDER_REVIEW, E::APPROVED, WorkflowActorSide::STAFF, ['public' => 'تمت الموافقة']));

        $this->assertSame(0, WorkflowEvent::count());
    }

    public function test_it_refuses_a_transition_the_state_machine_does_not_allow(): void
    {
        $request = ChangeRequest::factory()->create();

        // The request is SUBMITTED: claiming it came from APPROVED is refused.
        $this->refused(fn () => $this->record($request, S::APPROVED, E::SUBMITTED, WorkflowActorSide::FAMILY), LogicException::class);
        // Right transition, wrong event or wrong side.
        $this->refused(fn () => $this->record($request, null, E::APPROVED, WorkflowActorSide::FAMILY), LogicException::class);
        $this->refused(fn () => $this->record($request, null, E::SUBMITTED, WorkflowActorSide::STAFF), LogicException::class);
        // A family or Staff event needs its user.
        $this->refused(fn () => $this->record($request, null, E::SUBMITTED, WorkflowActorSide::FAMILY, ['actor' => null]));

        // An unsaved status change is refused: the event follows the saved state.
        $request->status = S::CANCELLED;
        $this->refused(fn () => $this->record($request, S::SUBMITTED, E::CANCELLED, WorkflowActorSide::FAMILY), LogicException::class);

        $this->assertSame(0, WorkflowEvent::count());
    }

    public function test_rejections_carry_an_allowed_reason_and_nothing_else_does(): void
    {
        $staff = $this->staff->id;
        $rejected = fn () => $this->moveTo(ChangeRequest::factory()->underReview($this->staff)->create(), [
            'status' => S::REJECTED, 'rejected_by' => $staff, 'rejected_at' => now(), 'rejection_reason_code' => ChangeRequestRejectionReason::CANNOT_VERIFY,
        ]);

        $event = $this->record($rejected(), S::UNDER_REVIEW, E::REJECTED, WorkflowActorSide::STAFF, [
            'reason' => ChangeRequestRejectionReason::CANNOT_VERIFY, 'public' => 'تعذّر التحقق من البيانات.',
        ]);
        $this->assertSame('CANNOT_VERIFY', $event->reason_code);

        $this->refused(fn () => $this->record($rejected(), S::UNDER_REVIEW, E::REJECTED, WorkflowActorSide::STAFF), LogicException::class);

        // APPROVED → REJECTED only as NO_LONGER_APPLICABLE (AE-4).
        $approvedThenRejected = fn () => $this->moveTo(ChangeRequest::factory()->approved($this->staff)->create(), [
            'apply_failure_count' => 1, 'last_apply_failed_at' => now(),
            'status' => S::REJECTED, 'rejected_by' => $staff, 'rejected_at' => now(), 'rejection_reason_code' => ChangeRequestRejectionReason::NO_LONGER_APPLICABLE,
        ]);
        $this->record($approvedThenRejected(), S::APPROVED, E::REJECTED, WorkflowActorSide::STAFF, ['reason' => ChangeRequestRejectionReason::NO_LONGER_APPLICABLE]);
        $this->refused(fn () => $this->record($approvedThenRejected(), S::APPROVED, E::REJECTED, WorkflowActorSide::STAFF, ['reason' => ChangeRequestRejectionReason::DATA_CHANGED]), LogicException::class);

        // A reason on a non-rejection is refused.
        $this->refused(fn () => $this->record(ChangeRequest::factory()->create(), null, E::SUBMITTED, WorkflowActorSide::FAMILY, ['reason' => ChangeRequestRejectionReason::OTHER]), LogicException::class);
    }

    public function test_apply_failed_is_recorded_only_on_an_approved_request(): void
    {
        $approved = ChangeRequest::factory()->approved($this->staff)->create();
        $event = $this->record($approved, S::APPROVED, E::APPLY_FAILED, WorkflowActorSide::STAFF, ['reason' => ChangeRequestApplyFailure::PRECONDITION_FAILED]);
        $this->assertSame(S::APPROVED, $event->from_status);
        $this->assertSame(S::APPROVED, $event->to_status);
        $this->assertSame('PRECONDITION_FAILED', $event->reason_code);

        // PWA-5b: the failure code is required, and belongs to APPLY_FAILED only.
        $this->refused(fn () => $this->record($approved, S::APPROVED, E::APPLY_FAILED, WorkflowActorSide::STAFF));
        $this->refused(fn () => $this->record($approved, S::APPROVED, E::APPLY_FAILED, WorkflowActorSide::STAFF, ['reason' => ChangeRequestRejectionReason::OTHER]));
        $this->refused(fn () => $this->record(ChangeRequest::factory()->create(), null, E::SUBMITTED, WorkflowActorSide::FAMILY, ['reason' => ChangeRequestApplyFailure::APPLY_FAILED]));

        $this->refused(fn () => $this->record(ChangeRequest::factory()->create(), S::SUBMITTED, E::APPLY_FAILED, WorkflowActorSide::STAFF), LogicException::class);
        $this->refused(fn () => $this->record($approved, S::APPROVED, E::APPLY_FAILED, WorkflowActorSide::FAMILY), LogicException::class);
    }

    public function test_metadata_is_allow_listed_and_nothing_is_allowed_yet(): void
    {
        $request = ChangeRequest::factory()->create();

        foreach ([['mobile' => '0590000000'], ['national_id' => '000000000'], ['submitted_data' => 'x'], [0 => 'x']] as $metadata) {
            $this->refused(fn () => $this->record($request, null, E::SUBMITTED, WorkflowActorSide::FAMILY, ['metadata' => $metadata]));
        }
        $this->assertSame(0, WorkflowEvent::count());
    }

    public function test_it_logs_nothing(): void
    {
        // (The "inside a transaction" guard cannot be observed here:
        // RefreshDatabase wraps every test in a transaction.)
        Log::spy();
        $request = ChangeRequest::factory()->create(['submitted_data' => ['mobile' => '0590000000']]);

        $this->record($request, null, E::SUBMITTED, WorkflowActorSide::FAMILY);
        $this->refused(fn () => $this->record($request, null, E::SUBMITTED, WorkflowActorSide::FAMILY, ['metadata' => ['mobile' => '0590000000']]));

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_events_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $request = ChangeRequest::factory()->create();
        $event = $this->record($request, null, E::SUBMITTED, WorkflowActorSide::FAMILY);

        $this->refused(fn () => $event->forceFill(['public_message' => 'x'])->save(), LogicException::class);
        $this->refused(fn () => $event->delete(), LogicException::class);
        $this->assertNull($event->fresh()->public_message);
        $this->assertSame(1, WorkflowEvent::count());
    }
}
