<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\CorrectNationalIdAction;
use App\Contracts\SmsSender;
use App\Models\AuthOtpChallenge;
use App\Models\FamilyAuthIdentity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I: a National ID corrected (through CorrectNationalIdAction) while a
 * password reset is in flight. At commit the obsolete identifier stops
 * working for login and reset; the in-flight grant is bound to the Person
 * AND the User — not to the identifier — so it follows the account while the
 * account keeps its Family context, and dies with it when the identity is
 * suspended. persons.national_id is never a login or reset fallback.
 * Synthetic data only.
 */
class NationalIdCorrectionDuringAuthTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const RESET = '/api/v1/family/auth/password/reset';

    private const OLD_ID = '223456789';

    private const NEW_ID = '323456789';

    private const OLD_PASSWORD = 'old-password-1';

    private const NEW_PASSWORD = 'new-password-1';

    private const BROWSER = ['Referer' => 'http://localhost:3000'];

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
            'family_auth.password_reset_enabled' => true,
            'family_auth.login_enabled' => true,
            'family_auth.activation.min_response_ms' => 0,
            'session.driver' => 'database',
        ]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        $this->account = $this->activatedHead(self::OLD_ID);
        $this->account['user']->forceFill(['password' => Hash::make(self::OLD_PASSWORD)])->save();
        $this->trustedMobile($this->account['person'], '0597654321');
        $this->freezeSecond();
    }

    private function login(string $nationalId, string $password): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/family/auth/login', ['national_id' => $nationalId, 'password' => $password], self::BROWSER);
    }

    private function startReset(string $nationalId): string
    {
        return $this->postJson(self::RESET.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
    }

    private function complete(string $reference): TestResponse
    {
        return $this->postJson(self::RESET.'/complete', ['challenge' => $reference, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD], self::BROWSER);
    }

    private function correct(string $nationalId): void
    {
        app(CorrectNationalIdAction::class)->handle($this->account['person'], $nationalId, null);
    }

    public function test_the_obsolete_identifier_stops_working_at_commit(): void
    {
        $this->correct(self::NEW_ID);

        $this->assertSame('SUPERSEDED', $this->account['identity']->fresh()->status->value);
        $this->assertSame(1, FamilyAuthIdentity::where('user_id', $this->account['user']->id)->where('status', 'ACTIVE')->count());

        $this->login(self::OLD_ID, self::OLD_PASSWORD)->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->login(self::NEW_ID, self::OLD_PASSWORD)->assertOk();

        // A reset started with the old identifier is a decoy: no challenge, no SMS.
        $this->startReset(self::OLD_ID);
        $this->assertSame(0, AuthOtpChallenge::count());
        $this->assertSame(0, $this->sms->attempts);
    }

    public function test_an_in_flight_reset_follows_the_account_not_the_identifier(): void
    {
        $reference = $this->startReset(self::OLD_ID);
        $code = $this->sms->lastCode();

        $this->correct(self::NEW_ID);

        // Same Person, same User, same trusted phone: the grant still holds.
        $this->postJson(self::RESET.'/verify', ['challenge' => $reference, 'code' => $code])->assertOk();
        $this->complete($reference)->assertOk();

        $this->login(self::OLD_ID, self::NEW_PASSWORD)->assertStatus(401);
        $this->login(self::NEW_ID, self::NEW_PASSWORD)->assertOk();
    }

    public function test_an_in_flight_reset_dies_when_the_correction_suspends_the_identity(): void
    {
        $reference = $this->startReset(self::OLD_ID);
        $code = $this->sms->lastCode();
        $this->sessionRowFor($this->account['user']);

        // A value that is not nine digits: the identity is SUSPENDED and the
        // account's sessions are revoked.
        $this->correct('12345678');

        $this->assertSame('SUSPENDED', $this->account['identity']->fresh()->status->value);
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->account['user']->id]);
        $this->postJson(self::RESET.'/verify', ['challenge' => $reference, 'code' => $code]);
        $this->complete($reference)->assertStatus(409)->assertJsonPath('code', 'RESET_FAILED');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->account['user']->fresh()->password));
        $this->login(self::OLD_ID, self::OLD_PASSWORD)->assertStatus(401);
    }

    public function test_the_registry_national_id_is_never_a_login_or_reset_fallback(): void
    {
        // The registry value changed WITHOUT the Domain Action: no identity
        // carries it, and the existing identity no longer matches the Person.
        $this->account['person']->forceFill(['national_id' => self::NEW_ID])->saveQuietly();

        $this->login(self::NEW_ID, self::OLD_PASSWORD)->assertStatus(401);
        $this->login(self::OLD_ID, self::OLD_PASSWORD)->assertStatus(401);
        $this->startReset(self::NEW_ID);
        $this->startReset(self::OLD_ID);
        $this->assertSame(0, AuthOtpChallenge::count());
    }
}
