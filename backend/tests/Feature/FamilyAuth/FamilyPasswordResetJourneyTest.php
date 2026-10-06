<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\AssignCoordinatorScopeAction;
use App\Actions\GrantCoordinatorRoleAction;
use App\Actions\RevokePersonMobileTrustAction;
use App\Actions\UpdatePersonAction;
use App\Contracts\SmsSender;
use App\Enums\AuthIdentityStatus;
use App\Enums\FingerprintContext;
use App\Enums\MobileTrustRevokeReason;
use App\Enums\MobileTrustStatus;
use App\Enums\MobileVerificationMethod;
use App\Enums\OtpPurpose;
use App\Models\AuthOtpChallenge;
use App\Models\AuthSecurityEvent;
use App\Models\CoordinatorScopeAssignment;
use App\Models\FamilyActivity;
use App\Models\FamilyAuthIdentity;
use App\Models\Person;
use App\Models\PersonMobileTrust;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\KeyedFingerprint;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoordinatorFixtures;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FU-14: password reset as it will run in Production (docs/11 §30a,
 * FP-ADR-045). Every account here is made through the real public
 * activation — first self-activation, so its mobile is TRUSTED with
 * verification method SELF_OTP, as for every pilot account — and every
 * Staff change goes through its Domain Action. Codes are read from the fake
 * SMS sender only; no OTP rule is relaxed. Synthetic data only.
 */
class FamilyPasswordResetJourneyTest extends TestCase
{
    use CoordinatorFixtures, FamilyIdentityFixtures, RefreshDatabase;

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const RESET = '/api/v1/family/auth/password/reset';

    private const NATIONAL_ID = '123456789';

    private const MOBILE = '0591234567';

    private const OTHER_MOBILE = '0597654321';

    private const ACTIVATION_PASSWORD = 'synthetic activation pass';

    private const NEW_PASSWORD = 'a new synthetic passphrase';

    /** A first-party browser request: the only kind that carries a session. */
    private const BROWSER = ['Referer' => 'http://localhost:3000'];

    private FakeSmsSender $sms;

    private Person $person;

