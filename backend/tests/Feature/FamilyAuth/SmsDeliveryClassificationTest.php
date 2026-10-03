<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Enums\OtpPurpose;
use App\Enums\SmsFailureOutcome;
use App\Enums\SmsFailureReason;
use App\Models\AuthSecurityEvent;
use App\Models\Person;
use App\Support\FamilyAuth\OtpChallenges;
use App\Support\Sms\SmsDeliveryException;
use App\Support\Sms\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * SMS delivery failures are CLASSIFIED (docs/11 §30a): the outcome and a
 * safe reason go to the security event and to one safe log line — never the
 * destination, the body, the code or a provider message. Synthetic data only.
 */
class SmsDeliveryClassificationTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const MOBILE = '0591234567';

    private FakeSmsSender $sms;

    private Person $person;

    /** @var list<array{level: string, message: string, context: array}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFamilyAuthKey();
        Cache::flush();
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
        $this->person = Person::factory()->create();
        $this->trustedMobile($this->person, self::MOBILE);
    }

    private function issueFailing(SmsDeliveryException $failure): void
    {
        $this->sms->failing = true;
        $this->sms->failure = $failure;
        app(OtpChallenges::class)->issue(OtpPurpose::ACTIVATION, $this->person);
    }

    public function test_the_class_and_reason_are_recorded_as_codes(): void
    {
        $this->issueFailing(SmsDeliveryException::classified(SmsFailureOutcome::TEMPORARY_FAILURE, SmsFailureReason::PROVIDER_BUSY));

        $event = AuthSecurityEvent::where('event_type', 'OTP_ISSUED')->sole();
        $this->assertSame(['FAILURE', 'DELIVERY_FAILED'], [$event->outcome->value, $event->reason_code]);
        $this->assertSame('TEMPORARY_FAILURE', $event->metadata['delivery_outcome']);
        $this->assertSame('PROVIDER_BUSY', $event->metadata['delivery_reason']);
    }

    public function test_an_unexpected_throwable_is_an_unknown_outcome(): void
    {
        $this->app->instance(SmsSender::class, new class implements SmsSender
        {
            public function send(SmsMessage $message): void
            {
                throw new RuntimeException('provider said: 0591234567 secret-key-123');
            }
        });

        app(OtpChallenges::class)->issue(OtpPurpose::ACTIVATION, $this->person);

        $event = AuthSecurityEvent::where('event_type', 'OTP_ISSUED')->sole();
        $this->assertSame(['UNKNOWN', 'UNEXPECTED_ERROR'], [$event->metadata['delivery_outcome'], $event->metadata['delivery_reason']]);
        // The raw message never reaches the log.
        $this->assertStringNotContainsString('secret-key-123', json_encode($this->logged));
        $this->assertStringNotContainsString(self::MOBILE, json_encode($this->logged));
    }

    public function test_an_ordinary_failure_is_one_safe_warning(): void
    {
        $this->issueFailing(SmsDeliveryException::classified(SmsFailureOutcome::UNKNOWN, SmsFailureReason::TRANSPORT_UNCERTAIN));

        $this->assertCount(1, $this->logged);
        $this->assertSame('warning', $this->logged[0]['level']);
        $this->assertSame([
            'provider' => 'none',
            'outcome' => 'UNKNOWN',
            'reason' => 'TRANSPORT_UNCERTAIN',
            'purpose' => 'ACTIVATION',
            'mobile_last2' => '67',
        ], $this->logged[0]['context']);
    }

    public function test_a_provider_configuration_failure_is_critical_at_most_once_per_window(): void
    {
        config(['family_auth.sms.driver' => 'tweetsms']);
        $credit = SmsDeliveryException::classified(SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::INSUFFICIENT_CREDIT);

        $this->issueFailing($credit);
        $this->travel(61)->seconds();
        $this->issueFailing($credit);

        $critical = array_values(array_filter($this->logged, fn ($l) => $l['level'] === 'critical'));
        $this->assertCount(1, $critical);
        $this->assertSame('tweetsms', $critical[0]['context']['provider']);
        $this->assertSame('INSUFFICIENT_CREDIT', $critical[0]['context']['reason']);

        // Another reason is logged on its own; the same one again after the window.
        $this->issueFailing(SmsDeliveryException::classified(SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::INVALID_SENDER));
        $this->travel(601)->seconds();
        $this->issueFailing($credit);
        $this->assertCount(3, array_filter($this->logged, fn ($l) => $l['level'] === 'critical'));
    }

    public function test_the_log_and_the_event_never_carry_the_destination_body_or_code(): void
    {
        $this->issueFailing(SmsDeliveryException::classified(SmsFailureOutcome::PERMANENT_FAILURE, SmsFailureReason::INVALID_DESTINATION));

        $haystack = json_encode($this->logged).AuthSecurityEvent::all()->toJson();
        $this->assertStringNotContainsString(self::MOBILE, $haystack);
        $this->assertStringNotContainsString('رمز', $haystack);
        $this->assertDoesNotMatchRegularExpression('/(?<!\d)\d{6}(?!\d)/', json_encode($this->logged));
    }

    public function test_the_existing_factories_keep_their_meaning_with_a_class(): void
    {
        $this->assertSame(SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsDeliveryException::unconfigured()->outcome);
        $this->assertSame(SmsFailureReason::NOT_CONFIGURED, SmsDeliveryException::unconfigured()->reason);
        $this->assertSame(SmsFailureReason::UNSAFE_ENVIRONMENT, SmsDeliveryException::unsafeEnvironment()->reason);
        $this->assertSame(SmsFailureReason::LOCAL_WRITE_FAILED, SmsDeliveryException::failed()->reason);
        $this->assertSame('SMS not delivered: UNKNOWN (UNRECOGNIZED_RESULT).', SmsDeliveryException::classified(SmsFailureOutcome::UNKNOWN, SmsFailureReason::UNRECOGNIZED_RESULT)->getMessage());
    }
}
