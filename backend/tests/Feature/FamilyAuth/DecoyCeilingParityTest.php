<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Support\FamilyAuth\OtpThrottle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I, finding A-3: once the IP or the global OTP SMS ceiling is reached,
 * a real resend is refused — so a decoy resend must be refused the same way,
 * without counting anything on the real SMS counters. (The destination
 * ceilings cannot be mirrored: a decoy has no destination.) Synthetic data.
 */
class DecoyCeilingParityTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION_ID = '123456789';

    private const RESET_ID = '223456789';

    private const UNKNOWN_ID = '987654321';

    private FakeSmsSender $sms;

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

        [$head] = $this->eligibleHead(self::ACTIVATION_ID);
        $this->trustedMobile($head, '0591234567');
        $account = $this->activatedHead(self::RESET_ID);
        $this->trustedMobile($account['person'], '0597654321');
        $this->freezeSecond();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function flows(): array
    {
        // Password reset only: first activation hands out no decoy since
        // FP-ADR-054 (a refused start answers ACTIVATION_REFUSED).
        return [
            'password reset' => [self::RESET, self::RESET_ID],
        ];
    }

    private function start(string $base, string $nationalId): string
    {
        // Activation confirms the masked number first (FP-ADR-053).
        return str_ends_with($base, '/activation')
            ? $this->startActivationChallenge($nationalId)
            : $this->postJson($base.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
    }

    private function resend(string $base, string $reference): TestResponse
    {
        return $this->postJson($base.'/resend', ['challenge' => $reference]);
    }

    private static function ipKey(): string
    {
        return 'family-otp|ip|'.hash('sha256', '127.0.0.1').'|hour';
    }

    #[DataProvider('flows')]
    public function test_an_exhausted_ip_ceiling_refuses_real_and_decoy_resends_alike(string $base, string $nationalId): void
    {
        config(['family_auth.throttle.ip.hour' => 1]);
        $real = $this->start($base, $nationalId);   // the one real SMS this IP may send
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $this->travel(61)->seconds();

        $realAnswer = $this->resend($base, $real)->assertStatus(429)->assertJsonPath('code', 'OTP_SEND_LIMIT');
        $decoyAnswer = $this->resend($base, $decoy)->assertStatus(429)->assertJsonPath('code', 'OTP_SEND_LIMIT');

        $this->assertSame($realAnswer->json(), $decoyAnswer->json());
        $this->assertSame(1, $this->sms->attempts);
    }

    #[DataProvider('flows')]
    public function test_an_exhausted_global_ceiling_refuses_real_and_decoy_resends_alike(string $base, string $nationalId): void
    {
        config(['family_auth.throttle.global.hour' => 1]);
        $real = $this->start($base, $nationalId);
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $this->travel(61)->seconds();

        $realAnswer = $this->resend($base, $real)->assertStatus(429)->assertJsonPath('code', 'OTP_SEND_LIMIT');
        $decoyAnswer = $this->resend($base, $decoy)->assertStatus(429)->assertJsonPath('code', 'OTP_SEND_LIMIT');

        $this->assertSame($realAnswer->json(), $decoyAnswer->json());
    }

    #[DataProvider('flows')]
    public function test_below_the_ceilings_real_and_decoy_resends_answer_alike(string $base, string $nationalId): void
    {
        $real = $this->start($base, $nationalId);
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $this->travel(61)->seconds();

        $realAnswer = $this->resend($base, $real)->assertOk();
        $decoyAnswer = $this->resend($base, $decoy)->assertOk();

        $this->assertSame($realAnswer->json(), $decoyAnswer->json());
    }

    #[DataProvider('flows')]
    public function test_a_decoy_resend_counts_nothing_on_the_real_sms_ceilings(string $base, string $nationalId): void
    {
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $this->travel(61)->seconds();
        $ip = RateLimiter::attempts(self::ipKey());
        $global = RateLimiter::attempts('family-otp|global|all|hour');

        $this->resend($base, $decoy)->assertOk();

        $this->assertSame($ip, RateLimiter::attempts(self::ipKey()));
        $this->assertSame($global, RateLimiter::attempts('family-otp|global|all|hour'));
        $this->assertSame(0, $this->sms->attempts);
    }

    public function test_the_shared_ceiling_check_is_read_only_and_fails_closed(): void
    {
        $throttle = app(OtpThrottle::class);
        config(['family_auth.throttle.ip.hour' => 2, 'family_auth.throttle.global.hour' => 500]);

        $this->assertFalse($throttle->sharedCeilingReached('127.0.0.1'));
        $this->assertSame(0, RateLimiter::attempts(self::ipKey()));

        RateLimiter::hit(self::ipKey(), 3600);
        RateLimiter::hit(self::ipKey(), 3600);
        $this->assertTrue($throttle->sharedCeilingReached('127.0.0.1'));
        $this->assertFalse($throttle->sharedCeilingReached('10.0.0.9'), 'Another IP is not limited.');

        config(['family_auth.throttle.global.hour' => 0]);
        $this->assertTrue($throttle->sharedCeilingReached(null), 'A ceiling below 1 blocks every send.');
    }
}