    private User $admin;

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
        ]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        $this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        // An eligible head whose registered mobile was never verified.
        [$this->person] = $this->eligibleHead(self::NATIONAL_ID);
        $this->person->forceFill(['mobile' => self::MOBILE])->saveQuietly();
        $this->freezeSecond();
    }

    // ---------------------------------------------------------------- steps

    /** First self-activation through the public API, then logout: the account a pilot user has. */
    private function activateAndLogOut(): User
    {
        $challenge = $this->startActivationChallenge(self::NATIONAL_ID);
        $this->postJson(self::ACTIVATION.'/verify', ['challenge' => $challenge, 'code' => $this->sms->lastCode()])->assertOk();
        $this->postJson(self::ACTIVATION.'/complete', [
            'challenge' => $challenge, 'password' => self::ACTIVATION_PASSWORD, 'password_confirmation' => self::ACTIVATION_PASSWORD,
        ], self::BROWSER)->assertCreated();
        $user = UserPersonLink::where('person_id', $this->person->id)->sole()->user;
        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/v1/family/auth/logout', [], self::BROWSER)->assertNoContent();
        $this->newBrowserRequest();
        $this->assertGuest('web');

        // The trust the activation made: TRUSTED, SELF_OTP, for this number.
        $trust = PersonMobileTrust::where('person_id', $this->person->id)->where('status', MobileTrustStatus::TRUSTED->value)->sole();
        $this->assertSame(MobileVerificationMethod::SELF_OTP, $trust->verification_method);

        return $user;
    }

    private function start(string $nationalId = self::NATIONAL_ID): TestResponse
    {
        return $this->postJson(self::RESET.'/start', ['national_id' => $nationalId])->assertOk();
    }

    private function verify(string $challenge, string $code): TestResponse
    {
        return $this->postJson(self::RESET.'/verify', ['challenge' => $challenge, 'code' => $code]);
    }

    private function complete(string $challenge): TestResponse
    {
        return $this->postJson(self::RESET.'/complete', [
            'challenge' => $challenge, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ], self::BROWSER);
    }

    /** start → the code from the SMS → verify → complete: the user's reset, signed in at the end. */
    private function resetPassword(): TestResponse
    {
        $sent = count($this->sms->sent);
        $challenge = $this->start()->json('challenge');
        $this->assertCount($sent + 1, $this->sms->sent, 'A real reset sends exactly one SMS.');
        $this->assertSame(self::MOBILE, $this->sms->last()->destination);
        $this->verify($challenge, $this->sms->lastCode())->assertOk();

        return $this->complete($challenge)->assertOk();
    }

    /**
     * Forget the signed-in user between requests, as a new request does in
     * Production. `auth.driver` (the Guard contract the database session
     * handler stamps user_id with) is a container singleton: it is
     * forgotten too, or it would keep the guard of an earlier request.
     */
    private function newBrowserRequest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
    }

    private function login(string $password, string $nationalId = self::NATIONAL_ID): TestResponse
    {
        $this->newBrowserRequest();

        return $this->postJson('/api/v1/family/auth/login', ['national_id' => $nationalId, 'password' => $password], self::BROWSER);
    }

    private function changeMobile(?string $mobile): void
    {
        app(UpdatePersonAction::class)->handle($this->person->fresh(), ['mobile' => $mobile], $this->admin->id);
    }

    /** The exact public answer of every start, real or decoy. */
    private function assertGenericStart(TestResponse $response): void
    {
        $response->assertExactJson([
            'challenge' => $response->json('challenge'),
            'resend_after_seconds' => 60,
            'expires_in_seconds' => 300,
            'can_resend' => true,
        ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** The reference behind a start is a decoy: no SMS, no reset challenge, no code can open it. */
    private function assertDecoy(TestResponse $response, int $smsBefore): string
    {
        $this->assertGenericStart($response);
        $reference = $response->json('challenge');
        $this->assertSame($smsBefore, $this->sms->attempts, 'A decoy sends nothing.');
        $this->assertSame(0, AuthOtpChallenge::where('purpose', OtpPurpose::PASSWORD_RESET->value)->count());
        // Whatever is tried, nothing completes.
        $this->verify($reference, '000000')->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');
        $this->complete($reference)->assertStatus(422)->assertJsonPath('code', 'OTP_INVALID');

        return $reference;
    }

    /** @return array<string, mixed> */
    private function trustSnapshot(): array
    {
        return PersonMobileTrust::where('person_id', $this->person->id)->orderBy('id')
            ->get(['id', 'status', 'verification_method', 'mobile_fingerprint', 'stale_at', 'revoked_at'])->toArray();
    }

    /** @return array<string, mixed> */
    private function registrySnapshot(): array
    {
        return [
            'persons' => DB::table('persons')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'families' => DB::table('families')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'family_memberships' => DB::table('family_memberships')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'family_activities' => FamilyActivity::count(),
        ];
    }

    // -------------------------------------------- the real Production journey

    public function test_a_self_activated_account_resets_its_password_and_is_signed_in(): void
    {
        $user = $this->activateAndLogOut();
        $this->login(self::ACTIVATION_PASSWORD)->assertOk();
        $this->postJson('/api/v1/family/auth/logout', [], self::BROWSER)->assertNoContent();
        $this->newBrowserRequest();
        $registry = $this->registrySnapshot();
        $trusts = $this->trustSnapshot();

        $response = $this->resetPassword();

        // The completing browser is signed in on a new session (FP-ADR-045).
        $response->assertJsonPath('user.roles', ['FAMILY_USER'])->assertJsonPath('user.context.available', true);
        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/v1/family/me')->assertOk()->assertJsonPath('user.context.available', true);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // The old password no longer works; the new one does.
        $this->login(self::ACTIVATION_PASSWORD)->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $this->login(self::NEW_PASSWORD)->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));

        // The reset is recorded; the grant is used; the trust is untouched.
        $challenge = AuthOtpChallenge::where('purpose', OtpPurpose::PASSWORD_RESET->value)->sole();
        $this->assertNotNull($challenge->consumed_at);
        $this->assertSame(['SUCCESS'], AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_REQUESTED')->pluck('outcome')->map->value->all());
        $this->assertSame(['SUCCESS'], AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_COMPLETED')->pluck('outcome')->map->value->all());
        $this->assertSame($trusts, $this->trustSnapshot());

        // The same account, the same Person, the same identity: nothing new created.
        $this->assertSame(1, User::whereHas('roles', fn ($q) => $q->where('name', 'FAMILY_USER'))->count());
        $this->assertSame(1, UserPersonLink::count());
        $this->assertSame(1, FamilyAuthIdentity::count());

        // Canonical registry data is not touched, and no Family Activity is written.
        $this->assertSame($registry, $this->registrySnapshot());
    }

    public function test_the_reset_ends_the_sessions_the_account_had_on_other_devices(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->activateAndLogOut();
        $this->sessionRowFor($user);
        $this->sessionRowFor($user);
        $before = DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();

        $this->resetPassword();

        $after = DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all();
        $this->assertCount(1, $after, 'Only the completing browser\'s new session remains.');
        $this->assertEmpty(array_intersect($before, $after));
        $this->assertSame(1, AuthSecurityEvent::where('event_type', 'SESSIONS_REVOKED')->where('user_id', $user->id)->count());
    }

    // ------------------------------------------------------------ coordinator

    public function test_a_coordinator_keeps_both_roles_its_scopes_and_its_space_after_a_reset(): void
    {
        $user = $this->activateAndLogOut();
        $clan = $this->clan();
        $branch = $this->branch($clan, null, 'BR_RESET');
        $this->familyIn($clan, $branch);
        app(GrantCoordinatorRoleAction::class)->handle($this->admin, $this->person);
        app(AssignCoordinatorScopeAction::class)->handle($this->admin, $this->person, $branch);
        $scopes = CoordinatorScopeAssignment::where('user_id', $user->id)->orderBy('id')->get()->toArray();
        $this->assertCount(1, $scopes);
        $users = User::count();

        $response = $this->resetPassword();

        $response->assertJsonPath('user.coordinator', true)->assertJsonPath('user.coordinator_space', true);
        $user = $user->fresh();
        $this->assertEqualsCanonicalizing(['FAMILY_USER', 'COORDINATOR'], $user->getRoleNames()->all());
        $this->assertSame($scopes, CoordinatorScopeAssignment::where('user_id', $user->id)->orderBy('id')->get()->toArray());
        // One account, one password: no coordinator account appears.
        $this->assertSame($users, User::count());
        $this->assertSame(1, UserPersonLink::count());

        // Coordinator Space opens for the new password's session, as before.
        $this->login(self::NEW_PASSWORD)->assertOk()->assertJsonPath('user.coordinator_space', true);
        $this->getJson('/api/v1/family/coordinator/context')->assertOk()
            ->assertJsonPath('data.scopes.0.code', 'BR_RESET');
    }

    // --------------------------------------------------------- Staff boundary

    public function test_a_staff_account_never_gets_a_reset_even_when_the_national_id_matches_a_person(): void
    {
        // The Person is an eligible head with a TRUSTED mobile but no Family
        // account; a Staff user exists beside it. Staff accounts have no
        // authentication identity: the National ID finds nothing.
        PersonMobileTrust::factory()->trusted()->create([
            'person_id' => $this->person->id,
            'mobile_fingerprint' => KeyedFingerprint::of(FingerprintContext::MOBILE, self::MOBILE),
            'mobile_last2' => substr(self::MOBILE, -2),
        ]);
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('ADMINISTRATOR'));
        $counts = [UserPersonLink::count(), FamilyAuthIdentity::count(), PersonMobileTrust::count()];

        $this->assertDecoy($this->start(), 0);

        $this->assertTrue(Hash::check('password', $staff->fresh()->password));
        $this->assertSame(['ADMINISTRATOR'], $staff->fresh()->getRoleNames()->all());
        $this->assertSame($counts, [UserPersonLink::count(), FamilyAuthIdentity::count(), PersonMobileTrust::count()]);
        $event = AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_REQUESTED')->sole();
        $this->assertSame(['DENIED', 'UNKNOWN_IDENTIFIER', null], [$event->outcome->value, $event->reason_code, $event->user_id]);
        $this->assertGuest('web');
    }

    public function test_a_staff_side_account_tied_to_the_matching_identity_gets_only_a_decoy(): void
    {
        // The worst case: a Staff-only account that the identity and link of
        // this Person point to (an account whose family roles were replaced
        // by a Staff role). The National ID finds it — and it is refused.
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('ADMINISTRATOR'));
        UserPersonLink::factory()->create(['user_id' => $staff->id, 'person_id' => $this->person->id]);
        FamilyAuthIdentity::factory()->create([
            'user_id' => $staff->id,
            'login_key' => KeyedFingerprint::of(FingerprintContext::LOGIN_ID, self::NATIONAL_ID),
            'key_version' => 1,
            'status' => AuthIdentityStatus::ACTIVE->value,
        ]);
        PersonMobileTrust::factory()->trusted()->create([
            'person_id' => $this->person->id,
            'mobile_fingerprint' => KeyedFingerprint::of(FingerprintContext::MOBILE, self::MOBILE),
            'mobile_last2' => substr(self::MOBILE, -2),
        ]);
        $counts = [User::count(), UserPersonLink::count(), FamilyAuthIdentity::count(), PersonMobileTrust::count()];

        $this->assertDecoy($this->start(), 0);

        $staff = $staff->fresh();
        $this->assertTrue(Hash::check('password', $staff->password), 'The Staff password is never reset.');
        $this->assertSame(['ADMINISTRATOR'], $staff->getRoleNames()->all(), 'No family-side role is added.');
        $this->assertSame($counts, [User::count(), UserPersonLink::count(), FamilyAuthIdentity::count(), PersonMobileTrust::count()]);
        $event = AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_REQUESTED')->sole();
        $this->assertSame(['DENIED', 'NOT_FAMILY_SIDE'], [$event->outcome->value, $event->reason_code]);
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_COMPLETED')->where('outcome', 'SUCCESS')->count());
        $this->assertGuest('web');
    }

    // ----------------------------------------------------------- mobile trust

    public function test_a_changed_mobile_stops_the_reset_until_staff_trust_it_and_nothing_is_revived(): void
    {
        $user = $this->activateAndLogOut();
        $original = PersonMobileTrust::where('person_id', $this->person->id)->sole();

        // A Staff edit of the registered mobile, through its Domain Action.
        $this->changeMobile(self::OTHER_MOBILE);
        $this->assertSame(MobileTrustStatus::STALE, $original->fresh()->status);
        $trusts = $this->trustSnapshot();
        $sms = $this->sms->attempts;

        $this->assertDecoy($this->start(), $sms);
        $event = AuthSecurityEvent::where('event_type', 'PASSWORD_RESET_REQUESTED')->latest('id')->first();
        $this->assertSame(['DENIED', 'TRUST_NOT_CURRENT'], [$event->outcome->value, $event->reason_code]);
        // No trust created, none confirmed, the stale one not revived.
        $this->assertSame($trusts, $this->trustSnapshot());

        // Changing back to the old number does not bring the old trust back,
        // and nothing is sent to the old destination either.
        $this->changeMobile(self::MOBILE);
        $trusts = $this->trustSnapshot();
        $this->assertDecoy($this->start(), $sms);
        $this->assertSame($trusts, $this->trustSnapshot());
        $this->assertSame(MobileTrustStatus::STALE, $original->fresh()->status);
        $this->assertSame(0, PersonMobileTrust::where('person_id', $this->person->id)->where('status', MobileTrustStatus::TRUSTED->value)->count());
        $this->assertSame(0, PersonMobileTrust::where('person_id', $this->person->id)->where('status', MobileTrustStatus::PENDING_VERIFICATION->value)->count());

        // The password is unchanged, and login still works with it.
        $this->assertTrue(Hash::check(self::ACTIVATION_PASSWORD, $user->fresh()->password));
        $this->login(self::ACTIVATION_PASSWORD)->assertOk();
    }

    public function test_a_self_verified_trust_revoked_by_staff_stops_the_reset_and_stays_revoked(): void
    {
        $user = $this->activateAndLogOut();
        $trust = PersonMobileTrust::where('person_id', $this->person->id)->sole();
        app(RevokePersonMobileTrustAction::class)->handle($this->admin, $trust, MobileTrustRevokeReason::REPORTED_LOST);
        $trusts = $this->trustSnapshot();

        $this->assertDecoy($this->start(), $this->sms->attempts);

        $this->assertSame($trusts, $this->trustSnapshot());
        $this->assertSame(MobileTrustStatus::REVOKED, $trust->fresh()->status);
        $this->assertTrue(Hash::check(self::ACTIVATION_PASSWORD, $user->fresh()->password));
    }

    public function test_a_mobile_changed_between_verify_and_complete_stops_the_reset(): void
    {
        $user = $this->activateAndLogOut();
        $challenge = $this->start()->json('challenge');
        $this->verify($challenge, $this->sms->lastCode())->assertOk();

        $this->changeMobile(self::OTHER_MOBILE);

        $this->complete($challenge)->assertStatus(423)->assertJsonPath('code', 'OTP_LOCKED');
        $this->assertTrue(Hash::check(self::ACTIVATION_PASSWORD, $user->fresh()->password));
        $this->assertGuest('web');
    }
}
