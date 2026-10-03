<?php

namespace Tests\Feature\FamilyAuth;

use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I: one sweep through the whole Family Auth flow — activation (decoy
 * and real, wrong and right code), login (wrong and right), password reset,
 * and a TweetsMS failure that logs CRITICAL — through the REAL TweetsMS
 * driver with faked HTTP. Afterwards nothing secret may be found in the
 * application log, the security events (every column), the OTP challenge
 * rows or the queue tables: no National ID, OTP, password, API key, sender,
 * full mobile, SMS text, session id, CSRF token or fingerprint key.
 * auth_otp_challenges.ip holds the raw client IP by design (90-day
 * retention). Synthetic data only.
 */
class FamilyAuthLeakageSweepTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION_ID = '123456789';

    private const UNKNOWN_ID = '987654321';

    private const MOBILE = '0591234567';

    private const PASSWORD = 'first-pass-phrase-1';

    private const NEW_PASSWORD = 'second-pass-phrase-2';

    private const WRONG_PASSWORD = 'wrong-pass-phrase-3';

    private const API_KEY = 'synthetic-api-key-7f3a9c';

    private const SENDER = 'SynthSender';

    private const BROWSER = ['Referer' => 'http://localhost:3000'];

    /** @var list<string> */
    private array $logged = [];

    /** @var list<string> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config([
            'family_auth.activation_enabled' => true,
            'family_auth.login_enabled' => true,
            'family_auth.password_reset_enabled' => true,
            'family_auth.activation.min_response_ms' => 0,
            'family_auth.sms.driver' => 'tweetsms',
            'family_auth.sms.tweetsms.api_key' => self::API_KEY,
            'family_auth.sms.tweetsms.sender' => self::SENDER,
        ]);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->level.' '.$e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
        });
        [$head] = $this->eligibleHead(self::ACTIVATION_ID);
        $this->trustedMobile($head, self::MOBILE);
        $this->freezeSecond();
    }

    private function provider(int $code): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => $code])]);
    }

    /** The OTP of the last SMS handed to TweetsMS (read from the faked request only). */
    private function lastCode(): string
    {
        $request = Http::recorded()->last()[0];
        $this->assertInstanceOf(Request::class, $request);
        $this->bodies[] = $request->data()['message'];
        preg_match('/(?<!\d)(\d{6})(?!\d)/', $request->data()['message'], $m);

        return $m[1];
    }

    public function test_nothing_secret_is_logged_or_stored_anywhere_in_the_flow(): void
    {
        $codes = [];
        $this->provider(999);

        // Activation: a decoy, then the real head with a wrong and a right code.
        $this->postJson(self::ACTIVATION.'/start', ['national_id' => self::UNKNOWN_ID])->assertOk();
        $reference = $this->postJson(self::ACTIVATION.'/start', ['national_id' => self::ACTIVATION_ID])->assertOk()->json('challenge');
        $codes[] = $code = $this->lastCode();
        $this->postJson(self::ACTIVATION.'/verify', ['challenge' => $reference, 'code' => $code === '000000' ? '111111' : '000000']);
        $this->postJson(self::ACTIVATION.'/verify', ['challenge' => $reference, 'code' => $code])->assertOk();
        $activated = $this->postJson(self::ACTIVATION.'/complete', ['challenge' => $reference, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], self::BROWSER)->assertCreated();
        $sessionIds = [(string) $activated->getCookie(config('session.cookie'))?->getValue()];
        $csrf = [(string) $activated->getCookie('XSRF-TOKEN', false)?->getValue()];

        // Login: wrong, then right.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/family/auth/login', ['national_id' => self::ACTIVATION_ID, 'password' => self::WRONG_PASSWORD], self::BROWSER)->assertStatus(401);
        $login = $this->postJson('/api/v1/family/auth/login', ['national_id' => self::ACTIVATION_ID, 'password' => self::PASSWORD], self::BROWSER)->assertOk();
        $sessionIds[] = (string) $login->getCookie(config('session.cookie'))?->getValue();

        // Password reset.
        $reset = $this->postJson(self::RESET.'/start', ['national_id' => self::ACTIVATION_ID])->assertOk()->json('challenge');
        $codes[] = $code = $this->lastCode();
        $this->postJson(self::RESET.'/verify', ['challenge' => $reset, 'code' => $code])->assertOk();
        $this->postJson(self::RESET.'/complete', ['challenge' => $reset, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], self::BROWSER)->assertOk();

        // TweetsMS refuses (no credit): a CRITICAL log line, after the response.
        $this->travel(61)->seconds();
        $this->provider(-124);
        $this->postJson(self::RESET.'/start', ['national_id' => self::ACTIVATION_ID])->assertOk();
        $codes[] = $this->lastCode();

        $haystacks = [
            'log' => implode("\n", $this->logged),
            'events' => AuthSecurityEvent::all()->map(fn ($e) => json_encode($e->getAttributes(), JSON_UNESCAPED_UNICODE))->implode("\n"),
            'challenges' => AuthOtpChallenge::all()->map(fn ($c) => json_encode($c->getAttributes()))->implode("\n"),
            'jobs' => DB::table('jobs')->get()->toJson().DB::table('failed_jobs')->get()->toJson(),
        ];
        $secrets = [
            'National ID' => self::ACTIVATION_ID,
            'unknown National ID' => self::UNKNOWN_ID,
            'password' => self::PASSWORD,
            'new password' => self::NEW_PASSWORD,
            'wrong password' => self::WRONG_PASSWORD,
            'API key' => self::API_KEY,
            'sender' => self::SENDER,
            'mobile' => self::MOBILE,
            'fingerprint key' => (string) config('family_auth.fingerprint.key'),
            ...array_combine(array_map(fn ($i) => "SMS text {$i}", array_keys($this->bodies)), $this->bodies),
            ...array_combine(array_map(fn ($i) => "session id {$i}", array_keys($sessionIds)), $sessionIds),
            ...array_combine(array_map(fn ($i) => "CSRF token {$i}", array_keys($csrf)), $csrf),
        ];

        $this->assertStringContainsString('CRITICAL', strtoupper($haystacks['log']), 'The provider failure was logged.');
        $this->assertStringContainsString('INSUFFICIENT_CREDIT', $haystacks['log']);
        foreach ($haystacks as $where => $haystack) {
            foreach ($secrets as $what => $secret) {
                if ($secret === '') {
                    continue;
                }
                $this->assertStringNotContainsString($secret, $haystack, "{$what} found in {$where}");
            }
            // A six-digit code may occur by chance INSIDE a hex digest; as a
            // value of its own it must not occur at all.
            foreach ($codes as $i => $code) {
                $this->assertDoesNotMatchRegularExpression('/(?<![0-9a-f])'.$code.'(?![0-9a-f])/i', $haystack, "OTP {$i} found in {$where}");
            }
        }
        // Allow-listed metadata keys only.
        foreach (AuthSecurityEvent::all() as $event) {
            $this->assertSame([], array_diff(array_keys($event->metadata ?? []), AuthSecurityEvent::ALLOWED_METADATA));
        }
        // The raw client IP is kept on the challenge rows only (90-day retention).
        $this->assertSame('127.0.0.1', AuthOtpChallenge::query()->first()->ip);
        $this->assertNotEmpty(array_filter($sessionIds));
    }
}
