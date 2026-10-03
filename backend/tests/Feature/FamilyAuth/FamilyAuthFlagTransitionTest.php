<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I: a Family Auth flag switched off in the middle of a flow. The
 * approved contract (docs/08 §16a): a closed surface answers 503 and does
 * NOTHING — no lookup, no counted attempt, no event, no SMS, no account, no
 * password change. A challenge issued before is left exactly as it was.
 * Synthetic data only.
 */
class FamilyAuthFlagTransitionTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION_ID = '123456789';

    private const RESET_ID = '223456789';

    private const PASSWORD = 'synthetic-pass-1';

    private FakeSmsSender $sms;

    /** @var array<string, mixed> */
    private array $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config([
            'family_auth.activation_enabled' => true,
            'family_auth.password_reset_enabled' => true,
            'family_auth.login_enabled' => true,
            'family_auth.activation.min_response_ms' => 0,
        ]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);

        [$head] = $this->eligibleHead(self::ACTIVATION_ID);
        $this->trustedMobile($head, '0591234567');
        $this->account = $this->activatedHead(self::RESET_ID);
        $this->account['user']->forceFill(['password' => Hash::make('old-password-1')])->save();
        $this->trustedMobile($this->account['person'], '0597654321');
        $this->freezeSecond();
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function flows(): array
    {
        return [
            'activation' => [self::ACTIVATION, self::ACTIVATION_ID, 'family_auth.activation_enabled', 'ACTIVATION_UNAVAILABLE'],
            'password reset' => [self::RESET, self::RESET_ID, 'family_auth.password_reset_enabled', 'PASSWORD_RESET_UNAVAILABLE'],
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'challenge' => AuthOtpChallenge::query()->first()?->only(['attempts', 'send_count', 'verified_at', 'consumed_at', 'code_hash']),
            'events' => AuthSecurityEvent::count(),
            'users' => User::count(),
            'sms' => $this->sms->attempts,
            'password' => $this->account['user']->fresh()->password,
        ];
    }

    #[DataProvider('flows')]
    public function test_switched_off_after_start_every_later_step_does_nothing(string $base, string $nationalId, string $flag, string $code): void
    {
        $reference = $this->postJson($base.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
        $this->travel(61)->seconds();
        config([$flag => false]);
        $before = $this->snapshot();

        $this->postJson($base.'/verify', ['challenge' => $reference, 'code' => '000000'])->assertStatus(503)->assertJsonPath('code', $code);
        $this->postJson($base.'/resend', ['challenge' => $reference])->assertStatus(503)->assertJsonPath('code', $code);
        $this->postJson($base.'/complete', ['challenge' => $reference, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], ['Referer' => 'http://localhost:3000'])
            ->assertStatus(503)->assertJsonPath('code', $code);
        $this->postJson($base.'/start', ['national_id' => $nationalId])->assertStatus(503)->assertJsonPath('code', $code);

        $this->assertEquals($before, $this->snapshot());
    }

    #[DataProvider('flows')]
    public function test_switched_off_after_verify_the_grant_is_not_used(string $base, string $nationalId, string $flag, string $code): void
    {
        $reference = $this->postJson($base.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
        $this->postJson($base.'/verify', ['challenge' => $reference, 'code' => $this->sms->lastCode()])->assertOk();
        config([$flag => false]);
        $before = $this->snapshot();

        $this->postJson($base.'/complete', ['challenge' => $reference, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD], ['Referer' => 'http://localhost:3000'])
            ->assertStatus(503)->assertJsonPath('code', $code);

        // No account, no new password, the grant still unconsumed.
        $this->assertEquals($before, $this->snapshot());
        $this->assertNull(AuthOtpChallenge::sole()->consumed_at);
        $this->assertTrue(Hash::check('old-password-1', $this->account['user']->fresh()->password));
    }

    public function test_login_switched_off_refuses_before_any_lookup_or_counting(): void
    {
        config(['family_auth.login_enabled' => false]);
        $events = AuthSecurityEvent::count();

        $this->postJson('/api/v1/family/auth/login', ['national_id' => self::RESET_ID, 'password' => 'old-password-1'], ['Referer' => 'http://localhost:3000'])
            ->assertStatus(503)->assertJsonPath('code', 'FAMILY_AUTH_UNAVAILABLE');

        $this->assertSame($events, AuthSecurityEvent::count());
        $this->assertGuest('web');
    }

    public function test_switching_one_flag_off_leaves_the_others_working(): void
    {
        config(['family_auth.activation_enabled' => false]);

        $this->postJson(self::RESET.'/start', ['national_id' => self::RESET_ID])->assertOk();
        $this->postJson('/api/v1/family/auth/login', ['national_id' => self::RESET_ID, 'password' => 'old-password-1'], ['Referer' => 'http://localhost:3000'])->assertOk();
    }
}
