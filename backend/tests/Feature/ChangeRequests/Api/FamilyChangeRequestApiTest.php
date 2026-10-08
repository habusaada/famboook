<?php

namespace Tests\Feature\ChangeRequests\Api;

use App\Actions\ChangeRequests\ApplyChangeRequestAction;
use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\RejectChangeRequestAction;
use App\Actions\ChangeRequests\ResubmitChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Enums\AuthIdentityStatus;
use App\Enums\ChangeRequestRejectionReason;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\FamilyActivityType;
use App\Enums\FamilyStatus;
use App\Enums\FingerprintContext;
use App\Enums\LifeStatus;
use App\Enums\UserPersonLinkStatus;
use App\Enums\WorkflowEventType;
use App\Exceptions\ChangeRequestException;
use App\Http\Middleware\EnsureFamilyContext;
use App\Http\Middleware\EnsureFamilySideAccount;
use App\Models\ChangeRequest;
use App\Models\Family;
use App\Models\FamilyActivity;
use App\Models\FamilyAuthIdentity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestTypes;
use App\Support\FamilyAuth\FamilyAccessResolver;
use App\Support\FamilyAuth\FamilyAccessResult;
use App\Support\FamilyAuth\KeyedFingerprint;
use App\Support\FamilyPortal\HouseholdMemberReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\ChangeRequests\FakeChangeRequestHandler;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * The Family Change Request API (PWA-5e) through the real family.side /
 * family.context boundary, with the test-only handler for OTHER. Synthetic
 * data only.
 */
class FamilyChangeRequestApiTest extends TestCase
{
    use ChangeRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/change-requests';

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

    private function api(User $as, string $method, string $uri = '', array $body = []): TestResponse
    {
        return $this->actingAs($as->fresh())->json($method, self::URI.$uri, $body);
    }

    private function body(array $overrides = []): array
    {
        return ['type' => 'OTHER', 'client_reference' => (string) Str::uuid(), 'data' => ['paper_form_no' => 'PF-NEW'], ...$overrides];
    }

    private function returned(?FamilyAccessResult $context = null): ChangeRequest
    {
        $request = $this->submit($context ?? $this->context);
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ReturnChangeRequestForClarificationAction::class)->handle($request, $this->reviewer, 'وضّح الرقم', 'ملاحظة داخلية سرية');

