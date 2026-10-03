<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\FamilyAuthIdentity;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * `famboook:family-auth-check` (PWA-1I): read-only, counts and YES/NO only.
 * It detects a missing AND a wrong fingerprint key (finding N-2) and the
 * runtime settings that would make Family Auth unsafe or nonfunctional.
 * Synthetic values only.
 */
class FamilyAuthCheckCommandTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const NATIONAL_ID = '123456789';

    private const MOBILE = '0591234567';

    private FakeSmsSender $sms;

    /** @var array<string, mixed> */
    private array $head;

    private PersonMobileTrust $trust;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        $this->head = $this->activatedHead(self::NATIONAL_ID);
        $this->trust = $this->trustedMobile($this->head['person'], self::MOBILE);
        // A Production-shaped runtime, so only what a test changes warns.
        config([
            'session.driver' => 'database',
            'session.http_only' => true,
            'session.same_site' => 'lax',
            'cache.default' => 'database',
        ]);
    }

    private function check(): PendingCommand
    {
        return $this->artisan('famboook:family-auth-check');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'identities' => FamilyAuthIdentity::count(),
            'trusts' => PersonMobileTrust::count(),
            'challenges' => AuthOtpChallenge::count(),
            'events' => AuthSecurityEvent::count(),
            'users' => User::count(),
            'links' => UserPersonLink::count(),
        ];
    }

    public function test_a_consistent_installation_reports_counts_and_no_warning(): void
    {
        $this->check()
            ->expectsOutputToContain('Key configured:         YES')
            ->expectsOutputToContain('Key valid (>= 32 bytes): YES')
            ->expectsOutputToContain('ACTIVE identities:      1')
            ->expectsOutputToContain('TRUSTED mobiles:        1')
            ->expectsOutputToContain('Not matching:           0')
            ->expectsOutputToContain('no warnings')
            ->assertSuccessful();
    }

    public function test_a_wrong_fingerprint_key_is_detected(): void
    {
        // Same version, different secret: the N-2 case.
        config(['family_auth.fingerprint.key' => str_repeat('w', 40)]);

        $this->check()
            ->expectsOutputToContain('Key valid (>= 32 bytes): YES')
            ->expectsOutputToContain('1 ACTIVE identity does not match the Person\'s current National ID under the configured key — a WRONG fingerprint key')
            ->expectsOutputToContain('1 TRUSTED mobile does not match')
            ->assertFailed();
    }

    public function test_a_missing_key_is_reported_and_nothing_can_be_checked(): void
    {
        config(['family_auth.fingerprint.key' => null]);

        $this->check()
            ->expectsOutputToContain('Key configured:         NO')
            ->expectsOutputToContain('FAMILY_AUTH_FINGERPRINT_KEY is not configured')
            ->expectsOutputToContain('cannot be checked without a usable fingerprint key')
            ->assertFailed();
    }

    public function test_a_short_key_and_a_broken_previous_key_are_reported(): void
    {
        config([
            'family_auth.fingerprint.key' => 'too-short',
            'family_auth.fingerprint.previous_key' => str_repeat('p', 40),
            'family_auth.fingerprint.previous_key_version' => 1,
        ]);

        $this->check()
            ->expectsOutputToContain('Key valid (>= 32 bytes): NO')
            ->expectsOutputToContain('Previous key:           configured, INVALID')
            ->assertFailed();
    }

    public function test_a_number_changed_without_the_trust_becoming_stale_is_reported(): void
    {
        $this->head['person']->forceFill(['mobile' => '0599999999'])->saveQuietly();

        $this->check()->expectsOutputToContain('1 TRUSTED mobile does not match')->assertFailed();
    }

    public function test_unsafe_runtime_settings_are_warned_about(): void
    {
        config([
            'family_auth.activation_enabled' => true,
            'family_auth.login_enabled' => false,
            'family_auth.sms.driver' => null,
            'session.driver' => 'file',
            'session.same_site' => 'none',
            'cache.default' => 'array',
        ]);

        $this->check()
            ->expectsOutputToContain('no SMS can be delivered')
            ->expectsOutputToContain('Activation is enabled without login')
            ->expectsOutputToContain('SESSION_DRIVER is not database')
            ->expectsOutputToContain('SESSION_SAME_SITE is not lax or strict')
            ->expectsOutputToContain('CACHE_STORE keeps nothing')
            ->assertFailed();
    }

    public function test_the_check_is_read_only_and_sends_nothing(): void
    {
        Http::fake();
        $before = $this->counts();

        $this->check()->assertSuccessful();
        config(['family_auth.fingerprint.key' => str_repeat('w', 40)]);
        $this->check()->assertFailed();

        $this->assertSame($before, $this->counts());
        $this->assertSame('ACTIVE', $this->head['identity']->fresh()->status->value);
        $this->assertSame('TRUSTED', $this->trust->fresh()->status->value);
        $this->assertSame(0, $this->sms->attempts);
        Http::assertNothingSent();
    }

    public function test_no_identifier_mobile_fingerprint_or_secret_is_printed(): void
    {
        config([
            'family_auth.sms.driver' => 'tweetsms',
            'family_auth.sms.tweetsms.api_key' => 'synthetic-api-key-0000',
            'family_auth.sms.tweetsms.sender' => 'SynthSender',
        ]);
        $secrets = [
            self::NATIONAL_ID,
            self::MOBILE,
            (string) config('family_auth.fingerprint.key'),
            $this->head['identity']->login_key,
            $this->trust->mobile_fingerprint,
            'synthetic-api-key-0000',
            'SynthSender',
        ];

        $pending = $this->check()->expectsOutputToContain('TweetsMS configured:    YES');
        foreach ($secrets as $secret) {
            $pending->doesntExpectOutputToContain($secret);
        }
        $pending->assertSuccessful();
    }
}
