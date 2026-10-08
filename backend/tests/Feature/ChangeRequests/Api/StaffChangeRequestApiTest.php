<?php

namespace Tests\Feature\ChangeRequests\Api;

use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\FamilyActivityType;
use App\Enums\WorkflowEventType;
use App\Models\ChangeRequest;
use App\Models\FamilyActivity;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\ChangeRequests\FakeChangeRequestHandler;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * The Staff Change Request API (PWA-5c) over the PWA-5b engine, with the
 * test-only handler for OTHER. Synthetic data only.
 */
class StaffChangeRequestApiTest extends TestCase
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

    private function api(?User $as, string $method, string $uri, array $body = []): TestResponse
    {
        $request = $as ? $this->actingAs($as) : $this;

        return $request->json($method, '/api/v1/change-requests'.$uri, $body);
    }

    private function act(ChangeRequest $request, string $action, array $body = [], ?User $as = null): TestResponse
    {
        return $this->api($as ?? $this->reviewer, 'POST', "/{$request->uuid}/{$action}", $body);
    }

    private function underReview(string $value = 'PF-NEW'): ChangeRequest
    {
        $request = $this->submit($this->context, ['paper_form_no' => $value]);
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);

        return $request->fresh();
    }

    private function approved(string $value = 'PF-NEW'): ChangeRequest
    {
        $request = $this->underReview($value);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);

        return $request->fresh();
    }

    // ---------------------------------------------------------------- authorization

    public function test_access_matrix(): void
    {
        $request = $this->submit($this->context);
        $routes = [
            ['GET', ''], ['GET', "/{$request->uuid}"], ['POST', "/{$request->uuid}/start-review"], ['POST', "/{$request->uuid}/return"],
            ['POST', "/{$request->uuid}/approve"], ['POST', "/{$request->uuid}/reject"], ['POST', "/{$request->uuid}/apply"],
        ];

        foreach ($routes as [$method, $uri]) {
            $this->json($method, '/api/v1/change-requests'.$uri)->assertUnauthorized();
        }

        $coordinator = $this->headContext('923456789', ['FAMILY_USER', 'COORDINATOR'])->user;
        foreach ([$this->context->user, $coordinator, $this->staff('DATA_ENTRY'), $this->staff('SOCIAL_WORKER'), $this->staff('REPORTS_VIEWER')] as $user) {
            foreach ($routes as [$method, $uri]) {
                $this->api($user, $method, $uri)->assertForbidden();
            }
        }

        foreach (['SUPER_ADMIN', 'ADMINISTRATOR', 'REVIEWER'] as $role) {
            $staff = $this->staff($role);
            $this->api($staff, 'GET', '')->assertOk();
            $this->api($staff, 'GET', "/{$request->uuid}")->assertOk();
        }
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }

    public function test_inactive_staff_are_refused_before_the_route(): void
    {
        $request = $this->submit($this->context);
        $inactive = $this->staff();
        $inactive->forceFill(['is_active' => false])->save();

        // The authentication layer refuses a deactivated account outright; the
        // Domain Action would refuse it as well (ChangeRequestActors).
        $this->act($request, 'start-review', as: $inactive)->assertUnauthorized();
        $this->assertSame(S::SUBMITTED, $request->fresh()->status);
    }

    // One request per test: EnsureUserIsActive logs the web guard out, and the
    // test harness reuses one application across requests.
    public function test_inactive_staff_cannot_read_the_queue(): void
    {
        $inactive = $this->staff();
        $inactive->forceFill(['is_active' => false])->save();

        $this->api($inactive, 'GET', '')->assertUnauthorized();
    }

    public function test_unknown_and_malformed_identifiers_are_404_and_ids_are_never_routes(): void
    {
        $request = $this->submit($this->context);

        $this->api($this->reviewer, 'GET', '/'.Str::uuid())->assertNotFound();
        $this->api($this->reviewer, 'GET', '/not-a-uuid')->assertNotFound();
        $this->api($this->reviewer, 'GET', '/'.$request->id)->assertNotFound();
        $this->api($this->reviewer, 'GET', '/'.$request->request_code)->assertNotFound();
        $this->api($this->reviewer, 'POST', '/'.Str::uuid().'/approve')->assertNotFound();
    }

    // ---------------------------------------------------------------- index

    public function test_the_index_is_paginated_newest_first_and_exposes_no_proposal(): void
    {
        FakeChangeRequestHandler::$conflictKeys = false;
        $old = $this->submit($this->context, ['paper_form_no' => 'PF-SECRET-A']);
        $old->forceFill(['submitted_at' => now()->subDays(3)])->saveQuietly();
        $new = $this->submit($this->context, ['paper_form_no' => 'PF-SECRET-B'], reason: 'سبب سري');

        $response = $this->api($this->reviewer, 'GET', '?per_page=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('data.0.id', $new->uuid)->assertJsonPath('data.0.request_code', $new->request_code);
        $response->assertJsonPath('data.0.family.family_code', $this->context->family->family_code);
        $response->assertJsonPath('data.0.type_available', true);
        $response->assertJsonPath('data.0.available_actions', ['start_review']);
        $json = $response->getContent();
        foreach (['PF-SECRET', 'سبب سري', 'submitted_data', 'base_fingerprint', 'client_reference', '"workflowable'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
        $this->assertArrayNotHasKey('timeline', $response->json('data.0'));

        $this->api($this->reviewer, 'GET', '?per_page=1&page=2')->assertJsonPath('data.0.id', $old->uuid);
    }

    public function test_the_index_filters(): void
    {
        FakeChangeRequestHandler::$conflictKeys = false;
        $a = $this->submit($this->context, ['paper_form_no' => 'PF-A']);
        $b = $this->underReview('PF-B');
        $b->forceFill(['submitted_at' => now()->subDays(10)])->saveQuietly();
        $other = ChangeRequest::factory()->create(['type' => 'OTHER']);

        $ids = fn (string $query) => collect($this->api($this->reviewer, 'GET', $query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$b->uuid], $ids('?status=UNDER_REVIEW'));
        $this->assertSame(collect([$a->uuid, $b->uuid])->sort()->values()->all(), $ids('?family='.$this->context->family->family_code));
        $this->assertSame([$other->uuid], $ids('?family='.$other->family->family_code));
        $this->assertSame([$a->uuid], $ids('?request_code='.$a->request_code));
        $this->assertSame([], $ids('?type=RESIDENCE_UPDATE'));
        $this->assertCount(3, $ids('?type=OTHER'));
        $this->assertSame([$b->uuid], $ids('?submitted_to='.now()->subDays(5)->toDateString()));
        $this->assertNotContains($b->uuid, $ids('?submitted_from='.now()->subDays(5)->toDateString()));

        foreach (['?status=PENDING', '?type=FAMILY_DATA_UPDATE', '?request_code=1', '?submitted_from=yesterday',
            '?submitted_from=2026-10-08&submitted_to=2026-10-01', '?per_page=500', '?per_page=0'] as $bad) {
            $this->api($this->reviewer, 'GET', $bad)->assertUnprocessable();
        }
    }

    public function test_the_index_does_not_issue_a_query_per_row(): void
    {
        FakeChangeRequestHandler::$conflictKeys = false;
        foreach (range(1, 3) as $i) {
            $this->submit($this->context, ['paper_form_no' => 'PF-'.$i]);
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->api($this->reviewer, 'GET', '')->assertOk();

            return count(DB::getQueryLog());
        };
        $count(); // warm-up: the reviewer's roles and permissions load once
        $three = $count();
        foreach (range(4, 9) as $i) {
            $this->submit($this->context, ['paper_form_no' => 'PF-'.$i]);
        }
        $this->assertSame($three, $count(), 'The query count must not grow with the rows.');
    }

    // ---------------------------------------------------------------- detail

    public function test_the_detail_shows_the_handler_presentation_and_an_ordered_timeline(): void
    {
        $request = $this->underReview();
        $this->act($request, 'return', ['public_message' => 'وضّح الرقم', 'internal_note' => 'ملاحظة داخلية'])->assertOk();

        $response = $this->api($this->reviewer, 'GET', "/{$request->uuid}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $response->assertJsonPath('data.id', $request->uuid)
            ->assertJsonPath('data.type', 'OTHER')
            ->assertJsonPath('data.type_available', true)
            ->assertJsonPath('data.status', 'RETURNED_FOR_CLARIFICATION')
            ->assertJsonPath('data.presentation', ['rows' => [['label' => 'رقم الاستمارة', 'current' => 'PF-OLD', 'proposed' => 'PF-NEW']]])
            ->assertJsonPath('data.submitted_by.name', $this->context->person->full_name)
            ->assertJsonPath('data.review.reviewed_by.name', $this->reviewer->name);
        $this->assertSame(['SUBMITTED', 'REVIEW_STARTED', 'RETURNED'], collect($response->json('data.timeline'))->pluck('event_type')->all());
        $returned = $response->json('data.timeline.2');
        $this->assertSame('وضّح الرقم', $returned['public_message']);
        $this->assertSame('ملاحظة داخلية', $returned['internal_note']);
        $this->assertSame(['name' => $this->reviewer->name], $returned['actor']);

        $json = $response->getContent();
        foreach (['submitted_data', 'base_fingerprint', 'base_key_version', 'client_reference', $request->base_fingerprint, '"metadata"'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
        $this->assertArrayNotHasKey('family_id', $response->json('data'));
    }

    public function test_internal_notes_are_shown_only_with_the_permission(): void
    {
        $request = $this->underReview();
        $this->act($request, 'return', ['public_message' => 'وضّح', 'internal_note' => 'سرّي للموظفين'])->assertOk();

        // A Staff user holding change-request.view but not view-internal-notes.
        $limited = User::factory()->create();
        $limited->assignRole('REPORTS_VIEWER');
        $limited->givePermissionTo('change-request.view');

        $response = $this->api($limited, 'GET', "/{$request->uuid}")->assertOk();
        $this->assertArrayNotHasKey('internal_note', $response->json('data.timeline.2'));
        $this->assertStringNotContainsString('سرّي للموظفين', $response->getContent());
        $this->assertSame('وضّح', $response->json('data.timeline.2.public_message'));
    }

    public function test_the_detail_of_an_unregistered_type_is_safe(): void
    {
        $orphan = ChangeRequest::factory()->underReview()->create([
            'type' => 'RESIDENCE_UPDATE', 'submitted_data' => ['governorate' => 'SECRET-GOV', 'national_id' => '000000001'],
        ]);

        $response = $this->api($this->reviewer, 'GET', "/{$orphan->uuid}")->assertOk();
        $response->assertJsonPath('data.type_available', false)->assertJsonPath('data.presentation', null);
        $this->assertStringNotContainsString('SECRET-GOV', $response->getContent());
        $this->assertStringNotContainsString('000000001', $response->getContent());
        $this->assertNotContains('approve', $response->json('data.available_actions'));

        $this->act($orphan, 'approve')->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_TYPE_UNAVAILABLE');
        $this->assertSame(S::UNDER_REVIEW, $orphan->fresh()->status);
    }

    // ---------------------------------------------------------------- transitions

    public function test_start_review_then_replay_then_a_competing_reviewer_conflict(): void
    {
        $request = $this->submit($this->context);

        $this->act($request, 'start-review')->assertOk()
            ->assertJson(['data' => ['id' => $request->uuid, 'request_code' => $request->request_code, 'status' => 'UNDER_REVIEW', 'replayed' => false]])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->act($request, 'start-review')->assertOk()->assertJsonPath('data.replayed', true);
        $this->act($request, 'start-review', as: $this->staff('ADMINISTRATOR'))
            ->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_INVALID_TRANSITION');
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::REVIEW_STARTED)->count());
    }

    public function test_return_validates_its_messages(): void
    {
        $request = $this->underReview();

        $this->act($request, 'return', [])->assertUnprocessable()->assertJsonValidationErrors('public_message');
        $this->act($request, 'return', ['public_message' => "\u{200F}\u{202E}"])->assertUnprocessable()->assertJsonValidationErrors('public_message');
        $this->act($request, 'return', ['public_message' => str_repeat('م', 2001)])->assertUnprocessable()->assertJsonValidationErrors('public_message');
        $this->act($request, 'return', ['public_message' => 'وضّح', 'internal_note' => str_repeat('م', 2001)])->assertUnprocessable()->assertJsonValidationErrors('internal_note');
        $this->act($request, 'return', ['public_message' => ['array']])->assertUnprocessable();
        $this->assertSame(S::UNDER_REVIEW, $request->fresh()->status);

        $this->act($request, 'return', ['public_message' => "<script>alert(1)</script>\u{202E}وضّح"])->assertOk()->assertJsonPath('data.status', 'RETURNED_FOR_CLARIFICATION');
        // Stored as plain text (rendered as text by the client), bidi override removed.
        $this->assertSame('<script>alert(1)</script>وضّح', WorkflowEvent::where('event_type', WorkflowEventType::RETURNED)->sole()->public_message);
        $this->assertSame(0, FamilyActivity::where('event_type', 'like', 'CHANGE_REQUEST_%')->where('event_type', '!=', 'CHANGE_REQUEST_SUBMITTED')->count());
    }

    public function test_approve_changes_nothing_canonical_and_refuses_a_stale_base(): void
    {
        $request = $this->underReview();
        $this->act($request, 'approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertSame('PF-OLD', $this->context->family->fresh()->paper_form_no);
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());

        FakeChangeRequestHandler::$conflictKeys = false;
        $stale = $this->underReview('PF-STALE');
        $this->context->family->forceFill(['paper_form_no' => 'PF-CHANGED'])->save();
        $response = $this->act($stale, 'approve')->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertStringNotContainsString('PF-CHANGED', $response->getContent());
        $this->assertStringNotContainsString((string) $stale->base_fingerprint, $response->getContent());
    }

    public function test_reject_and_the_approved_rejection_guard(): void
    {
        $request = $this->underReview();
        $this->act($request, 'reject', ['rejection_reason_code' => 'NOT_A_REASON'])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason_code');
        $this->act($request, 'reject', ['rejection_reason_code' => 'OTHER'])->assertUnprocessable()->assertJsonValidationErrors('public_message');
        $this->act($request, 'reject', ['rejection_reason_code' => 'CANNOT_VERIFY', 'public_message' => 'تعذّر التحقق', 'internal_note' => 'داخلي'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_REJECTED)->count());

        // An approved request: no generic undo; NO_LONGER_APPLICABLE only after a refused apply.
        FakeChangeRequestHandler::$conflictKeys = false;
        $approved = $this->approved('PF-2');
        $this->act($approved, 'reject', ['rejection_reason_code' => 'NO_LONGER_APPLICABLE'])->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_INVALID_TRANSITION');
        $this->assertNotContains('reject', $this->api($this->reviewer, 'GET', "/{$approved->uuid}")->json('data.available_actions'));

        FakeChangeRequestHandler::$preconditionFails = true;
        $this->act($approved, 'apply')->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_PRECONDITION_FAILED');
        $this->assertContains('reject', $this->api($this->reviewer, 'GET', "/{$approved->uuid}")->json('data.available_actions'));
        $this->act($approved, 'reject', ['rejection_reason_code' => 'DATA_CHANGED', 'public_message' => 'x'])->assertStatus(409);
        $this->act($approved, 'reject', ['rejection_reason_code' => 'NO_LONGER_APPLICABLE'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    }

    // ---------------------------------------------------------------- apply

    public function test_apply_success_and_duplicate_replay(): void
    {
        $request = $this->approved();

        $this->act($request, 'apply')->assertOk()->assertJson(['data' => ['status' => 'APPLIED', 'replayed' => false]]);
        $this->act($request, 'apply')->assertOk()->assertJson(['data' => ['status' => 'APPLIED', 'replayed' => true]]);

        $this->assertSame('PF-NEW', $this->context->family->fresh()->paper_form_no);
        $this->assertSame(1, FakeChangeRequestHandler::$applied);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_UPDATED)->count());
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());
    }

    public function test_an_unexpected_apply_failure_is_a_safe_500_that_keeps_its_failure_record_and_retries_once(): void
    {
        $request = $this->approved('PF-SECRET-0590000000');
        FakeChangeRequestHandler::$failAfterWrite = 'unexpected';

        $response = $this->act($request, 'apply')->assertStatus(500)->assertJsonPath('code', 'CHANGE_REQUEST_APPLY_FAILED');
        $this->assertSame(['message', 'code'], array_keys($response->json()));
        foreach (['0590000000', 'SQLSTATE', 'RuntimeException', 'trace'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }

        // Rolled back; the request stays APPROVED; the failure record survived the HTTP call.
        $this->assertSame('PF-OLD', $this->context->family->fresh()->paper_form_no);
        $fresh = $request->fresh();
        $this->assertSame(S::APPROVED, $fresh->status);
        $this->assertSame(1, $fresh->apply_failure_count);
        $this->assertSame('APPLY_FAILED', WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->sole()->reason_code);
        $this->assertSame(0, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());
        $this->assertSame(0, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_UPDATED)->count());

        FakeChangeRequestHandler::$failAfterWrite = null;
        $this->act($request, 'apply')->assertOk()->assertJsonPath('data.status', 'APPLIED');
        $this->assertSame('PF-SECRET-0590000000', $this->context->family->fresh()->paper_form_no);
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_APPLIED)->count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::FAMILY_UPDATED)->count());
        $this->assertSame(1, WorkflowEvent::where('event_type', WorkflowEventType::APPLIED)->count());
    }

    public function test_an_expected_apply_refusal_keeps_its_code_and_is_not_a_500(): void
    {
        $request = $this->approved();
        $this->context->family->forceFill(['paper_form_no' => 'PF-NEWER'])->save();

        $this->act($request, 'apply')->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_BASE_CHANGED');
        $this->assertSame('PF-NEWER', $this->context->family->fresh()->paper_form_no);
        $this->assertSame('BASE_CHANGED', WorkflowEvent::where('event_type', WorkflowEventType::APPLY_FAILED)->sole()->reason_code);

        FakeChangeRequestHandler::$conflictKeys = false;
        $notYet = $this->underReview('PF-3');
        $this->act($notYet, 'apply')->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_INVALID_TRANSITION');
        $this->assertSame(0, $notYet->fresh()->apply_failure_count);
    }

    public function test_no_endpoint_accepts_status_family_or_actor_overrides(): void
    {
        $request = $this->underReview();
        $other = ChangeRequest::factory()->create(['type' => 'OTHER']);

        $this->act($request, 'approve', [
            'status' => 'APPLIED', 'family_id' => $other->family_id, 'approved_by' => $this->context->user->id, 'applied_by' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $fresh = $request->fresh();
        $this->assertSame(S::APPROVED, $fresh->status);
        $this->assertSame($this->context->family->id, $fresh->family_id);
        $this->assertSame($this->reviewer->id, $fresh->approved_by);
        $this->assertNull($fresh->applied_by);
        $this->assertSame(S::SUBMITTED, $other->fresh()->status);
    }
}