        return $request->fresh();
    }

    /** A new eligible head of an EXISTING Family (the previous head steps down). */
    private function newHeadOf(Family $family, string $nationalId): FamilyAccessResult
    {
        FamilyMembership::query()->where('family_id', $family->id)->update(['is_household_head' => false]);
        $person = Person::factory()->create(['national_id' => $nationalId]);
        FamilyMembership::factory()->create(['family_id' => $family->id, 'person_id' => $person->id, 'is_household_head' => true]);
        $user = $this->familyUser();
        UserPersonLink::factory()->create(['user_id' => $user->id, 'person_id' => $person->id]);
        FamilyAuthIdentity::factory()->create([
            'user_id' => $user->id, 'login_key' => KeyedFingerprint::of(FingerprintContext::LOGIN_ID, $nationalId),
            'key_version' => 1, 'status' => AuthIdentityStatus::ACTIVE->value,
        ]);

        return app(FamilyAccessResolver::class)->familyContext($user);
    }

    // ---------------------------------------------------------------- boundary

    public function test_guests_inactive_staff_coordinator_only_and_mixed_accounts_are_refused(): void
    {
        $this->json('GET', self::URI)->assertUnauthorized();

        $accounts = [
            'staff' => $this->staff('SUPER_ADMIN'),
            'coordinator only' => $this->familyUser(['COORDINATOR']),
        ];
        $mixed = $this->activatedHead('222222222')['user'];
        $mixed->assignRole('ADMINISTRATOR');
        $accounts['mixed'] = $mixed;
        foreach ($accounts as $label => $user) {
            $this->api($user, 'GET')->assertForbidden()->assertExactJson(['message' => EnsureFamilySideAccount::MESSAGE]);
        }
    }

    public function test_a_deactivated_account_gets_401(): void
    {
        $this->context->user->forceFill(['is_active' => false])->save();

        $this->api($this->context->user, 'GET')->assertUnauthorized();
    }

    public function test_an_ended_link_a_deceased_head_or_an_inactive_family_has_no_family_context(): void
    {
        $refused = fn (FamilyAccessResult $context) => $this->api($context->user, 'GET')->assertForbidden()
            ->assertExactJson(['message' => EnsureFamilyContext::MESSAGE, 'code' => EnsureFamilyContext::CODE]);

        $a = $this->headContext('323456789');
        $a->link->forceFill(['status' => UserPersonLinkStatus::SUSPENDED->value, 'suspended_at' => now()])->save();
        $refused($a);

        $b = $this->headContext('423456789');
        $b->person->forceFill(['life_status' => LifeStatus::DECEASED->value, 'death_date' => '2026-10-01'])->save();
        $refused($b);

        $c = $this->headContext('523456789');
        $c->family->forceFill(['status' => FamilyStatus::INACTIVE])->save();
        $refused($c);
    }

    public function test_a_family_user_who_is_also_a_coordinator_sees_only_their_own_family(): void
    {
        $mine = $this->submit($this->context);
        $coordinator = $this->headContext('623456789', ['FAMILY_USER', 'COORDINATOR']);
        $theirs = $this->submit($coordinator);

        $ids = collect($this->api($coordinator->user, 'GET')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$theirs->uuid], $ids);
        $this->api($coordinator->user, 'GET', "/{$mine->uuid}")->assertNotFound();
    }

    // ---------------------------------------------------------------- history

    public function test_the_history_is_family_wide_newest_first_and_safe(): void
    {
        FakeChangeRequestHandler::$conflictKeys = false;
        $older = $this->submit($this->context, ['paper_form_no' => 'PF-SECRET-A']);
        $older->forceFill(['submitted_at' => now()->subDay()])->saveQuietly();
        $newer = $this->submit($this->context, ['paper_form_no' => 'PF-SECRET-B'], reason: 'سبب');
        $this->submit($this->headContext('723456789')); // another Family

        $response = $this->api($this->context->user, 'GET', '?per_page=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $newer->uuid)
            ->assertJsonPath('data.0.request_code', $newer->request_code)
            ->assertJsonPath('data.0.status', 'SUBMITTED')
            ->assertJsonPath('data.0.type_available', true)
            ->assertJsonPath('data.0.available_actions', ['cancel']);
        $this->api($this->context->user, 'GET', '?per_page=1&page=2')->assertJsonPath('data.0.id', $older->uuid);

        $json = $response->getContent();
        foreach (['PF-SECRET', 'submitted_data', 'base_fingerprint', 'client_reference', 'family_id', '"submitted_by'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }

        $this->assertCount(0, $this->api($this->context->user, 'GET', '?status=APPLIED')->json('data'));
        $this->assertCount(2, $this->api($this->context->user, 'GET', '?type=OTHER')->json('data'));
        $this->api($this->context->user, 'GET', '?status=PENDING')->assertUnprocessable();
        $this->api($this->context->user, 'GET', '?per_page=500')->assertUnprocessable();
    }

    public function test_a_new_head_sees_and_can_cancel_the_previous_heads_open_request(): void
    {
        $previous = $this->submit($this->context);
        $current = $this->newHeadOf($this->context->family, '823456789');
        $this->assertTrue($current->hasFamilyContext());

        $this->assertSame([$previous->uuid], collect($this->api($current->user, 'GET')->assertOk()->json('data'))->pluck('id')->all());
        $this->api($current->user, 'GET', "/{$previous->uuid}")->assertOk();
        $this->api($current->user, 'POST', "/{$previous->uuid}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame($current->user->id, $previous->fresh()->cancelled_by);
    }

    public function test_another_familys_request_is_not_found_for_reading_and_every_mutation(): void
    {
        $foreign = $this->returned($this->headContext('923456789'));

        foreach ([['GET', "/{$foreign->uuid}"], ['POST', "/{$foreign->uuid}/cancel"]] as [$method, $uri]) {
            $this->api($this->context->user, $method, $uri)->assertNotFound()->assertJsonPath('code', 'CHANGE_REQUEST_NOT_FOUND');
        }
        $this->api($this->context->user, 'POST', "/{$foreign->uuid}/resubmit", ['response' => 'رد'])->assertNotFound();
        $this->api($this->context->user, 'GET', '/not-a-uuid')->assertNotFound();
        $this->api($this->context->user, 'GET', '/'.Str::uuid())->assertNotFound();
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $foreign->fresh()->status);
    }

    // ---------------------------------------------------------------- detail

    public function test_the_detail_shows_the_family_view_without_internal_notes_or_diagnostics(): void
    {
        $request = $this->returned();
        app(ResubmitChangeRequestAction::class)->handle($this->context, $request, 'هذا هو الرقم الصحيح');
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer);
        FakeChangeRequestHandler::$failAfterWrite = 'unexpected';
        try {
            app(ApplyChangeRequestAction::class)->handle($request, $this->reviewer);
        } catch (ChangeRequestException) {
        }

        $response = $this->api($this->context->user, 'GET', "/{$request->uuid}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.presentation', ['rows' => [['label' => 'رقم الاستمارة', 'current' => null, 'proposed' => 'PF-NEW-1']]])
            ->assertJsonPath('data.available_actions', []);
        $this->assertSame(
            ['SUBMITTED', 'REVIEW_STARTED', 'RETURNED', 'RESUBMITTED', 'REVIEW_STARTED', 'APPROVED'],
            collect($response->json('data.timeline'))->pluck('event_type')->all(),
        );
        $this->assertSame('وضّح الرقم', $response->json('data.timeline.2.public_message'));
        $this->assertSame('هذا هو الرقم الصحيح', $response->json('data.timeline.3.public_message'));

        $json = $response->getContent();
        foreach (['ملاحظة داخلية سرية', 'internal_note', 'APPLY_FAILED', 'apply_failure', $this->reviewer->name, 'submitted_data',
            'base_fingerprint', 'client_reference', '"metadata"', '"actor"'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_a_rejection_shows_its_reason_code_and_family_message_only(): void
    {
        $request = $this->submit($this->context);
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(RejectChangeRequestAction::class)->handle($request, $this->reviewer, ChangeRequestRejectionReason::CANNOT_VERIFY, 'تعذّر التحقق', 'داخلي');

        $response = $this->api($this->context->user, 'GET', "/{$request->uuid}")->assertOk();
        $response->assertJsonPath('data.rejection.reason_code', 'CANNOT_VERIFY')
            ->assertJsonPath('data.rejection.message', 'تعذّر التحقق')
            ->assertJsonPath('data.timeline.2.reason_code', 'CANNOT_VERIFY');
        $this->assertArrayNotHasKey('rejected_by', $response->json('data.rejection'));
        $this->assertStringNotContainsString('داخلي', $response->getContent());
    }

    public function test_an_unregistered_type_is_shown_safely(): void
    {
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::production());
        $orphan = ChangeRequest::factory()->create([
            'family_id' => $this->context->family->id, 'submitted_by_person_id' => $this->context->person->id,
            'type' => 'BIRTH_REPORT', 'submitted_data' => ['governorate' => 'SECRET-GOV'],
        ]);

        $response = $this->api($this->context->user, 'GET', "/{$orphan->uuid}")->assertOk();
        $response->assertJsonPath('data.type_available', false)->assertJsonPath('data.presentation', null);
        $this->assertStringNotContainsString('SECRET-GOV', $response->getContent());
    }

    // ---------------------------------------------------------------- types and the switch

    public function test_type_discovery_follows_the_switch_the_registry_and_the_permission(): void
    {
        $this->api($this->context->user, 'GET', '/types')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [['type' => 'OTHER']], 'meta' => ['submission_enabled' => true]]);

        FakeChangeRequestHandler::$submittable = false;
        $this->api($this->context->user, 'GET', '/types')->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => true]]);
        FakeChangeRequestHandler::$submittable = true;

        config(['change_requests.family_submission_mode' => 'OFF']);
        $this->api($this->context->user, 'GET', '/types')->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => false]]);

        // The real Production registry: RESIDENCE_UPDATE only (PWA-6.1), and only while the switch is on.
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::production());
        $this->api($this->context->user, 'GET', '/types')->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => false]]);
        config(['change_requests.family_submission_mode' => 'GENERAL']);
        $this->api($this->context->user, 'GET', '/types')->assertExactJson(['data' => [['type' => 'RESIDENCE_UPDATE']], 'meta' => ['submission_enabled' => true]]);
    }

    public function test_the_switch_blocks_new_submissions_only(): void
    {
        $returned = $this->returned();
        $open = $this->submit($this->headContext('133456789'));
        config(['change_requests.family_submission_mode' => 'OFF']);
        $before = [ChangeRequest::count(), WorkflowEvent::count(), FamilyActivity::count()];

        $this->api($this->context->user, 'POST', '', $this->body())->assertStatus(503)
            ->assertJsonPath('code', 'CHANGE_REQUEST_SUBMISSION_DISABLED')->assertHeader('Cache-Control', 'no-store, private');
        // Even an invalid body gets the same answer.
        $this->api($this->context->user, 'POST', '', [])->assertStatus(503);
        $this->assertSame($before, [ChangeRequest::count(), WorkflowEvent::count(), FamilyActivity::count()]);

        // History, detail, resubmission and cancellation continue.
        $this->api($this->context->user, 'GET')->assertOk();
        $this->api($this->context->user, 'GET', "/{$returned->uuid}")->assertOk();
        $this->api($this->context->user, 'POST', "/{$returned->uuid}/resubmit", ['response' => 'الرد'])->assertOk()->assertJsonPath('data.status', 'RESUBMITTED');
        $this->api($this->context->user, 'POST', "/{$returned->uuid}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame(S::SUBMITTED, $open->fresh()->status);
        // And the switch defaults to off.
        $this->assertSame('OFF', (require config_path('change_requests.php'))['family_submission_mode']);
    }

    public function test_the_production_registry_accepts_no_unregistered_type(): void
    {
        $this->app->instance(ChangeRequestTypes::class, ChangeRequestTypes::production());

        $this->api($this->context->user, 'POST', '', $this->body())->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_TYPE_UNAVAILABLE');
        $this->api($this->context->user, 'POST', '', $this->body(['type' => 'BIRTH_REPORT']))->assertUnprocessable()->assertJsonPath('code', 'CHANGE_REQUEST_TYPE_UNAVAILABLE');
        $this->assertSame(0, ChangeRequest::count());
    }

    // ---------------------------------------------------------------- submission

    public function test_a_submission_creates_a_request_from_the_trusted_context_only(): void
    {
        $other = Family::factory()->create();
        $response = $this->api($this->context->user, 'POST', '', $this->body([
            'reason' => 'رقم الاستمارة تغيّر',
            'family_id' => $other->id, 'status' => 'APPLIED', 'submitted_by' => 1, 'person_id' => 1, 'target_membership_id' => 1,
            'base_fingerprint' => str_repeat('a', 64), 'approved_by' => 1, 'applied_by' => 1,
            'data' => ['paper_form_no' => ' PF-NEW ', 'notes' => 'x'],
        ]))->assertCreated()->assertHeader('Cache-Control', 'no-store, private');

        $response->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.replayed', false);
        $request = ChangeRequest::where('uuid', $response->json('data.id'))->sole();
        $this->assertSame($this->context->family->id, $request->family_id);
        $this->assertSame($this->context->user->id, $request->submitted_by);
        $this->assertSame($this->context->person->id, $request->submitted_by_person_id);
        $this->assertNull($request->target_membership_id);
        $this->assertNull($request->approved_by);
        $this->assertSame(['paper_form_no' => 'PF-NEW'], $request->submitted_data);
        $this->assertNotSame(str_repeat('a', 64), $request->base_fingerprint);
        $this->assertSame('رقم الاستمارة تغيّر', $request->reason);
        // No canonical change.
        $this->assertSame('PF-OLD', $this->context->family->fresh()->paper_form_no);
        $this->assertSame(0, ChangeRequest::where('family_id', $other->id)->count());
        $this->assertArrayNotHasKey('submitted_data', $response->json('data'));
    }

    public function test_a_replay_returns_the_existing_request_and_different_material_conflicts(): void
    {
        $body = $this->body();
        $first = $this->api($this->context->user, 'POST', '', $body)->assertCreated();
        $replay = $this->api($this->context->user, 'POST', '', $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));

        $this->api($this->context->user, 'POST', '', [...$body, 'data' => ['paper_form_no' => 'PF-OTHER']])
            ->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, ChangeRequest::count());
        $this->assertSame(1, WorkflowEvent::count());
        $this->assertSame(1, FamilyActivity::where('event_type', FamilyActivityType::CHANGE_REQUEST_SUBMITTED)->count());
    }

    public function test_invalid_envelopes_targets_and_payloads_are_refused(): void
    {
        // Refused attempts count against the throttle too; this test sends many.
        config(['change_requests.family_limits.submit_user_minute' => 100]);
        $this->api($this->context->user, 'POST', '', [])->assertUnprocessable()->assertJsonValidationErrors(['type', 'client_reference', 'data']);
        $this->api($this->context->user, 'POST', '', $this->body(['client_reference' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors('client_reference');
        $this->api($this->context->user, 'POST', '', $this->body(['type' => 'FAMILY_DATA_UPDATE']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->api($this->context->user, 'POST', '', $this->body(['data' => ['paper_form_no' => str_repeat('x', 51)]]))->assertUnprocessable();
        $this->api($this->context->user, 'POST', '', $this->body(['reason' => str_repeat('س', 2001)]))->assertUnprocessable()->assertJsonValidationErrors('reason');

        $foreign = FamilyMembership::factory()->create();
        $this->api($this->context->user, 'POST', '', $this->body(['data' => [
            'paper_form_no' => 'PF-X', 'member_ref' => HouseholdMemberReference::of($foreign->family_id, $foreign->id),
        ]]))->assertNotFound()->assertJsonPath('code', 'CHANGE_REQUEST_NOT_FOUND');

        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_a_second_open_request_for_the_same_subject_conflicts(): void
    {
        $this->api($this->context->user, 'POST', '', $this->body())->assertCreated();
        $this->api($this->context->user, 'POST', '', $this->body(['data' => ['paper_form_no' => 'PF-2']]))
            ->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_ALREADY_OPEN');
    }

    // ---------------------------------------------------------------- resubmit and cancel

    public function test_resubmission_keeps_the_proposal_and_records_only_an_event(): void
    {
        $request = $this->returned();
        $before = $request->only(['submitted_data', 'target_membership_id', 'base_fingerprint', 'reason']);

        $this->api($this->context->user, 'POST', "/{$request->uuid}/resubmit", ['response' => '  '])->assertUnprocessable()->assertJsonValidationErrors('response');
        $this->api($this->context->user, 'POST', "/{$request->uuid}/resubmit", [
            'response' => 'هذا هو الرقم الصحيح', 'data' => ['paper_form_no' => 'CHANGED'],
        ])->assertOk()->assertJsonPath('data.status', 'RESUBMITTED');

        $fresh = $request->fresh();
        $this->assertSame($before, $fresh->only(['submitted_data', 'target_membership_id', 'base_fingerprint', 'reason']));
        $this->assertSame(1, ChangeRequest::count());
        $this->assertSame(1, FamilyActivity::where('event_type', 'like', 'CHANGE_REQUEST_%')->count());
        $this->assertSame('هذا هو الرقم الصحيح', WorkflowEvent::where('event_type', WorkflowEventType::RESUBMITTED)->sole()->public_message);

        // Not RETURNED any more: refused.
        $this->api($this->context->user, 'POST', "/{$request->uuid}/resubmit", ['response' => 'مرة أخرى'])
            ->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_INVALID_TRANSITION');
    }

    public function test_cancellation_follows_the_transition_table_and_records_no_activity(): void
    {
        $request = $this->submit($this->context);
        $this->api($this->context->user, 'POST', "/{$request->uuid}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->api($this->context->user, 'POST', "/{$request->uuid}/cancel")->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(0, FamilyActivity::where('event_type', 'like', 'CHANGE_REQUEST_%')->where('event_type', '!=', 'CHANGE_REQUEST_SUBMITTED')->count());

        FakeChangeRequestHandler::$conflictKeys = false;
        $approved = $this->submit($this->context, ['paper_form_no' => 'PF-A']);
        app(StartChangeRequestReviewAction::class)->handle($approved, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($approved, $this->reviewer);
        $this->api($this->context->user, 'POST', "/{$approved->uuid}/cancel")->assertStatus(409)->assertJsonPath('code', 'CHANGE_REQUEST_INVALID_TRANSITION');
        $this->assertSame(S::APPROVED, $approved->fresh()->status);
    }

    public function test_a_missing_permission_is_refused_at_the_route(): void
    {
        $request = $this->returned();
        $role = Role::findByName('FAMILY_USER', 'web');
        $role->revokePermissionTo(['change-request.submit', 'change-request.resubmit', 'change-request.cancel']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->api($this->context->user, 'POST', '', $this->body())->assertForbidden();
        $this->api($this->context->user, 'POST', "/{$request->uuid}/resubmit", ['response' => 'رد'])->assertForbidden();
        $this->api($this->context->user, 'POST', "/{$request->uuid}/cancel")->assertForbidden();
        // Reading needs only family-portal.access; the hints follow the permissions.
        $this->api($this->context->user, 'GET', "/{$request->uuid}")->assertOk()->assertJsonPath('data.available_actions', []);
        $this->api($this->context->user, 'GET', '/types')->assertJsonPath('data', []);
    }

    public function test_available_actions_follow_the_transition_table(): void
    {
        $returned = $this->returned();
        $this->api($this->context->user, 'GET', "/{$returned->uuid}")->assertJsonPath('data.available_actions', ['resubmit', 'cancel']);
    }

    // ---------------------------------------------------------------- throttling and logs

    public function test_submissions_and_actions_are_throttled_per_user(): void
    {
        config(['change_requests.family_limits.submit_user_minute' => 1, 'change_requests.family_limits.action_user_minute' => 1]);
        FakeChangeRequestHandler::$conflictKeys = false;

        $this->api($this->context->user, 'POST', '', $this->body())->assertCreated();
        $this->api($this->context->user, 'POST', '', $this->body())->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        $request = ChangeRequest::sole();
        $this->api($this->context->user, 'POST', "/{$request->uuid}/cancel")->assertOk();
        $this->api($this->context->user, 'POST', "/{$request->uuid}/cancel")->assertStatus(429);
    }

    public function test_nothing_of_the_payload_is_logged(): void
    {
        Log::spy();

        $this->api($this->context->user, 'POST', '', $this->body(['data' => ['paper_form_no' => 'PF-0590000000']]))->assertCreated();

        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }
}
