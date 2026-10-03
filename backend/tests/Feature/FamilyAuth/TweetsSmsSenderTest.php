<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Enums\SmsFailureOutcome;
use App\Enums\SmsFailureReason;
use App\Support\Sms\SmsDeliveryException;
use App\Support\Sms\SmsMessage;
use App\Support\Sms\TweetsSmsSender;
use App\Support\Sms\UnconfiguredSmsSender;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The TweetsMS driver (docs/08 §16a, docs/11 §30a): the exact request, code
 * 999 as the ONLY success, every documented failure classified, and no
 * automatic retry. HTTP is always faked — no test reaches TweetsMS.
 * Synthetic values only.
 */
class TweetsSmsSenderTest extends TestCase
{
    private const ENDPOINT = 'https://www.tweetsms.ps/api.php/office/sendsms';

    private const KEY = 'test-only-tweetsms-key-abc123';

    private const SENDER = 'TestSender';

    private const MOBILE = '0591234567';

    private const BODY = 'رمز التحقق في Famboook: 482913';

    /** The response of a real successful send, as confirmed. */
    private const SUCCESS = ['status' => 'success', 'code' => 999, 'msg' => 'send success', 'cost' => 1, 'messages_count' => 1, 'error_nums' => 0, 'remained_balance' => null];

    protected function setUp(): void
    {
        parent::setUp();
        config(['family_auth.sms.driver' => 'tweetsms', 'family_auth.sms.tweetsms' => [
            'api_key' => self::KEY, 'sender' => self::SENDER, 'endpoint' => self::ENDPOINT, 'connect_timeout' => 3, 'timeout' => 8,
        ]]);
    }

    private function send(string $destination = self::MOBILE, string $body = self::BODY): void
    {
        app(SmsSender::class)->send(new SmsMessage($destination, $body, 'ACTIVATION'));
    }

    private function sendFails(): SmsDeliveryException
    {
        try {
            $this->send();
        } catch (SmsDeliveryException $e) {
            return $e;
        }
        $this->fail('Expected a delivery failure.');
    }

    private function fakeJson(array|string $body, int $status = 200): void
    {
        // A fresh fake each time: Laravel would otherwise keep the first stub.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => Http::response($body, $status, is_array($body) ? [] : ['Content-Type' => 'text/html'])]);
    }

    // ----------------------------------------------------------------- request

