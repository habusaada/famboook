<?php

namespace Tests\Feature\ChangeRequests\Api;

use App\Actions\ChangeRequests\ReturnChangeRequestForClarificationAction;
use App\Actions\ChangeRequests\StartChangeRequestReviewAction;
use App\Actions\ChangeRequests\SubmitChangeRequestAction;
use App\Enums\ChangeRequestStatus as S;
use App\Enums\FamilyStatus;
use App\Enums\FamilySubmissionMode;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\FamilyActivity;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Support\ChangeRequests\ChangeRequestSubmission;
use App\Support\ChangeRequests\FamilySubmissionPolicy;
use App\Support\FamilyAuth\FamilyAccessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ChangeRequests\ChangeRequestFixtures;
use Tests\Support\ChangeRequests\FakeChangeRequestHandler;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * The controlled pilot submission gate (PWA-6.1b, docs/11 FP-ADR-075):
 * OFF / PILOT (Family allowlist) / GENERAL, enforced in
 * SubmitChangeRequestAction and reported per Family by type discovery.
 * Only NEW submissions are gated. Synthetic data only.
 */
class FamilySubmissionGateTest extends TestCase
{
    use ChangeRequestFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const URI = '/api/v1/family/change-requests';

    private FamilyAccessResult $pilot;

