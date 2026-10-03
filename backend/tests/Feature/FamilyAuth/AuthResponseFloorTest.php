<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I, finding A-2: verify and complete wait out the same response floor
 * as start and resend, for EVERY outcome — real or decoy, right or wrong
 * code, locked, expired, consumed, refused or successful — so the database
 * work of a real challenge does not show in the response time. Sleep is
 * faked; the floor is set far above any real duration so that every floored
 * request sleeps exactly once. Synthetic data only.
 */
class AuthResponseFloorTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION_ID = '123456789';

    private const RESET_ID = '223456789';

    private const UNKNOWN_ID = '987654321';

    private const PASSWORD = 'synthetic-pass-1';

    private FakeSmsSender $sms;

    private int $slept = 0;

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
        return [
            'activation' => [self::ACTIVATION, self::ACTIVATION_ID],
            'password reset' => [self::RESET, self::RESET_ID],
        ];
    }

    /** Starts without the floor; the floor is switched on afterwards. */
    private function start(string $base, string $nationalId): string
    {
        return $this->postJson($base.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
    }

    private function floorOn(): void
    {
        Sleep::fake();
        config(['family_auth.activation.min_response_ms' => 60_000]);
    }

    /** The request, and proof it slept exactly once — the floor. */
    private function floored(TestResponse $response): TestResponse
    {
        $this->slept++;
        Sleep::assertSleptTimes($this->slept);

        return $response;
    }

    private function verify(string $base, string $reference, string $code): TestResponse
    {
        return $this->floored($this->postJson($base.'/verify', ['challenge' => $reference, 'code' => $code]));
    }

    private function complete(string $base, string $reference, bool $session = true): TestResponse
    {
        return $this->floored($this->postJson(
            $base.'/complete',
            ['challenge' => $reference, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD],
            $session ? ['Referer' => 'http://localhost:3000'] : [],
        ));
    }

    private function wrong(): string
    {
        return $this->sms->lastCode() === '000000' ? '111111' : '000000';
    }

    #[DataProvider('flows')]
    public function test_verify_is_floored_for_every_outcome_real_or_decoy(string $base, string $nationalId): void
    {
        $real = $this->start($base, $nationalId);
        $code = $this->sms->lastCode();
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $other = $this->start($base, self::UNKNOWN_ID === '987654321' ? '987654322' : '987654321');
        $this->floorOn();

        $this->verify($base, $decoy, '000000')->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->verify($base, $real, $this->wrong())->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->verify($base, $real, $code)->assertOk();
        // Locked: five wrong codes on the decoy.
        for ($i = 0; $i < 4; $i++) {
            $this->verify($base, $decoy, '000000');
        }
        $this->verify($base, $decoy, '000000')->assertJsonPath('code', 'OTP_LOCKED');
        // Unknown reference.
        $this->verify($base, '00000000-0000-4000-8000-000000000000', '000000')->assertJsonPath('code', 'OTP_INVALID');
        // Expired.
        $this->travel(301)->seconds();
        $this->verify($base, $other, '000000')->assertJsonPath('code', 'OTP_EXPIRED');
    }

    #[DataProvider('flows')]
    public function test_complete_is_floored_for_every_outcome_real_or_decoy(string $base, string $nationalId): void
    {
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $real = $this->start($base, $nationalId);
        $code = $this->sms->lastCode();
        $this->floorOn();

        // A decoy, and a real challenge not verified yet.
        $this->complete($base, $decoy)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->complete($base, $real)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        // No session: refused before anything.
        $this->verify($base, $real, $code)->assertOk();
        $this->complete($base, $real, session: false)->assertClientError()->assertJsonStructure(['code']);
        // The account itself, then the consumed grant.
        $this->complete($base, $real)->assertSuccessful();
        $this->complete($base, $real)->assertClientError()->assertJsonStructure(['code']);
    }

    #[DataProvider('flows')]
    public function test_a_floor_of_zero_never_sleeps(string $base, string $nationalId): void
    {
        $real = $this->start($base, $nationalId);
        Sleep::fake();

        $this->postJson($base.'/verify', ['challenge' => $real, 'code' => $this->wrong()]);
        $this->postJson($base.'/complete', ['challenge' => $real, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);

        Sleep::assertNeverSlept();
    }
}