    public function test_it_posts_exactly_the_four_fields_as_json_to_the_endpoint(): void
    {
        $this->fakeJson(self::SUCCESS);

        $this->send();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertStringContainsString('application/json', $request->header('Content-Type')[0]);
            $this->assertStringContainsString('application/json', $request->header('Accept')[0]);
            // Exactly these fields; the destination untouched, ONE destination.
            $this->assertSame(['api_key' => self::KEY, 'sender' => self::SENDER, 'message' => self::BODY, 'to' => self::MOBILE], $request->data());
            // The key is in the body only.
            $this->assertStringNotContainsString(self::KEY, $request->url());
            $this->assertStringNotContainsString(self::KEY, json_encode($request->headers()));

            return true;
        });
    }

    public function test_the_local_05_format_is_never_converted(): void
    {
        $this->fakeJson(self::SUCCESS);

        $this->send('0567654321');

        Http::assertSent(fn (Request $r) => $r['to'] === '0567654321'
            && ! str_contains($r->body(), '970')
            && ! array_key_exists('groups', $r->data()) && ! array_key_exists('date', $r->data()) && ! array_key_exists('time', $r->data()));
    }

    public function test_only_one_local_destination_is_ever_sent(): void
    {
        $this->fakeJson(self::SUCCESS);

        foreach (['0591234567,0597654321', '+970591234567', '970591234567', '591234567', '05912345678', ''] as $destination) {
            try {
                $this->send($destination);
                $this->fail("Sent to {$destination}.");
            } catch (SmsDeliveryException $e) {
                $this->assertSame(SmsFailureReason::INVALID_DESTINATION, $e->reason, $destination);
            }
        }
        Http::assertNothingSent();
    }

    // ----------------------------------------------------------------- success

    public function test_code_999_integer_or_string_is_success(): void
    {
        $this->fakeJson(self::SUCCESS);
        $this->send();
        Http::assertSentCount(1);

        $this->fakeJson([...self::SUCCESS, 'code' => '999']);
        $this->send();
        Http::assertSentCount(1);
    }

    public function test_status_success_or_http_2xx_without_code_999_is_not_success(): void
    {
        foreach ([
            [...self::SUCCESS, 'code' => 998],
            ['status' => 'success', 'msg' => 'send success'],
            ['status' => 'success', 'code' => null],
            ['status' => 'success', 'code' => '999.0'],
            ['status' => 'success', 'code' => [999]],
            ['status' => 'success', 'code' => true],
        ] as $body) {
            $this->fakeJson($body);
            $this->assertSame(SmsFailureOutcome::UNKNOWN, $this->sendFails()->outcome, json_encode($body));
        }
        // A float in the raw JSON is not the integer code.
        $this->fakeJson('{"status":"success","code":999.0}');
        $this->assertSame(SmsFailureOutcome::UNKNOWN, $this->sendFails()->outcome);
    }

    // -------------------------------------------------------- provider codes

    /** @return array<string, array{0: int|string, 1: SmsFailureOutcome, 2: SmsFailureReason}> */
    public static function providerCodes(): array
    {
        return [
            '-100' => [-100, SmsFailureOutcome::PERMANENT_FAILURE, SmsFailureReason::MISSING_PARAMETERS],
            '-110' => [-110, SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::INVALID_CREDENTIALS],
            '-111' => [-111, SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::ACCOUNT_INACTIVE],
            '-112' => [-112, SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::ACCOUNT_BLOCKED],
            '-114' => [-114, SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::SENDING_STOPPED],
            '-115' => [-115, SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::INVALID_SENDER],
            '-116' => [-116, SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::INVALID_SENDER],
            '-120' => [-120, SmsFailureOutcome::PERMANENT_FAILURE, SmsFailureReason::INVALID_DESTINATION],
            '-124' => [-124, SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::INSUFFICIENT_CREDIT],
            '-126' => [-126, SmsFailureOutcome::TEMPORARY_FAILURE, SmsFailureReason::PROVIDER_BUSY],
            '-126 as a string' => ['-126', SmsFailureOutcome::TEMPORARY_FAILURE, SmsFailureReason::PROVIDER_BUSY],
            'unknown code' => [-999, SmsFailureOutcome::UNKNOWN, SmsFailureReason::UNRECOGNIZED_RESULT],
            'unknown positive code' => [1, SmsFailureOutcome::UNKNOWN, SmsFailureReason::UNRECOGNIZED_RESULT],
        ];
    }

    #[DataProvider('providerCodes')]
    public function test_each_provider_code_is_classified_and_never_retried(int|string $code, SmsFailureOutcome $outcome, SmsFailureReason $reason): void
    {
        $this->fakeJson(['status' => 'error', 'code' => $code, 'msg' => 'provider text 0591234567']);

        $failure = $this->sendFails();

        $this->assertSame([$outcome, $reason], [$failure->outcome, $failure->reason]);
        Http::assertSentCount(1);
        // The provider's own text never travels further.
        $this->assertStringNotContainsString('provider text', $failure->getMessage());
        $this->assertStringNotContainsString(self::MOBILE, $failure->getMessage());
    }

    // -------------------------------------------------------- response shape

    /** @return array<string, array{0: string, 1: int}> */
    public static function malformed(): array
    {
        return [
            'empty body' => ['', 200],
            'malformed json' => ['{"code": 999', 200],
            'html' => ['<html><body>999</body></html>', 200],
            'plain 999' => ['999', 200],
            'json list' => ['[999]', 200],
            'json string' => ['"999"', 200],
            'unexpected structure' => ['{"result": {"code": 999}}', 200],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_or_unexpected_response_is_unknown(string $body, int $status): void
    {
        Http::fake([self::ENDPOINT => Http::response($body, $status, ['Content-Type' => 'text/plain'])]);

        $failure = $this->sendFails();

        $this->assertSame([SmsFailureOutcome::UNKNOWN, SmsFailureReason::MALFORMED_RESPONSE], [$failure->outcome, $failure->reason]);
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------- transport

    public function test_http_errors_are_classified_and_a_success_body_on_an_error_status_does_not_count(): void
    {
        $this->fakeJson(self::SUCCESS, 500);
        $this->assertSame([SmsFailureOutcome::TEMPORARY_FAILURE, SmsFailureReason::HTTP_SERVER_ERROR], [$this->sendFails()->outcome, $this->sendFails()->reason]);

        $this->fakeJson(self::SUCCESS, 401);
        $failure = $this->sendFails();
        $this->assertSame([SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::HTTP_CLIENT_ERROR], [$failure->outcome, $failure->reason]);

        $this->fakeJson(self::SUCCESS, 302);
        $this->assertSame(SmsFailureReason::UNEXPECTED_HTTP_STATUS, $this->sendFails()->reason);
    }

    public function test_a_timeout_is_unknown_and_is_never_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out after 8000 ms with 0 bytes received');
        });

        $failure = $this->sendFails();

        $this->assertSame([SmsFailureOutcome::UNKNOWN, SmsFailureReason::TRANSPORT_UNCERTAIN], [$failure->outcome, $failure->reason]);
        $this->assertSame(1, $attempts);
        $this->assertStringNotContainsString('cURL', $failure->getMessage());
    }

    public function test_a_connection_that_never_opened_is_temporary_and_still_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            $guzzle = new ConnectException('cURL error 7: Failed to connect', new PsrRequest('POST', self::ENDPOINT), null, ['errno' => 7]);
            throw new ConnectionException($guzzle->getMessage(), 0, $guzzle);
        });

        $failure = $this->sendFails();

        $this->assertSame([SmsFailureOutcome::TEMPORARY_FAILURE, SmsFailureReason::CONNECTION_FAILED], [$failure->outcome, $failure->reason]);
        $this->assertSame(1, $attempts);
    }

    public function test_the_request_uses_tls_verification_timeouts_and_no_redirects(): void
    {
        $options = null;
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(self::SUCCESS);
        });

        $this->send();

        $this->assertTrue($options['verify']);
        $this->assertSame(3, $options['connect_timeout']);
        $this->assertSame(8, $options['timeout']);
        $this->assertFalse($options['allow_redirects']);
    }

    // ----------------------------------------------------------------- config

    /** @return array<string, array{0: array}> */
    public static function incompleteConfig(): array
    {
        return [
            'no key' => [['api_key' => null, 'sender' => self::SENDER]],
            'empty key' => [['api_key' => '  ', 'sender' => self::SENDER]],
            'no sender' => [['api_key' => self::KEY, 'sender' => null]],
            'empty sender' => [['api_key' => self::KEY, 'sender' => '']],
            'plain http endpoint' => [['api_key' => self::KEY, 'sender' => self::SENDER, 'endpoint' => 'http://www.tweetsms.ps/api.php/office/sendsms']],
        ];
    }

    #[DataProvider('incompleteConfig')]
    public function test_an_incomplete_configuration_fails_closed_without_any_request(array $config): void
    {
        Http::fake();
        config(['family_auth.sms.tweetsms' => [...['endpoint' => self::ENDPOINT, 'connect_timeout' => 3, 'timeout' => 8], ...$config]]);

        $this->assertInstanceOf(TweetsSmsSender::class, app(SmsSender::class));
        $failure = $this->sendFails();

        $this->assertSame([SmsFailureOutcome::PROVIDER_CONFIGURATION_FAILURE, SmsFailureReason::NOT_CONFIGURED], [$failure->outcome, $failure->reason]);
        Http::assertNothingSent();
    }

    public function test_an_incomplete_configuration_does_not_take_the_application_down(): void
    {
        config(['family_auth.sms.tweetsms' => ['api_key' => null, 'sender' => null]]);

        $this->getJson('/api/v1/health')->assertOk();
        $this->assertFalse(app(SmsSender::class)->configured());
    }

    public function test_an_unknown_driver_is_still_unconfigured(): void
    {
        foreach (['TweetsMS', 'tweet', 'sms'] as $driver) {
            config(['family_auth.sms.driver' => $driver]);
            $this->assertInstanceOf(UnconfiguredSmsSender::class, app(SmsSender::class), $driver);
        }
    }

    public function test_the_defaults_and_the_templates_carry_no_secret(): void
    {
        $config = (require base_path('config/family_auth.php'))['sms']['tweetsms'];
        $this->assertSame(self::ENDPOINT, $config['endpoint']);
        $this->assertSame([3, 8], [$config['connect_timeout'], $config['timeout']]);

        foreach ([base_path('.env.example'), base_path('../deploy/env/backend.production.env.example')] as $template) {
            $text = (string) file_get_contents($template);
            $this->assertMatchesRegularExpression('/^TWEETSMS_API_KEY=\r?$/m', $text, $template);
            $this->assertMatchesRegularExpression('/^TWEETSMS_SENDER=\r?$/m', $text, $template);
            $this->assertMatchesRegularExpression('/^FAMILY_SMS_DRIVER=\r?$/m', $text, $template);
        }
    }

    public function test_no_stray_request_can_leave_a_test(): void
    {
        // Without Http::fake(), the global guard refuses any real request.
        $this->expectException(\RuntimeException::class);
        Http::post(self::ENDPOINT, ['probe' => true]);
    }
}
