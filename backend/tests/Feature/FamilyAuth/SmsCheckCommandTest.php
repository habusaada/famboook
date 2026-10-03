<?php

namespace Tests\Feature\FamilyAuth;

use App\Console\Commands\SmsCheck;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `famboook:sms-check` (docs/08 §16a): reports the SMS configuration as
 * YES/NO only and sends nothing unless --send-test is given; the test SMS is
 * a fixed non-OTP text and the full number, key and sender are never
 * printed. HTTP is faked; synthetic values only.
 */
class SmsCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'synthetic-api-key-0000';

    private const SENDER = 'SynthSender';

    private const MOBILE = '0591234567';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'family_auth.sms.driver' => 'tweetsms',
            'family_auth.sms.tweetsms' => [
                'api_key' => self::KEY,
                'sender' => self::SENDER,
                'endpoint' => 'https://www.tweetsms.ps/api.php/office/sendsms',
                'connect_timeout' => 3,
                'timeout' => 8,
            ],
        ]);
    }

    private function providerAnswers(int|string $code): void
    {
        Http::fake(['*' => Http::response(['status' => 'x', 'code' => $code])]);
    }

    public function test_the_default_check_reports_presence_only_and_sends_nothing(): void
    {
        Http::fake();

        $this->artisan('famboook:sms-check')
            ->expectsOutputToContain('Driver:  tweetsms')
            ->expectsOutputToContain('API key: YES')
            ->expectsOutputToContain('Sender:  YES')
            ->expectsOutputToContain('Configuration: OK')
            ->doesntExpectOutputToContain(self::KEY)
            ->doesntExpectOutputToContain(self::SENDER)
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_missing_key_or_sender_is_reported_as_incomplete(): void
    {
        Http::fake();
        config(['family_auth.sms.tweetsms.api_key' => '', 'family_auth.sms.tweetsms.sender' => null]);

        $this->artisan('famboook:sms-check')
            ->expectsOutputToContain('API key: NO')
            ->expectsOutputToContain('Sender:  NO')
            ->expectsOutputToContain('INCOMPLETE')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_empty_driver_is_reported(): void
    {
        config(['family_auth.sms.driver' => null]);

        $this->artisan('famboook:sms-check')
            ->expectsOutputToContain('Driver:  (none)')
            ->expectsOutputToContain('INCOMPLETE')
            ->assertFailed();
    }

    public function test_an_incomplete_configuration_refuses_the_test_send(): void
    {
        Http::fake();
        config(['family_auth.sms.tweetsms.api_key' => '']);

        $this->artisan('famboook:sms-check', ['--send-test' => self::MOBILE, '--no-interaction' => true])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_the_test_send_requires_the_local_format(): void
    {
        Http::fake();

        foreach (['970591234567', '+970591234567', '059123456', '05912345678', '0691234567', 'abc'] as $bad) {
            $this->artisan('famboook:sms-check', ['--send-test' => $bad, '--no-interaction' => true])
                ->expectsOutputToContain('05 followed by 8 digits')
                ->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_the_test_send_sends_one_fixed_non_otp_message(): void
    {
        $this->providerAnswers(999);

        $this->artisan('famboook:sms-check', ['--send-test' => self::MOBILE])
            ->expectsConfirmation('Send one test SMS to 05******67?', 'yes')
            ->expectsOutputToContain('SENT to 05******67')
            ->doesntExpectOutputToContain(self::MOBILE)
            ->doesntExpectOutputToContain(self::KEY)
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->data() === [
            'api_key' => self::KEY,
            'sender' => self::SENDER,
            'message' => SmsCheck::TEST_MESSAGE,
            'to' => self::MOBILE,
        ]);
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', SmsCheck::TEST_MESSAGE, 'The test SMS carries no code.');
        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame(0, AuthSecurityEvent::count());
    }

    public function test_declining_the_confirmation_sends_nothing(): void
    {
        Http::fake();

        $this->artisan('famboook:sms-check', ['--send-test' => self::MOBILE])
            ->expectsConfirmation('Send one test SMS to 05******67?', 'no')
            ->expectsOutputToContain('Nothing sent.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_a_provider_refusal_prints_only_the_safe_outcome(): void
    {
        $this->providerAnswers(-124);

        $this->artisan('famboook:sms-check', ['--send-test' => self::MOBILE, '--no-interaction' => true])
            ->expectsOutputToContain('NOT SENT: PROVIDER_CONFIGURATION_FAILURE (INSUFFICIENT_CREDIT)')
            ->doesntExpectOutputToContain(self::MOBILE)
            ->doesntExpectOutputToContain(self::KEY)
            ->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_a_success_without_code_999_is_not_sent(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success', 'msg' => 'send success'])]);

        $this->artisan('famboook:sms-check', ['--send-test' => self::MOBILE, '--no-interaction' => true])
            ->expectsOutputToContain('NOT SENT: UNKNOWN (MALFORMED_RESPONSE)')
            ->assertFailed();
    }
}
