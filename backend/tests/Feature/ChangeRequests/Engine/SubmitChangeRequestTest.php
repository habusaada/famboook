<?php

namespace Tests\Feature\ChangeRequests\Engine;

use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestStatus;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyMembership;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestBase;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\ChangeRequests\FakeChangeRequestHandler;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;
use Throwable;

/**
 * SubmitChangeRequestAction (PWA-5b) through the test-only fake handler.
 * Synthetic data only.
 */
class SubmitChangeRequestTest extends TestCase
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

    public function test_a_submission_creates_a_submitted_request_from_the_trusted_context(): void
    {
        $context = $this->headContext();
        $context->family->forceFill(['paper_form_no' => 'PF-OLD'])->save();

        $request = $this->submit($context, ['paper_form_no' => '  PF-NEW-1  '], reason: 'تصحيح رقم الاستمارة');

        $this->assertSame(ChangeRequestStatus::SUBMITTED, $request->status);
        $this->assertSame($context->family->id, $request->family_id);
        $this->assertSame($context->user->id, $request->submitted_by);
        $this->assertSame($context->person->id, $request->submitted_by_person_id);
        $this->assertNotNull($request->submitted_at);
        $this->assertSame(['paper_form_no' => 'PF-NEW-1'], $request->fresh()->submitted_data);
        $this->assertSame('تصحيح رقم الاستمارة', $request->reason);
        $this->assertNull($request->target_membership_id);
        // The base is the CURRENT canonical value, fingerprinted.
        $this->assertTrue(ChangeRequestBase::matches($request->fresh(), ['paper_form_no' => 'PF-OLD']));
        $this->assertFalse(ChangeRequestBase::matches($request->fresh(), ['paper_form_no' => 'PF-NEW-1']));
        // Canonical data unchanged.
        $this->assertSame('PF-OLD', $context->family->fresh()->paper_form_no);

        $events = WorkflowEvent::all();
        $this->assertCount(1, $events);
        $this->assertSame(WorkflowEventType::SUBMITTED, $events[0]->event_type);
        $this->assertNull($events[0]->from_status);
        $activity = FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_SUBMITTED)->sole();
        $this->assertSame(['request_type' => 'OTHER'], $activity->metadata);
        $this->assertSame($context->user->id, $activity->actor_user_id);
        $this->assertSame('change_request', $activity->subject_type);
    }

    public function test_unknown_input_keys_never_reach_the_proposal_or_the_row(): void
    {
        $context = $this->headContext();
        $other = Family::factory()->create();

        $request = $this->submit($context, [
            'paper_form_no' => 'PF-2', 'family_id' => $other->id, 'status' => 'APPLIED', 'approved_by' => 1,
            'base_fingerprint' => str_repeat('a', 64), 'submitted_by' => 999, 'notes' => 'x',
        ]);

        $fresh = $request->fresh();
        $this->assertSame(['paper_form_no' => 'PF-2'], $fresh->submitted_data);
        $this->assertSame($context->family->id, $fresh->family_id);
        $this->assertSame(ChangeRequestStatus::SUBMITTED, $fresh->status);
        $this->assertNull($fresh->approved_by);
        $this->assertNotSame(str_repeat('a', 64), $fresh->base_fingerprint);
    }

    public function test_a_member_target_is_resolved_only_inside_the_family(): void
    {
        $context = $this->headContext();
        $member = FamilyMembership::factory()->create(['family_id' => $context->family->id]);
        $ref = HouseholdMemberReference::of($context->family->id, $member->id);

        $request = $this->submit($context, ['paper_form_no' => 'PF-3', 'member_ref' => $ref]);
        $this->assertSame($member->id, $request->target_membership_id);
        $this->assertSame($member->person_id, $request->person_id);

        // Another family's member reference does not resolve here.
        $foreign = FamilyMembership::factory()->create();
        $this->refusedWith(fn () => $this->submit($context, [
            'paper_form_no' => 'PF-4', 'member_ref' => HouseholdMemberReference::of($foreign->family_id, $foreign->id),
        ]), ChangeRequestException::NOT_FOUND);
        $this->refusedWith(fn () => $this->submit($context, ['paper_form_no' => 'PF-4', 'member_ref' => str_repeat('0', 64)]), ChangeRequestException::NOT_FOUND);
        $this->assertSame(1, ChangeRequest::count());
    }

    public function test_the_same_client_reference_and_request_replays_without_writing(): void
    {
        $context = $this->headContext();
        $reference = (string) Str::uuid();
        $action = app(SubmitChangeRequestAction::class);
        $submission = new ChangeRequestSubmission(ChangeRequestType::OTHER, ['paper_form_no' => 'PF-5'], 'سبب', $reference);

        $first = $action->handle($context, $submission);
        $again = $action->handle($context, new ChangeRequestSubmission(ChangeRequestType::OTHER, ['paper_form_no' => ' PF-5 '], ' سبب ', $reference));

        $this->assertFalse($first->replayed);
        $this->assertTrue($again->replayed);
        $this->assertTrue($again->request->is($first->request));
        $this->assertSame(1, ChangeRequest::count());
        $this->assertSame(1, WorkflowEvent::count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_SUBMITTED)->count());
    }

    public function test_the_same_client_reference_with_different_material_is_refused(): void
    {
        $context = $this->headContext();
        $reference = (string) Str::uuid();
        $this->submit($context, ['paper_form_no' => 'PF-6'], $reference);
        $member = FamilyMembership::factory()->create(['family_id' => $context->family->id]);

        foreach ([
            ['input' => ['paper_form_no' => 'PF-7']],
            ['input' => ['paper_form_no' => 'PF-6'], 'reason' => 'سبب آخر'],
            ['input' => ['paper_form_no' => 'PF-6', 'member_ref' => HouseholdMemberReference::of($context->family->id, $member->id)]],
        ] as $case) {
            $this->refusedWith(fn () => $this->submit($context, $case['input'], $reference, $case['reason'] ?? null), ChangeRequestException::IDEMPOTENCY_CONFLICT);
        }
        $this->assertSame(1, ChangeRequest::count());
        $this->assertSame(1, WorkflowEvent::count());
    }

    public function test_a_second_open_request_for_the_same_subject_is_refused_until_the_first_finishes(): void
    {
        $context = $this->headContext();
        $first = $this->submit($context, ['paper_form_no' => 'PF-8']);

        $this->refusedWith(fn () => $this->submit($context, ['paper_form_no' => 'PF-9']), ChangeRequestException::ALREADY_OPEN);

        // A different subject (a member) is not a conflict.
        $member = FamilyMembership::factory()->create(['family_id' => $context->family->id]);
        $this->submit($context, ['paper_form_no' => 'PF-9', 'member_ref' => HouseholdMemberReference::of($context->family->id, $member->id)]);

        // Once the first is terminal, the subject is free again.
        $first->forceFill(['status' => ChangeRequestStatus::CANCELLED, 'cancelled_by' => $context->user->id, 'cancelled_at' => now()])->save();
        $this->submit($context, ['paper_form_no' => 'PF-10']);

        // A type without conflict keys allows parallel requests.
        FakeChangeRequestHandler::$conflictKeys = false;
        $this->submit($context, ['paper_form_no' => 'PF-11']);
        $this->assertSame(4, ChangeRequest::count());
    }

    public function test_unavailable_types_failed_preconditions_and_invalid_input_create_nothing(): void
    {
        $context = $this->headContext();

        // No handler registered for this type.
        $this->refusedWith(fn () => app(SubmitChangeRequestAction::class)->handle(
            $context, new ChangeRequestSubmission(ChangeRequestType::RESIDENCE_UPDATE, ['paper_form_no' => 'x'], null, (string) Str::uuid())
        ), ChangeRequestException::TYPE_UNAVAILABLE);

        FakeChangeRequestHandler::$submittable = false;
        $this->refusedWith(fn () => $this->submit($context), ChangeRequestException::TYPE_UNAVAILABLE);
        FakeChangeRequestHandler::$submittable = true;

        FakeChangeRequestHandler::$preconditionFails = true;
        $this->refusedWith(fn () => $this->submit($context), ChangeRequestException::PRECONDITION_FAILED);
        FakeChangeRequestHandler::$preconditionFails = false;

        foreach ([[], ['paper_form_no' => str_repeat('x', 51)], ['paper_form_no' => ['nested']]] as $input) {
            try {
                $this->submit($context, $input);
                $this->fail('Invalid input accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        // Oversized or too deeply nested input never reaches validation.
        foreach ([['paper_form_no' => 'x', 'pad' => str_repeat('y', 17000)], ['paper_form_no' => 'x', 'a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]] as $input) {
            try {
                $this->submit($context, $input);
                $this->fail('Unbounded input accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('input', $e->errors());
            }
        }
        try {
            $this->submit($context, ['paper_form_no' => 'x'], 'not-a-uuid');
            $this->fail('A non-UUID client reference was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('client_reference', $e->errors());
        }

        $this->assertSame(0, ChangeRequest::count());
        $this->assertSame(0, WorkflowEvent::count());
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_SUBMITTED)->count());
    }

    public function test_only_a_family_context_with_the_submit_permission_may_submit(): void
    {
        $context = $this->headContext();

        // A Staff account cannot impersonate a family submission.
        $staffContext = FamilyAccessResult::identity($this->staff('ADMINISTRATOR'), $context->link, $context->person, $context->authIdentity)
            ->withFamily($context->membership, $context->family);
        $this->refusedWith(fn () => $this->submit($staffContext), ChangeRequestException::ACTOR_NOT_ALLOWED);

        // Identity without a resolved Family is not a family context.
        $identityOnly = FamilyAccessResult::identity($context->user, $context->link, $context->person, $context->authIdentity);
        $this->refusedWith(fn () => $this->submit($identityOnly), ChangeRequestException::ACTOR_NOT_ALLOWED);

        // A family account without change-request.submit.
        $context->user->syncRoles([]);
        $context->user->assignRole('COORDINATOR');
        $this->refusedWith(fn () => $this->submit($context), ChangeRequestException::ACTOR_NOT_ALLOWED);

        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_exception_messages_are_fixed_and_carry_no_values(): void
    {
        $context = $this->headContext();
        $this->submit($context, ['paper_form_no' => 'PF-SECRET-0590000000']);

        try {
            $this->submit($context, ['paper_form_no' => 'PF-SECRET-0590000000']);
        } catch (Throwable $e) {
            $this->assertStringNotContainsString('0590000000', $e->getMessage());
            $this->assertStringNotContainsString((string) $context->family->id, $e->getMessage());
        }
    }
}
