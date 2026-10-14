<?php

namespace Tests\Feature\ChangeRequests\Coordinator;

use App\Actions\ChangeRequests\ApproveChangeRequestAction;
use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Enums\ChangeRequestAttestation;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\FamilyStatus;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\CoordinatorScopeAssignment;
use App\Models\Person;
use App\Models\User;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use App\Support\FamilyAuth\FamilyAccessResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\ChangeRequests\CoordinatorRequestFixtures;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * CO-02.4: the Coordinator request API (PERSON_ADD) — boundaries,
 * permissions, scope, the switch, submission, tracking, resubmission,
 * cancellation, channel isolation, identity privacy and throttles — over
 * the CO-02.3b engine. Synthetic data and National IDs only.
 */
class CoordinatorChangeRequestApiTest extends TestCase
{
    use CoordinatorFixtures, CoordinatorRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/coordinator';

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCoordinatorRequests();
        $this->reviewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::findByName('REVIEWER', 'web')));
        // Generous limits by default; the throttle test lowers them.
        config(['change_requests.coordinator_limits' => ['submit_user_minute' => 100, 'submit_user_hour' => 100, 'action_user_minute' => 100, 'read_user_minute' => 100]]);
    }

    private function api(?User $as, string $method, string $uri, array $body = []): TestResponse
    {
        if ($as !== null) {
            $this->actingAs($as->fresh());
        }

        return $this->json($method, self::URI.$uri, $body);
    }

    private function submit(array $data = [], ?string $familyCode = null, ?string $reference = null, ?User $as = null, string $type = 'ADD_FAMILY_MEMBER'): TestResponse
    {
        return $this->api($as ?? $this->coordinatorUser, 'POST', '/families/'.($familyCode ?? $this->household->family->family_code).'/requests', [
            'type' => $type, 'client_reference' => $reference ?? (string) Str::uuid(), 'data' => $this->member($data),
        ]);
    }

    private function submitted(array $data = []): ChangeRequest
    {
        return ChangeRequest::where('uuid', $this->submit($data)->assertCreated()->json('data.id'))->sole();
    }

    private function returned(ChangeRequest $request): ChangeRequest
    {
        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ReturnChangeRequestForClarificationAction::class)->handle($request, $this->reviewer, 'أرفق ما يثبت العلاقة.', 'ملاحظة داخلية سرية');

        return $request->fresh();
    }

    private function assertNotFound(TestResponse $response): void
    {
        $response->assertNotFound()->assertExactJson([
            'message' => (new ChangeRequestException(ChangeRequestException::NOT_FOUND))->getMessage(),
            'code' => ChangeRequestException::NOT_FOUND,
        ]);
    }

    // ------------------------------------------------------------ boundaries

    public function test_every_request_route_carries_its_boundaries_permission_and_throttle(): void
    {
        $expected = [
            'GET api/v1/family/coordinator/requests/types' => ['can:coordinator-request.view', 'throttle:coordinator-request-read'],
            'GET api/v1/family/coordinator/requests' => ['can:coordinator-request.view', 'throttle:coordinator-request-read'],
            'GET api/v1/family/coordinator/requests/{changeRequest}' => ['can:coordinator-request.view', 'throttle:coordinator-request-read'],
            'POST api/v1/family/coordinator/families/{familyCode}/requests' => ['can:coordinator-family.view-summary', 'can:coordinator-request.submit', 'throttle:coordinator-request-submit'],
            'POST api/v1/family/coordinator/requests/{changeRequest}/resubmit' => ['can:coordinator-request.resubmit', 'throttle:coordinator-request-action'],
            'POST api/v1/family/coordinator/requests/{changeRequest}/cancel' => ['can:coordinator-request.cancel', 'throttle:coordinator-request-action'],
        ];
        $found = [];
        foreach (Route::getRoutes() as $route) {
            $key = $route->methods()[0].' '.$route->uri();
            if (! isset($expected[$key])) {
                continue;
            }
            $found[] = $key;
            $middleware = $route->gatherMiddleware();
            foreach (['auth:sanctum', 'family.side', 'coordinator.space', ...$expected[$key]] as $required) {
                $this->assertContains($required, $middleware, $key);
            }
            $this->assertSame([], array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'can:change-request.')), $key);
            $this->assertSame([], $route->signatureParameters(['subClass' => Model::class]), $key);
        }
        $this->assertEqualsCanonicalizing(array_keys($expected), $found);
    }

    public function test_guests_staff_households_and_unscoped_accounts_are_refused(): void
    {
        $this->json('GET', self::URI.'/requests')->assertUnauthorized();
        $this->api($this->reviewer, 'GET', '/requests')->assertForbidden();
        $this->api($this->household->user, 'GET', '/requests')->assertForbidden();
        $this->api($this->household->user, 'POST', '/families/'.$this->household->family->family_code.'/requests', [
            'type' => 'ADD_FAMILY_MEMBER', 'client_reference' => (string) Str::uuid(), 'data' => $this->member(),
        ])->assertForbidden();
        $unscoped = $this->coordinator('511111110');
        $this->api($unscoped, 'GET', '/requests')->assertForbidden();
        $this->submit(as: $unscoped)->assertForbidden();
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_each_route_needs_its_coordinator_request_permission(): void
    {
        $role = Role::findByName('COORDINATOR', 'web');
        $request = $this->returned($this->submitted());

        $role->revokePermissionTo('coordinator-request.view');
        $this->api($this->coordinatorUser, 'GET', '/requests')->assertForbidden();
        $this->api($this->coordinatorUser, 'GET', '/requests/'.$request->uuid)->assertForbidden();
        $this->api($this->coordinatorUser, 'GET', '/requests/types')->assertForbidden();

        $role->revokePermissionTo('coordinator-request.submit');
        $this->assertTrue($this->coordinatorUser->fresh()->can('change-request.submit'), 'held through FAMILY_USER');
        $this->submit(['national_id' => '402345678'])->assertForbidden();

        $role->revokePermissionTo('coordinator-request.resubmit');
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/resubmit', ['response' => 'رد'])->assertForbidden();
        $role->revokePermissionTo('coordinator-request.cancel');
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/cancel')->assertForbidden();
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $request->fresh()->status);
        $this->assertSame(1, ChangeRequest::count());
    }

    // ---------------------------------------------------------------- switch

    public function test_the_switch_off_pilot_and_general(): void
    {
        config(['change_requests.coordinator_submission_mode' => 'OFF']);
        $this->submit()->assertStatus(503)->assertJsonPath('code', ChangeRequestException::SUBMISSION_DISABLED);
        $this->api($this->coordinatorUser, 'GET', '/requests/types')->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => false]]);

        config(['change_requests.coordinator_submission_mode' => 'PILOT', 'change_requests.coordinator_pilot_user_ids' => '999999']);
        $this->submit()->assertStatus(503);
        config(['change_requests.coordinator_pilot_user_ids' => (string) $this->coordinatorUser->id]);
        $this->submit()->assertCreated();
        $this->api($this->coordinatorUser, 'GET', '/requests/types')->assertOk()
            ->assertExactJson(['data' => [['type' => 'ADD_FAMILY_MEMBER'], ['type' => 'REGISTER_FAMILY']], 'meta' => ['submission_enabled' => true]]);

        config(['change_requests.coordinator_submission_mode' => 'GENERAL', 'change_requests.coordinator_pilot_user_ids' => '']);
        $this->submit(['national_id' => '402345678'])->assertCreated();
        $this->assertSame(2, ChangeRequest::count());
    }

    public function test_a_closed_switch_answers_before_validation_and_never_blocks_follow_up(): void
    {
        $request = $this->returned($this->submitted());
        config(['change_requests.coordinator_submission_mode' => 'OFF']);

        $this->api($this->coordinatorUser, 'POST', '/families/NO-SUCH/requests', ['junk' => true])->assertStatus(503);
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/resubmit', ['response' => 'أرفقت الوثيقة.'])->assertOk()
            ->assertJsonPath('data.status', 'RESUBMITTED');
        $this->api($this->coordinatorUser, 'GET', '/requests/'.$request->uuid)->assertOk();
    }

    // ------------------------------------------------------------ submission

    public function test_a_coordinator_submits_person_add_for_a_family_in_scope(): void
    {
        $response = $this->submit()->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertExactJson(['data' => [
            'id' => $response->json('data.id'),
            'request_code' => $response->json('data.request_code'),
            'status' => 'SUBMITTED',
            'replayed' => false,
        ]]);
        $request = ChangeRequest::where('uuid', $response->json('data.id'))->sole();
        $this->assertSame('COORDINATOR', $request->submission_channel->value);
        $this->assertSame($this->household->family->id, $request->family_id);
        $this->assertFalse(Person::where('national_id', self::MEMBER_NID)->exists(), 'nothing canonical before APPLY');
    }

    public function test_submission_validates_the_envelope_and_the_proposal(): void
    {
        $this->submit(type: 'OTHER')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->submit(type: 'REGISTER_FAMILY')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->submit(type: 'CONTACT_UPDATE')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->api($this->coordinatorUser, 'POST', '/families/'.$this->household->family->family_code.'/requests', [
            'type' => 'ADD_FAMILY_MEMBER', 'client_reference' => 'not-a-uuid', 'data' => $this->member(),
        ])->assertUnprocessable()->assertJsonValidationErrors('client_reference');
        // The National ID rule (DB-ADR-061): nine digits, never a placeholder.
        $this->submit(['national_id' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('national_id');
        $this->submit(['national_id' => '111111111'])->assertUnprocessable()->assertJsonValidationErrors('national_id');
        $this->submit(['relationship' => 'HEAD'])->assertUnprocessable()->assertJsonValidationErrors('relationship');
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_submission_never_says_whether_a_national_id_exists(): void
    {
        // One ID held by a registry Person elsewhere, one free: the same answer.
        Person::factory()->create(['national_id' => '402345678']);
        $held = $this->submit(['national_id' => '402345678'])->assertCreated();
        $free = $this->submit(['national_id' => '403456789'])->assertCreated();
        $this->assertSame(array_keys($held->json('data')), array_keys($free->json('data')));
        $this->assertSame('SUBMITTED', $held->json('data.status'));

        // And the Coordinator's view of it has no registry-match row.
        $detail = $this->api($this->coordinatorUser, 'GET', '/requests/'.$held->json('data.id'))->assertOk();
        $this->assertNotContains('مطابقة رقم الهوية في السجل', array_column($detail->json('data.presentation.rows'), 'label'));
    }

    public function test_submission_is_idempotent_and_one_open_proposal_per_id(): void
    {
        $reference = (string) Str::uuid();
        $first = $this->submit(reference: $reference)->assertCreated();
        $this->submit(reference: $reference)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.id', $first->json('data.id'));
        $this->submit(['full_name' => 'اسم آخر'], reference: $reference)->assertStatus(409)->assertJsonPath('code', ChangeRequestException::IDEMPOTENCY_CONFLICT);
        $this->submit()->assertStatus(409)->assertJsonPath('code', ChangeRequestException::ALREADY_OPEN);
        $this->assertSame(1, ChangeRequest::count());
    }

    // ----------------------------------------------------------------- scope

    public function test_a_family_outside_the_scope_is_the_same_404_as_a_nonexistent_one(): void
    {
        $elsewhere = $this->householdIn($this->clan('OTHER_CLAN'), '823456789');
        $outside = $this->submit(familyCode: $elsewhere->family->family_code);
        $missing = $this->submit(familyCode: 'FAM-999999');
        $this->assertNotFound($outside);
        $this->assertNotFound($missing);

        $own = app(FamilyAccessResolver::class)->familyContext($this->coordinatorUser)->family;
        $this->assertNotFound($this->submit(familyCode: $own->family_code));

        $this->household->family->forceFill(['status' => FamilyStatus::INACTIVE])->save();
        $this->assertNotFound($this->submit());
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_a_revoked_scope_hides_the_requests_and_staff_keep_them(): void
    {
        $request = $this->returned($this->submitted());
        CoordinatorScopeAssignment::where('user_id', $this->coordinatorUser->id)
            ->update(['revoked_at' => now(), 'revoked_by' => $this->reviewer->id, 'revoke_reason' => 'ADMINISTRATIVE']);
        $this->assign($this->coordinatorUser, $this->clan('OTHER_CLAN'));

        $this->api($this->coordinatorUser, 'GET', '/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotFound($this->api($this->coordinatorUser, 'GET', '/requests/'.$request->uuid));
        $this->assertNotFound($this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/resubmit', ['response' => 'رد']));
        $this->assertNotFound($this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/cancel'));

        $this->actingAs($this->reviewer->fresh())->getJson('/api/v1/change-requests/'.$request->uuid)->assertOk()
            ->assertJsonPath('data.submission_channel', 'COORDINATOR')->assertJsonPath('data.status', 'RETURNED_FOR_CLARIFICATION');
    }

    // -------------------------------------------------------------- tracking

    public function test_the_list_holds_only_the_coordinators_own_requests_in_scope(): void
    {
        $mine = $this->submitted();
        $colleague = $this->coordinator('921111110');
        $this->assign($colleague, $this->scopeClan);
        $this->submit(['national_id' => '402345678'], as: $colleague)->assertCreated();
        // The household's own request about the same Family.
        $this->actingAs($this->household->user->fresh())->postJson('/api/v1/family/change-requests', [
            'type' => 'ADD_FAMILY_MEMBER', 'client_reference' => (string) Str::uuid(), 'data' => $this->member(['national_id' => '403456789']),
        ])->assertCreated();

        $list = $this->api($this->coordinatorUser, 'GET', '/requests')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([$mine->uuid], array_column($list->json('data'), 'id'));
        $row = $list->json('data.0');
        $this->assertSame([
            'id', 'request_code', 'type', 'type_available', 'status', 'family_code', 'placement', 'created_family_code', 'summary',
            'submitted_at', 'approved_at', 'rejected_at', 'applied_at', 'cancelled_at', 'available_actions',
        ], array_keys($row));
        $this->assertSame($this->household->family->family_code, $row['family_code']);
        $this->assertSame(['cancel'], $row['available_actions']);

        $this->api($this->coordinatorUser, 'GET', '/requests?status=CANCELLED')->assertOk()->assertJsonCount(0, 'data');
        $this->api($this->coordinatorUser, 'GET', '/requests?type=ADD_FAMILY_MEMBER')->assertOk()->assertJsonCount(1, 'data');
        $this->api($this->coordinatorUser, 'GET', '/requests?per_page=500')->assertUnprocessable();
    }

    public function test_the_detail_shows_status_masked_proposal_timeline_and_actions_only(): void
    {
        $request = $this->returned($this->submitted(['mobile' => '0591112233']));
        $response = $this->api($this->coordinatorUser, 'GET', '/requests/'.$request->uuid)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'RETURNED_FOR_CLARIFICATION')
            ->assertJsonPath('data.family_code', $this->household->family->family_code)
            ->assertJsonPath('data.available_actions', ['resubmit', 'cancel']);

        $json = $response->getContent();
        $this->assertStringNotContainsString(self::MEMBER_NID, $json, 'never the full National ID');
        $this->assertStringNotContainsString('0591112233', $json, 'never the full mobile');
        $this->assertStringNotContainsString('ملاحظة داخلية سرية', $json, 'never a Staff-only note');
        $this->assertStringNotContainsString($this->reviewer->name, $json, 'never a Staff name');
        foreach (['base_fingerprint', 'client_reference', 'submitted_data', 'apply_failure_count', 'internal_note', 'actor_user_id', 'family_id'] as $hidden) {
            $this->assertStringNotContainsString('"'.$hidden.'"', $json, $hidden);
        }
        $this->assertSame(['SUBMITTED', 'REVIEW_STARTED', 'RETURNED'], array_column($response->json('data.timeline'), 'event_type'));
        $this->assertSame(['COORDINATOR', 'STAFF', 'STAFF'], array_column($response->json('data.timeline'), 'actor_side'));
        $this->assertSame('أرفق ما يثبت العلاقة.', $response->json('data.timeline.2.public_message'));
        $this->assertContains('*****4567', array_column($response->json('data.presentation.rows'), 'proposed'));

        $this->assertNotFound($this->api($this->coordinatorUser, 'GET', '/requests/not-a-uuid'));
        $this->assertNotFound($this->api($this->coordinatorUser, 'GET', '/requests/'.Str::uuid()));
    }

    // ------------------------------------------- resubmission, cancellation

    public function test_the_coordinator_answers_and_withdraws_their_request(): void
    {
        $request = $this->returned($this->submitted());

        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/resubmit', [])->assertUnprocessable()->assertJsonValidationErrors('response');
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/resubmit', ['response' => 'أرفقت الوثيقة.'])->assertOk()
            ->assertJsonPath('data.status', 'RESUBMITTED')->assertJsonPath('data.replayed', false);
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/resubmit', ['response' => 'أرفقت الوثيقة.'])->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/cancel')->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/cancel')->assertOk()->assertJsonPath('data.replayed', true);
        $this->api($this->coordinatorUser, 'GET', '/requests/'.$request->uuid)->assertOk()
            ->assertJsonPath('data.available_actions', [])
            ->assertJsonPath('data.timeline.4.actor_side', 'COORDINATOR');
    }

    public function test_cancellation_follows_the_workflow_rules(): void
    {
        $request = $this->submitted();
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/resubmit', ['response' => 'رد'])
            ->assertStatus(409)->assertJsonPath('code', ChangeRequestException::INVALID_TRANSITION);

        app(StartChangeRequestReviewAction::class)->handle($request, $this->reviewer);
        app(ApproveChangeRequestAction::class)->handle($request, $this->reviewer, new ChangeRequestApprovalEvidence(
            [ChangeRequestAttestation::IDENTITY_VERIFIED, ChangeRequestAttestation::RELATIONSHIP_VERIFIED], self::MEMBER_NID,
        ));
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/cancel')
            ->assertStatus(409)->assertJsonPath('code', ChangeRequestException::INVALID_TRANSITION);
        $this->assertSame(S::APPROVED, $request->fresh()->status);
    }

    // -------------------------------------------------- channel isolation

    public function test_channels_and_coordinators_never_cross(): void
    {
        $request = $this->returned($this->submitted());

        // The household sees it in its history but never answers or cancels it.
        $this->actingAs($this->household->user->fresh())->getJson('/api/v1/family/change-requests/'.$request->uuid)->assertOk()
            ->assertJsonPath('data.submission_channel', 'COORDINATOR')->assertJsonPath('data.available_actions', []);
        $this->actingAs($this->household->user->fresh())->postJson('/api/v1/family/change-requests/'.$request->uuid.'/cancel')
            ->assertForbidden()->assertJsonPath('code', ChangeRequestException::ACTOR_NOT_ALLOWED);
        $this->actingAs($this->household->user->fresh())->postJson('/api/v1/family/change-requests/'.$request->uuid.'/resubmit', ['response' => 'رد'])
            ->assertForbidden()->assertJsonPath('code', ChangeRequestException::ACTOR_NOT_ALLOWED);

        // Another Coordinator of the same scope: does not exist for them.
        $colleague = $this->coordinator('921111110');
        $this->assign($colleague, $this->scopeClan);
        $this->assertNotFound($this->api($colleague, 'GET', '/requests/'.$request->uuid));
        $this->assertNotFound($this->api($colleague, 'POST', '/requests/'.$request->uuid.'/cancel'));

        // The household's own request: never visible through the coordinator routes.
        $familyRequest = $this->actingAs($this->household->user->fresh())->postJson('/api/v1/family/change-requests', [
            'type' => 'ADD_FAMILY_MEMBER', 'client_reference' => (string) Str::uuid(), 'data' => $this->member(['national_id' => '402345678']),
        ])->assertCreated()->json('data.id');
        $this->assertNotFound($this->api($this->coordinatorUser, 'GET', '/requests/'.$familyRequest));
        $this->assertNotFound($this->api($this->coordinatorUser, 'POST', '/requests/'.$familyRequest.'/cancel'));
        $this->assertSame(S::SUBMITTED, ChangeRequest::where('uuid', $familyRequest)->sole()->status);
        $this->assertSame(S::RETURNED_FOR_CLARIFICATION, $request->fresh()->status);
    }

    // ---------------------------------------------------------- throttles

    public function test_each_route_is_throttled_per_user(): void
    {
        config(['change_requests.coordinator_limits' => ['submit_user_minute' => 1, 'submit_user_hour' => 10, 'action_user_minute' => 1, 'read_user_minute' => 2]]);
        $request = $this->submitted();
        $this->submit(['national_id' => '402345678'])->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');

        $this->api($this->coordinatorUser, 'GET', '/requests')->assertOk();
        $this->api($this->coordinatorUser, 'GET', '/requests/'.$request->uuid)->assertOk();
        $this->api($this->coordinatorUser, 'GET', '/requests/types')->assertStatus(429);

        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/cancel')->assertOk();
        $this->api($this->coordinatorUser, 'POST', '/requests/'.$request->uuid.'/cancel')->assertStatus(429);

        // Per user: another Coordinator is unaffected.
        $colleague = $this->coordinator('921111110');
        $this->assign($colleague, $this->scopeClan);
        $this->api($colleague, 'GET', '/requests')->assertOk();
        $this->submit(['national_id' => '403456789'], as: $colleague)->assertCreated();
    }
}
