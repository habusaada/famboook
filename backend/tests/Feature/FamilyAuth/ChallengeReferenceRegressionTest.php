<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Models\AuthOtpChallenge;
use App\Support\FamilyAuth\ChallengeDecoys;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I: a challenge reference — real or decoy — is a random UUID v4 and
 * looks, lives and behaves across purposes exactly alike. Guards against a
 * real reference silently becoming a time-ordered UUIDv7 (the model's
 * HasUuids default) if the explicit Str::uuid() in OtpChallenges::issue were
 * ever removed: that alone would be an enumeration oracle. Synthetic data.
 */
class ChallengeReferenceRegressionTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION_ID = '123456789';

    private const RESET_ID = '223456789';

    private const UNKNOWN_ID = '987654321';

    private const UUID_V4 = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

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
        $this->app->instance(SmsSender::class, new FakeSmsSender);

        [$head] = $this->eligibleHead(self::ACTIVATION_ID);
        $this->trustedMobile($head, '0591234567');
        $account = $this->activatedHead(self::RESET_ID);
        $this->trustedMobile($account['person'], '0597654321');
        $this->freezeSecond();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function flows(): array
    {
        return [
            'activation' => [self::ACTIVATION, self::ACTIVATION_ID, self::RESET],
            'password reset' => [self::RESET, self::RESET_ID, self::ACTIVATION],
        ];
    }

    private function start(string $base, string $nationalId): string
    {
        // Activation confirms the masked number first (FP-ADR-053).
        return str_ends_with($base, '/activation')
            ? $this->startActivationChallenge($nationalId)
            : $this->postJson($base.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
    }

    private function verify(string $base, string $reference): TestResponse
    {
        return $this->postJson($base.'/verify', ['challenge' => $reference, 'code' => '000000']);
    }

    #[DataProvider('flows')]
    public function test_real_and_decoy_references_are_both_uuid_v4(string $base, string $nationalId): void
    {
        $real = $this->start($base, $nationalId);
        $decoy = $this->start($base, self::UNKNOWN_ID);

        $this->assertMatchesRegularExpression(self::UUID_V4, $real);
        $this->assertMatchesRegularExpression(self::UUID_V4, $decoy);
        $this->assertSame($real, AuthOtpChallenge::sole()->uuid);
        $this->assertSame(strlen($real), strlen($decoy));
    }

    public function test_many_real_references_carry_no_time_order(): void
    {
        $references = [];
        for ($i = 0; $i < 5; $i++) {
            $references[] = $this->start(self::ACTIVATION, self::ACTIVATION_ID);
            $this->travel(2)->seconds();
        }

        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression(self::UUID_V4, $reference, 'Not a time-ordered v7.');
        }
    }

    #[DataProvider('flows')]
    public function test_real_and_decoy_references_live_exactly_as_long(string $base, string $nationalId): void
    {
        $real = $this->start($base, $nationalId);
        $decoy = $this->start($base, self::UNKNOWN_ID);

        $this->travel(ChallengeDecoys::REFERENCE_TTL - 1)->seconds();
        $this->assertSame($this->verify($base, $real)->json(), $this->verify($base, $decoy)->json());
        $this->verify($base, $real)->assertJsonPath('code', 'OTP_EXPIRED');

        $this->travel(1)->seconds();
        $realGone = $this->verify($base, $real)->assertJsonPath('code', 'OTP_INVALID');
        $this->assertSame($realGone->json(), $this->verify($base, $decoy)->json());
    }

    #[DataProvider('flows')]
    public function test_a_reference_is_equally_unknown_to_the_other_purpose(string $base, string $nationalId, string $other): void
    {
        $real = $this->start($base, $nationalId);
        $decoy = $this->start($base, self::UNKNOWN_ID);

        foreach (['verify' => ['code' => '000000'], 'resend' => []] as $step => $extra) {
            $a = $this->postJson("{$other}/{$step}", ['challenge' => $real, ...$extra]);
            $b = $this->postJson("{$other}/{$step}", ['challenge' => $decoy, ...$extra]);
            $this->assertSame([$a->status(), $a->json()], [$b->status(), $b->json()], $step);
            $a->assertJsonPath('code', 'OTP_INVALID');
        }
        $password = ['password' => 'synthetic-pass-1', 'password_confirmation' => 'synthetic-pass-1'];
        $a = $this->postJson("{$other}/complete", ['challenge' => $real, ...$password], ['Referer' => 'http://localhost:3000']);
        $b = $this->postJson("{$other}/complete", ['challenge' => $decoy, ...$password], ['Referer' => 'http://localhost:3000']);
        $this->assertSame([$a->status(), $a->json()], [$b->status(), $b->json()]);
    }
}