    private FamilyAccessResult $other;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpChangeRequestEngine();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->pilot = $this->headContext('123456789');
        $this->other = $this->headContext('223456789');
        $this->reviewer = $this->staff();
    }

    private function gate(string $mode, string $ids = ''): void
    {
        config(['change_requests.family_submission_mode' => $mode, 'change_requests.pilot_family_ids' => $ids]);
    }

    private function pilotOnly(): void
    {
        $this->gate('PILOT', (string) $this->pilot->family->id);
    }

    private function api(FamilyAccessResult $as, string $method, string $uri = '', array $body = []): TestResponse
    {
        return $this->actingAs($as->user->fresh())->json($method, self::URI.$uri, $body);
    }

    private function propose(FamilyAccessResult $as, ?string $reference = null, string $form = 'PF-NEW'): TestResponse
    {
        return $this->api($as, 'POST', '', [
            'type' => 'OTHER', 'client_reference' => $reference ?? (string) Str::uuid(), 'data' => ['paper_form_no' => $form],
        ]);
    }

    private function assertClosed(TestResponse $response): void
    {
        $response->assertStatus(503)->assertExactJson([
            'message' => (new ChangeRequestException(ChangeRequestException::SUBMISSION_DISABLED))->getMessage(),
            'code' => ChangeRequestException::SUBMISSION_DISABLED,
        ]);
    }

    private function types(FamilyAccessResult $as): TestResponse
    {
        return $this->api($as, 'GET', '/types')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    // ---------------------------------------------------------------- modes

    public function test_the_default_is_off_and_nobody_can_submit(): void
    {
        config(['change_requests.family_submission_mode' => (require config_path('change_requests.php'))['family_submission_mode']]);
        $this->assertSame(FamilySubmissionMode::OFF, FamilySubmissionPolicy::mode());

        foreach ([$this->pilot, $this->other] as $context) {
            $this->assertClosed($this->propose($context));
            $this->types($context)->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => false]]);
        }
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_pilot_with_an_empty_allowlist_admits_nobody(): void
    {
        $this->gate('PILOT');

        $this->assertClosed($this->propose($this->pilot));
        $this->types($this->pilot)->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => false]]);
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_pilot_admits_only_the_allowlisted_family(): void
    {
        $this->pilotOnly();

        $this->propose($this->pilot)->assertCreated();
        $this->types($this->pilot)->assertExactJson(['data' => [['type' => 'OTHER']], 'meta' => ['submission_enabled' => true]]);

        $this->assertClosed($this->propose($this->other));
        $this->types($this->other)->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => false]]);

        $this->assertSame([$this->pilot->family->id], ChangeRequest::pluck('family_id')->all());
    }

    public function test_general_admits_every_eligible_family(): void
    {
        $this->gate('GENERAL');

        $this->propose($this->pilot)->assertCreated();
        $this->propose($this->other)->assertCreated();
        $this->types($this->other)->assertJsonPath('meta.submission_enabled', true);
        $this->assertSame(2, ChangeRequest::count());
    }

    public function test_the_mode_is_normalized_strictly_and_fails_closed(): void
    {
        foreach (['GENERAL' => 'GENERAL', ' general ' => 'GENERAL', 'Pilot' => 'PILOT', 'OFF' => 'OFF'] as $raw => $mode) {
            config(['change_requests.family_submission_mode' => $raw]);
            $this->assertSame($mode, FamilySubmissionPolicy::mode()->value, $raw);
        }
        foreach (['', 'true', '1', 'ON', 'ENABLED', 'ALL', 'PILOT,GENERAL', 'GENERAL;', null, true, 1, ['GENERAL']] as $raw) {
            config(['change_requests.family_submission_mode' => $raw]);
            $this->assertSame(FamilySubmissionMode::OFF, FamilySubmissionPolicy::mode(), var_export($raw, true));
            $this->assertClosed($this->propose($this->pilot));
        }
        $this->assertSame(0, ChangeRequest::count());
    }

    public function test_a_malformed_allowlist_admits_nobody(): void
    {
        $id = (string) $this->pilot->family->id;
        foreach (["{$id},abc", "0{$id}", "-{$id}", "{$id}.0", "{$id},,7", "{$id};7", 'FAM-000001', '123456789', "{$id} 7", '0', true, [$id]] as $raw) {
            $this->gate('PILOT');
            config(['change_requests.pilot_family_ids' => $raw]);
            if ($raw === '123456789') {
                // Well-formed but not this Family (e.g. a National ID pasted by mistake): no match.
                $this->assertSame([123456789], FamilySubmissionPolicy::pilotFamilyIds());
            } else {
                $this->assertNull(FamilySubmissionPolicy::pilotFamilyIds(), var_export($raw, true));
            }
            $this->assertClosed($this->propose($this->pilot));
            $this->types($this->pilot)->assertJsonPath('meta.submission_enabled', false);
        }
        $this->assertSame(0, ChangeRequest::count());

        // Whitespace around valid ids is accepted; duplicates collapse.
        config(['change_requests.pilot_family_ids' => " {$id} , 999999 ,{$id}"]);
        $this->assertSame([(int) $id, 999999], FamilySubmissionPolicy::pilotFamilyIds());
    }

    public function test_the_legacy_boolean_can_never_open_the_channel(): void
    {
        // As configured: a leftover true, no mode.
        config(['change_requests.legacy_family_submission_enabled' => true, 'change_requests.family_submission_mode' => null]);
        $this->assertSame(FamilySubmissionMode::OFF, FamilySubmissionPolicy::mode());
        $this->assertClosed($this->propose($this->pilot));

        // From the environment: the config file maps the old key to nothing that opens.
        $previous = [$_ENV['CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED'] ?? null, $_SERVER['CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED'] ?? null];
        $_ENV['CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED'] = $_SERVER['CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED'] = 'true';
        putenv('CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED=true');
        try {
            $config = require config_path('change_requests.php');
        } finally {
            putenv('CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED');
            [$env, $server] = $previous;
            if ($env === null) {
                unset($_ENV['CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED']);
            } else {
                $_ENV['CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED'] = $env;
            }
            if ($server === null) {
                unset($_SERVER['CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED']);
            } else {
                $_SERVER['CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED'] = $server;
            }
        }
        $this->assertSame('OFF', $config['family_submission_mode']);
        $this->assertArrayNotHasKey('family_submission_enabled', $config);
        config(['change_requests' => $config]);
        $this->assertSame(FamilySubmissionMode::OFF, FamilySubmissionPolicy::mode());
        $this->assertClosed($this->propose($this->pilot));
        $this->assertSame(0, ChangeRequest::count());
    }

    // ---------------------------------------------------------------- enforcement

    public function test_the_domain_action_enforces_the_gate_without_the_http_layer(): void
    {
        $this->pilotOnly();
        $before = [ChangeRequest::count(), WorkflowEvent::count(), FamilyActivity::count()];

        try {
            app(SubmitChangeRequestAction::class)->handle($this->other, new ChangeRequestSubmission(self::FAKE_TYPE, ['paper_form_no' => 'PF-X'], null, (string) Str::uuid()));
            $this->fail('A non-allowlisted Family submitted through the Domain Action.');
        } catch (ChangeRequestException $e) {
            $this->assertSame(ChangeRequestException::SUBMISSION_DISABLED, $e->reason);
        }
        $this->assertSame($before, [ChangeRequest::count(), WorkflowEvent::count(), FamilyActivity::count()]);

        // The gate answers before validation: even an invalid input gets the same refusal.
        $this->expectExceptionObject(new ChangeRequestException(ChangeRequestException::SUBMISSION_DISABLED));
        app(SubmitChangeRequestAction::class)->handle($this->other, new ChangeRequestSubmission(self::FAKE_TYPE, [], null, 'not-a-uuid'));
    }

    public function test_a_context_without_a_family_is_never_allowed(): void
    {
        $this->gate('GENERAL');
        $this->pilot->family->forceFill(['status' => FamilyStatus::INACTIVE])->save();

        // The HTTP boundary answers 403 first (precedence kept) …
        $this->propose($this->pilot)->assertForbidden();
        // … and the policy itself refuses a context without a Family.
        $this->assertFalse(FamilySubmissionPolicy::allows(FamilyAccessResult::identity(
            $this->pilot->user, $this->pilot->link, $this->pilot->person, $this->pilot->authIdentity,
        )));
    }

    public function test_the_client_cannot_choose_its_family(): void
    {
        $this->pilotOnly();

        // The non-allowlisted head names the pilot Family: still closed, and nothing reaches the pilot Family.
        $this->assertClosed($this->api($this->other, 'POST', '', [
            'type' => 'OTHER', 'client_reference' => (string) Str::uuid(), 'data' => ['paper_form_no' => 'PF-X'],
            'family_id' => $this->pilot->family->id, 'family' => $this->pilot->family->family_code,
        ]));
        $this->assertSame(0, ChangeRequest::count());

        // The pilot head's submission always targets its own Family.
        $this->api($this->pilot, 'POST', '', [
            'type' => 'OTHER', 'client_reference' => (string) Str::uuid(), 'data' => ['paper_form_no' => 'PF-Y'],
            'family_id' => $this->other->family->id,
        ])->assertCreated();
        $this->assertSame([$this->pilot->family->id], ChangeRequest::pluck('family_id')->all());
    }

    public function test_nothing_discloses_the_allowlist(): void
    {
        $this->gate('PILOT', $this->pilot->family->id.',424242');
        Log::spy();

        $responses = [$this->propose($this->other), $this->types($this->other), $this->types($this->pilot), $this->api($this->other, 'GET')];
        foreach ($responses as $response) {
            $body = $response->getContent();
            $this->assertStringNotContainsString('424242', $body);
            $this->assertStringNotContainsString($this->pilot->family->family_code, $body);
            $this->assertStringNotContainsString('PILOT', $body);
            $this->assertStringNotContainsString('pilot', $body);
        }
        $this->types($this->other)->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => false]]);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    // ---------------------------------------------------------------- only NEW submissions

    public function test_history_detail_resubmit_and_cancel_continue_for_a_closed_family(): void
    {
        $this->gate('GENERAL');
        $returned = $this->submit($this->other);
        app(StartChangeRequestReviewAction::class)->handle($returned, $this->reviewer);
        app(ReturnChangeRequestForClarificationAction::class)->handle($returned, $this->reviewer, 'وضّح', null);
        $open = $this->submit($this->headContext('333456789'));

        $this->pilotOnly();
        $this->assertClosed($this->propose($this->other));

        $this->api($this->other, 'GET')->assertOk()->assertJsonPath('data.0.id', $returned->uuid);
        $this->api($this->other, 'GET', "/{$returned->uuid}")->assertOk()->assertJsonPath('data.available_actions', ['resubmit', 'cancel']);
        $this->api($this->other, 'POST', "/{$returned->uuid}/resubmit", ['response' => 'الرد'])->assertOk()->assertJsonPath('data.status', 'RESUBMITTED');
        $this->api($this->other, 'POST', "/{$returned->uuid}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame(S::SUBMITTED, $open->fresh()->status);
    }

    public function test_the_staff_workflow_ignores_the_gate(): void
    {
        $this->gate('GENERAL');
        $request = $this->submit($this->other);

        $this->gate('OFF');
        $this->actingAs($this->reviewer)->postJson("/api/v1/change-requests/{$request->uuid}/start-review")->assertOk();
        $this->actingAs($this->reviewer)->postJson("/api/v1/change-requests/{$request->uuid}/approve")->assertOk();
        $this->actingAs($this->reviewer)->postJson("/api/v1/change-requests/{$request->uuid}/apply")->assertOk()->assertJsonPath('data.status', 'APPLIED');
        $this->assertSame('PF-NEW-1', $this->other->family->fresh()->paper_form_no);

        $rejectable = $this->submitWhileOpen($this->pilot);
        app(StartChangeRequestReviewAction::class)->handle($rejectable, $this->reviewer);
        $this->actingAs($this->reviewer)->postJson("/api/v1/change-requests/{$rejectable->uuid}/reject", ['rejection_reason_code' => 'CANNOT_VERIFY', 'public_message' => 'تعذر التحقق'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');
    }

    private function submitWhileOpen(FamilyAccessResult $context): ChangeRequest
    {
        $mode = config('change_requests.family_submission_mode');
        $this->gate('GENERAL');
        $request = $this->submit($context);
        config(['change_requests.family_submission_mode' => $mode]);

        return $request;
    }

    // ---------------------------------------------------------------- unchanged engine behaviour

    public function test_idempotency_and_open_conflicts_are_unchanged_in_pilot(): void
    {
        $this->pilotOnly();
        $reference = (string) Str::uuid();

        $first = $this->propose($this->pilot, $reference)->assertCreated();
        $this->propose($this->pilot, $reference)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.id', $first->json('data.id'));
        $this->propose($this->pilot, $reference, 'PF-OTHER')->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_IDEMPOTENCY_CONFLICT');
        $this->propose($this->pilot)->assertConflict()->assertJsonPath('code', 'CHANGE_REQUEST_ALREADY_OPEN');
        $this->assertSame(1, ChangeRequest::count());

        // Closing the channel turns a replay into the closed answer too: nothing new can be created.
        $this->gate('OFF');
        $this->assertClosed($this->propose($this->pilot, $reference));
    }

    public function test_type_discovery_lists_only_family_submittable_types_for_an_open_family(): void
    {
        $this->pilotOnly();
        FakeChangeRequestHandler::$submittable = false;
        $this->types($this->pilot)->assertExactJson(['data' => [], 'meta' => ['submission_enabled' => true]]);
    }

    // ---------------------------------------------------------------- readiness command

    public function test_the_readiness_check_prints_counts_only(): void
    {
        $this->gate('PILOT', $this->pilot->family->id.',999999');
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Family submission mode (effective): PILOT')
            ->expectsOutputToContain('Pilot allowlist: 2 id(s), 1 active Family(ies)')
            ->doesntExpectOutputToContain((string) $this->pilot->family->id.',')
            ->doesntExpectOutputToContain($this->pilot->family->family_code)
            ->expectsOutputToContain('WARN: Some allowlisted ids are not active Families.')
            ->assertExitCode(1);

        $this->gate('PILOT', (string) $this->pilot->family->id);
        $this->artisan('famboook:change-requests-check')->expectsOutputToContain('no warnings')->assertExitCode(0);

        config(['change_requests.family_submission_mode' => 'YES', 'change_requests.legacy_family_submission_enabled' => 'true', 'change_requests.pilot_family_ids' => 'x']);
        $this->artisan('famboook:change-requests-check')
            ->expectsOutputToContain('Family submission mode (effective): OFF')
            ->expectsOutputToContain('Pilot allowlist: INVALID')
            ->expectsOutputToContain('Legacy CHANGE_REQUESTS_FAMILY_SUBMISSION_ENABLED set: YES')
            ->assertExitCode(1);
    }
}
