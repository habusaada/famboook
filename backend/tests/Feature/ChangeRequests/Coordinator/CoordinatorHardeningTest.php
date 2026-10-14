<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Actions\ChangeRequests\RejectChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\ChangeRequestType;
use App\Enums\FamilyActivityType;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\FamilyActivity;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\ChangeRequests\CoordinatorRequestFixtures;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.7b: Staff close a returned request (RETURNED_FOR_CLARIFICATION →
 * REJECTED), the household sees who submitted a Coordinator's request, the
 * Coordinator Space reads are throttled, and the Staff queue filters by
 * channel and submitter. Synthetic data only.
 */
class CoordinatorHardeningTest extends TestCase
{
    use CoordinatorFixtures, CoordinatorRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const STAFF = '/api/v1/change-requests';

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoordinatorRequests();
        $this->reviewer = $this->staffUser('REVIEWER');
    }

    private function staffUser(string $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName($role, 'web')));
    }

    private function returned(ChangeRequest $request): ChangeRequest
    {
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ReturnChangeRequestForClarificationAction::class)->handle($request, $this->reviewer, 'أرفق ما يثبت العلاقة.');

        return $request->fresh();
    }

    private function familyRequest(): ChangeRequest
    {
        return app(SubmitChangeRequestAction::class)->handle($this->household, new ChangeRequestSubmission(
            ChangeRequestType::ADD_FAMILY_MEMBER, $this->member(['national_id' => '402345678']), null, (string) Str::uuid(),
        ))->request;
    }

    private function reject(ChangeRequest $request, array $body, ?User $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->reviewer)->fresh())->postJson(self::STAFF.'/'.$request->uuid.'/reject', $body);
    }

    // --------------------------------------------- RETURNED → REJECTED

    public function test_staff_reject_a_returned_request_of_either_channel_with_a_recorded_reason(): void
    {
        foreach (['COORDINATOR' => $this->returned($this->coordinatorRequest()), 'FAMILY' => $this->returned($this->familyRequest())] as $channel => $request) {
            $this->actingAs($this->reviewer->fresh())->getJson(self::STAFF.'/'.$request->uuid)->assertOk()
                ->assertJsonPath('data.available_actions', ['reject']);

            $this->reject($request, ['rejection_reason_code' => 'INSUFFICIENT_INFORMATION', 'public_message' => 'لم يصل الاستكمال.', 'internal_note' => 'انتهت المهلة'])
                ->assertOk()->assertJsonPath('data.status', 'REJECTED');

            $request->refresh();
            $this->assertSame([S::REJECTED, ChangeRequestRejectionReason::INSUFFICIENT_INFORMATION, $this->reviewer->id, 'لم يصل الاستكمال.'],
                [$request->status, $request->rejection_reason_code, $request->rejected_by, $request->rejection_reason], $channel);
            $this->assertNotNull($request->rejected_at);
            $event = $request->workflowEvents()->get()->last();
            $this->assertSame([WorkflowEventType::REJECTED, S::RETURNED_FOR_CLARIFICATION, S::REJECTED, 'STAFF', $this->reviewer->id, 'INSUFFICIENT_INFORMATION', 'انتهت المهلة'],
                [$event->event_type, $event->from_status, $event->to_status, $event->actor_side->value, $event->actor_user_id, $event->reason_code, $event->internal_note], $channel);
            $this->assertSame(1, FamilyActivity::where('subject_id', $request->id)->where('event_type', FamilyActivityType::CHANGE_REQUEST_REJECTED)->count(), $channel);
        }
    }

    public function test_a_returned_proposal_without_a_family_is_rejected_with_no_family_activity(): void
    {
        $proposal = $this->returned($this->proposedFamily());
        $this->reject($proposal, ['rejection_reason_code' => 'CANNOT_VERIFY'])->assertOk();
        $this->assertSame(S::REJECTED, $proposal->fresh()->status);
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_REJECTED)->count());
    }

    public function test_the_reason_is_mandatory(): void
    {
        $request = $this->returned($this->coordinatorRequest());
        $this->reject($request, [])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason_code');
        $this->reject($request, ['rejection_reason_code' => 'NOT_A_REASON'])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason_code');
        $this->reject($request, ['rejection_reason_code' => 'OTHER'])->assertUnprocessable()->assertJsonValidationErrors('public_message');
        $this->reject($request, ['rejection_reason_code' => 'OTHER', 'public_message' => '   '])->assertUnprocessable();
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $request->fresh()->status);
    }

    public function test_only_staff_with_the_reject_permission_may_do_it(): void
    {
        $request = $this->returned($this->coordinatorRequest());

        $this->reject($request, ['rejection_reason_code' => 'CANNOT_VERIFY'], $this->staffUser('DATA_ENTRY'))->assertForbidden();
        $this->reject($request, ['rejection_reason_code' => 'CANNOT_VERIFY'], $this->coordinatorUser)->assertForbidden();
        $this->reject($request, ['rejection_reason_code' => 'CANNOT_VERIFY'], $this->household->user)->assertForbidden();
        foreach ([$this->coordinatorUser, $this->household->user] as $user) {
            try {
                app(RejectChangeRequestAction::class)->handle($request, $user->fresh(), ChangeRequestRejectionReason::CANNOT_VERIFY);
                $this->fail('A family-side account rejected a request.');
            } catch (ChangeRequestException $e) {
                $this->assertSame(ChangeRequestException::ACTOR_NOT_ALLOWED, $e->reason);
            }
        }
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $request->fresh()->status);
    }

    public function test_after_the_rejection_nobody_answers_or_cancels_it_and_a_replay_writes_nothing(): void
    {
        $request = $this->returned($this->coordinatorRequest());
        $this->reject($request, ['rejection_reason_code' => 'CANNOT_VERIFY'])->assertOk()->assertJsonPath('data.replayed', false);
        $this->reject($request, ['rejection_reason_code' => 'CANNOT_VERIFY'])->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, $request->workflowEvents()->where('event_type', WorkflowEventType::REJECTED)->count());

        $this->actingAs($this->coordinatorUser->fresh())->postJson('/api/v1/family/coordinator/requests/'.$request->uuid.'/resubmit', ['response' => 'رد'])
            ->assertStatus(409)->assertJsonPath('code', ChangeRequestException::INVALID_TRANSITION);
        $this->actingAs($this->coordinatorUser->fresh())->postJson('/api/v1/family/coordinator/requests/'.$request->uuid.'/cancel')
            ->assertStatus(409);
    }

    public function test_the_other_rejections_are_unchanged(): void
    {
        // SUBMITTED and RESUBMITTED are not rejectable without a review.
        $submitted = $this->coordinatorRequest();
        $this->reject($submitted, ['rejection_reason_code' => 'CANNOT_VERIFY'])->assertStatus(409);
        $this->assertSame(S::SUBMITTED, $submitted->fresh()->status);
    }

    // ------------------------------------- the Coordinator in the household history

    public function test_the_household_sees_the_coordinators_name_and_nothing_else_about_them(): void
    {
        $coordinatorRequest = $this->coordinatorRequest();
        $ownRequest = $this->familyRequest();
        $coordinatorPerson = app(FamilyAccessResolver::class)->familyContext($this->coordinatorUser)->person;
        $coordinatorPerson->forceFill(['mobile' => '0599888777'])->save();
        $this->actingAs($this->household->user->fresh());

        $list = $this->getJson('/api/v1/family/change-requests')->assertOk();
        $rows = collect($list->json('data'))->keyBy('id');
        $this->assertSame(['name' => $coordinatorPerson->full_name], $rows[$coordinatorRequest->uuid]['coordinator']);
        $this->assertNull($rows[$ownRequest->uuid]['coordinator']);
        $this->assertSame([], $rows[$coordinatorRequest->uuid]['available_actions']);

        $detail = $this->getJson('/api/v1/family/change-requests/'.$coordinatorRequest->uuid)->assertOk()
            ->assertJsonPath('data.coordinator', ['name' => $coordinatorPerson->full_name]);
        foreach ([$list->getContent(), $detail->getContent()] as $json) {
            foreach (['111111110', '0599888777', $this->coordinatorUser->email ?? '@', '"submitted_by"', '"user_id"', 'scope'] as $secret) {
                $this->assertStringNotContainsString($secret, $json, $secret);
            }
        }
    }

    public function test_another_household_never_sees_the_request(): void
    {
        $request = $this->coordinatorRequest();
        $other = $this->householdIn($this->scopeClan, '933456789');
        $this->actingAs($other->user->fresh());
        $this->getJson('/api/v1/family/change-requests/'.$request->uuid)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/family/change-requests')->assertOk()->json('data'));
    }

    // ---------------------------------------------- Coordinator Space read throttles

    public function test_the_coordinator_space_reads_are_throttled_per_user(): void
    {
        config(['family_auth.coordinator_read.limits.user_minute' => 2]);
        $code = $this->household->family->family_code;
        $this->actingAs($this->coordinatorUser->fresh());

        $this->getJson('/api/v1/family/coordinator/context')->assertOk();
        $this->getJson('/api/v1/family/coordinator/families')->assertOk();
        $this->getJson('/api/v1/family/coordinator/families/'.$code)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->getJson('/api/v1/family/coordinator/context')->assertStatus(429);

        $colleague = $this->coordinator('921111110');
        $this->assign($colleague, $this->scopeClan);
        $this->actingAs($colleague->fresh())->getJson('/api/v1/family/coordinator/context')->assertOk();
    }

    // ---------------------------------------------------------- Staff queue filters

    public function test_the_staff_queue_filters_by_channel_and_submitter(): void
    {
        $coordinatorRequest = $this->coordinatorRequest();
        $proposal = $this->proposedFamily();
        $ownRequest = $this->familyRequest();
        $coordinatorName = app(FamilyAccessResolver::class)->familyContext($this->coordinatorUser)->person->full_name;
        $this->actingAs($this->reviewer->fresh());

        $ids = fn (string $query) => collect($this->getJson(self::STAFF.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$coordinatorRequest->uuid, $proposal->uuid])->sort()->values()->all(), $ids('?channel=COORDINATOR'));
        $this->assertSame([$ownRequest->uuid], $ids('?channel=FAMILY'));
        $this->assertSame(collect([$coordinatorRequest->uuid, $proposal->uuid])->sort()->values()->all(), $ids('?submitter='.urlencode(mb_substr($coordinatorName, 0, 4))));
        $this->assertSame([], $ids('?submitter='.urlencode('اسم غير موجود')));
        $this->assertSame([$proposal->uuid], $ids('?channel=COORDINATOR&type=REGISTER_FAMILY'));

        $this->getJson(self::STAFF.'?channel=STAFF')->assertUnprocessable()->assertJsonValidationErrors('channel');
        $this->getJson(self::STAFF.'?submitter=x')->assertUnprocessable()->assertJsonValidationErrors('submitter');
        // LIKE wildcards are literal.
        $this->assertSame([], $ids('?submitter='.urlencode('%%')));
    }

    public function test_a_proposal_is_found_by_the_family_it_created_once_applied(): void
    {
        $proposal = $this->proposedFamily();
        $created = $this->familyIn($this->scopeClan);
        $proposal->forceFill([
            'status' => 'APPLIED', 'created_family_id' => $created->id, 'reviewed_by' => $this->reviewer->id, 'reviewed_at' => now(),
            'approved_by' => $this->reviewer->id, 'approved_at' => now(), 'applied_by' => $this->reviewer->id, 'applied_at' => now(),
        ])->saveQuietly();

        $this->actingAs($this->reviewer->fresh())->getJson(self::STAFF.'?family='.$created->family_code)->assertOk()
            ->assertJsonPath('data.0.id', $proposal->uuid)
            ->assertJsonPath('data.0.created_family_code', $created->family_code)
            ->assertJsonPath('data.0.family', null);
    }
}
