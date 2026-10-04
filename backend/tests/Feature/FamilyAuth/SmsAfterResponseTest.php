<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Enums\SmsFailureOutcome;
use App\Enums\SmsFailureReason;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Support\Sms\SmsDeliveryException;
use App\Support\Sms\SmsDispatcher;
use App\Support\Sms\SmsMessage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * TweetsMS A′ (docs/11 §30a): on the public activation and password reset
 * routes the OTP SMS is handed to the provider AFTER the response — same PHP
 * process, no queue. The provider's latency or failure never changes the
 * public answer; a failure is still recorded, after the response. Outside a
 * request the send is immediate. Synthetic data only.
 */
class SmsAfterResponseTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION_ID = '123456789';

    private const RESET_ID = '223456789';

    private const UNKNOWN_ID = '987654321';

    private FakeSmsSender $sms;

    /** SMS attempts already made when the response was complete; null = not yet handled. */
    private ?int $attemptsAtResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config([
            'family_auth.activation_enabled' => true,
            'family_auth.password_reset_enabled' => true,
            'family_auth.activation.min_response_ms' => 0,
        ]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        // RequestHandled fires once the response exists, before termination.
        Event::listen(RequestHandled::class, function () {
            $this->attemptsAtResponse = $this->sms->attempts;
        });

        [$head] = $this->eligibleHead(self::ACTIVATION_ID);
        $this->trustedMobile($head, '0591234567');
        $account = $this->activatedHead(self::RESET_ID);
        $this->trustedMobile($account['person'], '0597654321');
        $this->freezeSecond();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function flows(): array
    {
        return [
            'activation' => [self::ACTIVATION, self::ACTIVATION_ID],
            'password reset' => [self::RESET, self::RESET_ID],
        ];
    }

    /** The request that sends the first code: activation's send (after the confirmation), reset's start. */
    private function start(string $base, string $nationalId): TestResponse
    {
        if ($base === self::ACTIVATION) {
            $confirmation = $this->postJson($base.'/start', ['national_id' => $nationalId])->assertOk()->json('confirmation');
            $this->attemptsAtResponse = null;

            return $this->postJson($base.'/send', ['confirmation' => $confirmation]);
        }
        $this->attemptsAtResponse = null;

        return $this->postJson($base.'/start', ['national_id' => $nationalId]);
    }

    private function resend(string $base, string $reference): TestResponse
    {
        $this->attemptsAtResponse = null;

        return $this->postJson($base.'/resend', ['challenge' => $reference]);
    }

    #[DataProvider('flows')]
    public function test_start_sends_the_sms_only_after_the_response(string $base, string $nationalId): void
    {
        $this->start($base, $nationalId)->assertOk();

        $this->assertSame(0, $this->attemptsAtResponse, 'No SMS may be sent before the response.');
        $this->assertSame(1, $this->sms->attempts);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(1, AuthOtpChallenge::sole()->send_count);
    }

    #[DataProvider('flows')]
    public function test_resend_sends_the_sms_only_after_the_response(string $base, string $nationalId): void
    {
        $reference = $this->start($base, $nationalId)->assertOk()->json('challenge');
        $this->travel(61)->seconds();

        $this->resend($base, $reference)->assertOk();

        $this->assertSame(1, $this->attemptsAtResponse, 'The resend SMS may not be sent before the response.');
        $this->assertSame(2, $this->sms->attempts);
        $this->assertSame(2, AuthOtpChallenge::sole()->send_count);
    }

    #[DataProvider('flows')]
    public function test_a_failing_provider_does_not_change_the_public_answer(string $base, string $nationalId): void
    {
        $healthy = $this->start($base, $nationalId)->assertOk()->json();
        AuthOtpChallenge::query()->delete();
        AuthSecurityEvent::query()->delete();
        Cache::flush();

        $this->sms->failing = true;
        $this->sms->failure = SmsDeliveryException::classified(SmsFailureOutcome::UNKNOWN, SmsFailureReason::TRANSPORT_UNCERTAIN);
        $failing = $this->start($base, $nationalId)->assertOk()->json();

        $this->assertSame(array_keys($healthy), array_keys($failing));
        $this->assertSame(
            collect($healthy)->except('challenge')->all(),
            collect($failing)->except('challenge')->all(),
        );
        $this->assertStringNotContainsString('TRANSPORT_UNCERTAIN', json_encode($failing));
        $this->assertStringNotContainsString('UNKNOWN', json_encode($failing));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function decoyFlows(): array
    {
        // First activation answers a refused identifier with a refusal, not a
        // decoy (FP-ADR-054); password reset keeps its decoys.
        return ['password reset' => [self::RESET, self::RESET_ID]];
    }

    #[DataProvider('decoyFlows')]
    public function test_a_real_challenge_with_a_failing_provider_answers_like_a_decoy(string $base, string $nationalId): void
    {
        $decoy = $this->start($base, self::UNKNOWN_ID)->assertOk()->json();
        $this->sms->failing = true;
        $real = $this->start($base, $nationalId)->assertOk()->json();

        $this->assertSame(collect($decoy)->except('challenge')->all(), collect($real)->except('challenge')->all());
    }

    #[DataProvider('flows')]
    public function test_a_failure_after_the_response_is_still_recorded_with_safe_codes(string $base, string $nationalId): void
    {
        $this->sms->failing = true;
        $this->sms->failure = SmsDeliveryException::classified(SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::INSUFFICIENT_CREDIT);

        $this->start($base, $nationalId)->assertOk();

        $this->assertSame(0, $this->attemptsAtResponse);
        $event = AuthSecurityEvent::where('event_type', 'OTP_ISSUED')->sole();
        $this->assertSame('FAILURE', $event->outcome->value);
        $this->assertSame('DELIVERY_FAILED', $event->reason_code);
        $this->assertSame('PROVIDER_CONFIGURATION_FAILURE', $event->metadata['delivery_outcome']);
        $this->assertSame('INSUFFICIENT_CREDIT', $event->metadata['delivery_reason']);
    }

    #[DataProvider('flows')]
    public function test_a_slow_provider_is_not_waited_for_before_the_response(string $base, string $nationalId): void
    {
        $slow = new class implements SmsSender
        {
            public bool $responseWasReady = false;

            public ?\Closure $probe = null;

            public function send(SmsMessage $message): void
            {
                $this->responseWasReady = ($this->probe)();
                usleep(200_000);
            }
        };
        $slow->probe = fn () => $this->attemptsAtResponse !== null;
        $this->app->instance(SmsSender::class, $slow);

        $this->start($base, $nationalId)->assertOk();

        $this->assertTrue($slow->responseWasReady, 'The provider call must start after the response was complete.');
    }

    #[DataProvider('flows')]
    public function test_nothing_is_queued_and_the_code_is_never_stored(string $base, string $nationalId): void
    {
        Queue::fake();

        $this->start($base, $nationalId)->assertOk();

        Queue::assertNothingPushed();
        $code = $this->sms->lastCode();
        $this->assertNotNull($code);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertStringNotContainsString($code, json_encode(AuthOtpChallenge::sole()->getAttributes()));
        $events = AuthSecurityEvent::all()->map(fn ($e) => json_encode($e->getAttributes()))->implode("\n");
        $this->assertStringNotContainsString($code, $events);
    }

    public function test_outside_a_request_the_send_is_immediate(): void
    {
        $dispatcher = $this->app->make(SmsDispatcher::class);
        $reported = 'not called';

        $failure = $dispatcher->dispatch(new SmsMessage('0591234567', 'synthetic', 'TEST'), function ($f) use (&$reported) {
            $reported = $f;
        });

        $this->assertFalse($dispatcher->deferring());
        $this->assertNull($failure);
        $this->assertNull($reported);
        $this->assertSame(1, $this->sms->attempts);
    }

    public function test_an_immediate_failure_is_returned_to_the_caller(): void
    {
        $this->sms->failing = true;

        $failure = $this->app->make(SmsDispatcher::class)
            ->dispatch(new SmsMessage('0591234567', 'synthetic', 'TEST'), fn () => null);

        $this->assertInstanceOf(SmsDeliveryException::class, $failure);
    }

    public function test_an_unexpected_error_after_the_response_never_escapes(): void
    {
        $this->app->instance(SmsSender::class, new class implements SmsSender
        {
            public function send(SmsMessage $message): void
            {
                throw new \RuntimeException('provider exploded');
            }
        });

        $this->start(self::ACTIVATION, self::ACTIVATION_ID)->assertOk();

        $event = AuthSecurityEvent::where('event_type', 'OTP_ISSUED')->sole();
        $this->assertSame('UNKNOWN', $event->metadata['delivery_outcome']);
        $this->assertSame('UNEXPECTED_ERROR', $event->metadata['delivery_reason']);
    }

    public function test_login_is_not_deferred_and_sends_nothing(): void
    {
        $this->postJson('/api/v1/family/auth/login', ['national_id' => self::RESET_ID, 'password' => 'wrong-password-123']);

        $this->assertSame(0, $this->sms->attempts);
        $this->assertFalse($this->app->make(SmsDispatcher::class)->deferring());
    }
}
