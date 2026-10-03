<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\FamilyStatus;
use App\Enums\LifeStatus;
use App\Enums\UserPersonLinkStatus;
use App\Models\AuthSecurityEvent;
use App\Models\Family;
use App\Models\FamilyAuthIdentity;
use App\Models\FamilyMembership;
use App\Models\Person;
use App\Models\User;
use App\Models\UserPersonLink;
use App\Support\FamilyAuth\FamilyLogin;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1G: Family login (docs/11 §30a) — National ID + password through the
 * authentication identity, never the registry field; one generic failure;
 * the two-tier lockout. Synthetic data only.
 */
class FamilyLoginTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const LOGIN = '/api/v1/family/auth/login';

    private const NATIONAL_ID = '123456789';

    private const UNKNOWN_ID = '987654321';

    /** The password every factory-made account has. Synthetic. */
    private const PASSWORD = 'password';

    private const INVALID = ['message' => 'رقم الهوية أو كلمة المرور غير صحيحة.', 'code' => 'INVALID_CREDENTIALS'];

    /** A first-party browser request: the only kind that carries a session. */
    private const BROWSER = ['Referer' => 'http://localhost:3000'];

    /** @var array{user: User, person: Person, family: Family, membership: FamilyMembership, link: UserPersonLink, identity: FamilyAuthIdentity} */
    private array $head;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config(['family_auth.login_enabled' => true]);
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
        $this->head = $this->activatedHead(self::NATIONAL_ID);
        $this->freezeSecond();
    }

    private function login(string $nationalId = self::NATIONAL_ID, string $password = self::PASSWORD, array $headers = self::BROWSER, ?string $ip = null): TestResponse
    {
        if ($ip !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        }

        return $this->postJson(self::LOGIN, ['national_id' => $nationalId, 'password' => $password], $headers);
    }

    private function assertInvalid(TestResponse $response): void
    {
        $response->assertStatus(401)->assertExactJson(self::INVALID);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertGuest('web');
    }

    private function lastFailureReason(): ?string
    {
        return AuthSecurityEvent::where('event_type', 'LOGIN_FAILED')->latest('id')->first()?->reason_code;
    }

    // ---------------------------------------------------------------- success

    public function test_the_national_id_and_the_password_sign_the_family_user_in(): void
    {
        $response = $this->login()->assertOk();

        $response->assertExactJson(['user' => [
            'display_name' => $this->head['person']->full_name,
            'roles' => ['FAMILY_USER'],
            'coordinator' => false,
            'coordinator_space' => false,
            'context' => ['available' => true, 'family' => [
                'code' => $this->head['family']->family_code,
                'name' => $this->head['family']->branch?->name ?? $this->head['family']->clan?->name,
            ]],
        ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertAuthenticatedAs($this->head['user'], 'web');
        $this->getJson('/api/v1/family/me')->assertOk();

        $event = AuthSecurityEvent::where('event_type', 'LOGIN_SUCCEEDED')->sole();
        $this->assertSame('SUCCESS', $event->outcome->value);
        $this->assertSame([$this->head['user']->id, $this->head['person']->id, $this->head['link']->id], [$event->user_id, $event->person_id, $event->user_person_link_id]);
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'LOGIN_FAILED')->count());
    }

    public function test_arabic_and_persian_digits_and_separators_are_accepted(): void
    {
        $this->login('١٢٣-٤٥٦ ۷۸۹')->assertOk();
    }

    public function test_the_session_is_regenerated_and_no_token_or_remember_cookie_is_issued(): void
    {
        $this->startSession();
        $before = session()->getId();
        $token = session()->token();
        $remember = $this->head['user']->remember_token;

        $response = $this->login()->assertOk();

        $this->assertNotSame($before, session()->getId());
        $this->assertNotSame($token, session()->token());
        // No "remember me": the token is not touched and no cookie carries one.
        $this->assertSame($remember, $this->head['user']->fresh()->remember_token);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringStartsNotWith('remember_', $cookie->getName());
        }
        $this->assertSame(0, $this->head['user']->tokens()->count());
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_a_staff_session_in_the_same_browser_is_replaced(): void
    {
        $staff = tap(User::factory()->create(), fn (User $u) => $u->assignRole('SUPER_ADMIN'));
        $this->actingAs($staff, 'web');

        $this->login()->assertOk();

        $this->assertAuthenticatedAs($this->head['user'], 'web');
        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_another_family_session_in_the_same_browser_is_replaced(): void
    {
        $other = $this->activatedHead('555555555');
        $this->actingAs($other['user'], 'web');

        $this->login()->assertOk()->assertJsonPath('user.display_name', $this->head['person']->full_name);

        $this->assertAuthenticatedAs($this->head['user'], 'web');
    }

    public function test_a_coordinator_who_is_a_family_user_signs_in(): void
    {
        $this->head['user']->assignRole('COORDINATOR');

        $this->login()->assertOk()->assertJsonPath('user.coordinator', true);
    }

    public function test_an_identity_under_the_previous_key_version_still_signs_in_during_a_rotation(): void
    {
        // The identity was written under key version 1; version 2 is now current.
        config(['family_auth.fingerprint' => [
            'key' => 'test-only-family-auth-key-ROTATED-0123456789',
            'key_version' => 2,
            'previous_key' => 'test-only-family-auth-key-0123456789-abcdef',
            'previous_key_version' => 1,
        ]]);

        $this->login()->assertOk();
    }

    // ---------------------------------------------- one answer for every failure

    /**
     * Every failure after format validation, as [arrange, recorded reason].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function failures(): array
    {
        return [
            'unknown identifier' => ['unknown', 'UNKNOWN_IDENTIFIER'],
            'wrong password' => ['wrong-password', 'WRONG_PASSWORD'],
            'suspended identity' => ['identity-suspended', 'UNKNOWN_IDENTIFIER'],
            'superseded identity' => ['identity-superseded', 'UNKNOWN_IDENTIFIER'],
            'inactive user' => ['user-inactive', 'USER_INACTIVE'],
            'staff role added (mixed account)' => ['mixed', 'NOT_FAMILY_SIDE'],
            'staff account holding an identity' => ['staff', 'NOT_FAMILY_SIDE'],
            'coordinator without family user' => ['coordinator-only', 'NOT_FAMILY_SIDE'],
            'no role at all' => ['role-less', 'NOT_FAMILY_SIDE'],
            'suspended link' => ['link-suspended', 'LINK_SUSPENDED'],
            'ended link' => ['link-ended', 'NO_LINK'],
            'deceased person' => ['deceased', 'PERSON_NOT_ALIVE'],
            'unknown life status' => ['life-unknown', 'PERSON_NOT_ALIVE'],
            'inactive person' => ['person-inactive', 'PERSON_INACTIVE'],
            'deleted person' => ['person-deleted', 'PERSON_DELETED'],
            'registry national id no longer matches' => ['id-changed', 'IDENTITY_MISMATCH'],
            'headship moved' => ['non-head', 'NOT_HOUSEHOLD_HEAD'],
            'membership ended' => ['no-membership', 'NO_ACTIVE_MEMBERSHIP'],
            'inactive family' => ['family-inactive', 'FAMILY_NOT_ACTIVE'],
            'deleted family' => ['family-deleted', 'FAMILY_DELETED'],
        ];
    }

    #[DataProvider('failures')]
    public function test_every_failure_is_the_same_invalid_credentials(string $case, string $reason): void
    {
        ['user' => $user, 'person' => $person, 'family' => $family, 'membership' => $membership, 'link' => $link, 'identity' => $identity] = $this->head;
        match ($case) {
            'unknown', 'wrong-password' => null,
            'identity-suspended' => $identity->forceFill(['status' => 'SUSPENDED'])->save(),
            'identity-superseded' => $identity->forceFill(['status' => 'SUPERSEDED', 'superseded_at' => now(), 'supersede_reason' => 'LINK_ENDED'])->save(),
            'user-inactive' => $user->forceFill(['is_active' => false])->save(),
            'mixed' => $user->assignRole('ADMINISTRATOR'),
            'staff' => $user->syncRoles(['DATA_ENTRY']),
            'coordinator-only' => $user->syncRoles(['COORDINATOR']),
            'role-less' => $user->syncRoles([]),
            'link-suspended' => $link->forceFill(['status' => UserPersonLinkStatus::SUSPENDED, 'suspended_at' => now(), 'suspension_reason' => 'ADMINISTRATIVE'])->save(),
            'link-ended' => $link->forceFill(['status' => UserPersonLinkStatus::ENDED, 'ended_at' => now(), 'end_reason' => 'ADMINISTRATIVE'])->save(),
            'deceased' => $person->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save(),
            'life-unknown' => $person->forceFill(['life_status' => LifeStatus::UNKNOWN])->save(),
            'person-inactive' => $person->forceFill(['is_active' => false])->save(),
            'person-deleted' => $person->delete(),
            'id-changed' => $person->forceFill(['national_id' => '222222222'])->saveQuietly(),
            'non-head' => $membership->forceFill(['is_household_head' => false])->save(),
            'no-membership' => $membership->forceFill(['is_active' => false])->save(),
            'family-inactive' => $family->forceFill(['status' => collect(FamilyStatus::cases())->first(fn ($s) => $s !== FamilyStatus::ACTIVE)])->save(),
            'family-deleted' => $family->delete(),
        };

        $response = $this->login(
            $case === 'unknown' ? self::UNKNOWN_ID : self::NATIONAL_ID,
            $case === 'wrong-password' ? 'not-the-password' : self::PASSWORD,
        );

        // The same status, the same body, to the byte — whatever the cause.
        $this->assertInvalid($response);
        $this->assertStringNotContainsString($reason, $response->getContent());

        // The cause is recorded, with the keyed fingerprint and never the number.
        $event = AuthSecurityEvent::where('event_type', 'LOGIN_FAILED')->sole();
        $this->assertSame(['FAILURE', $reason], [$event->outcome->value, $event->reason_code]);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $event->login_key);
        $this->assertSame(0, AuthSecurityEvent::where('event_type', 'LOGIN_SUCCEEDED')->count());
    }

    public function test_a_malformed_request_is_a_plain_validation_error(): void
    {
        foreach (['', '12345678', '1234567890', '12345678a'] as $id) {
            $this->login($id)->assertStatus(422)->assertJsonValidationErrors('national_id');
        }
        $this->postJson(self::LOGIN, ['national_id' => self::NATIONAL_ID], self::BROWSER)->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson(self::LOGIN, ['national_id' => self::NATIONAL_ID, 'password' => ['x']], self::BROWSER)->assertStatus(422);
        $this->login(self::NATIONAL_ID, str_repeat('a', 256))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(0, AuthSecurityEvent::count());
        $this->assertGuest('web');
    }

    public function test_a_request_without_a_session_is_refused_before_any_password_check(): void
    {
        $checks = $this->recordHashChecks();

        $this->assertInvalid($this->login(headers: []));

        $this->assertSame([], $checks->getArrayCopy());
        $this->assertSame('SESSION_REQUIRED', $this->lastFailureReason());
    }

    // --------------------------------------------------------- the dummy hash

    /** Replaces the hasher with one that records every hash a password was checked against. */
    private function recordHashChecks(): \ArrayObject
    {
        $checked = new \ArrayObject;
        $real = Hash::getFacadeRoot();
        Hash::swap(new class($real, $checked) implements Hasher
        {
            public function __construct(private readonly object $real, private readonly \ArrayObject $checked) {}

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->checked->append($hashedValue);

                return $this->real->check($value, $hashedValue, $options);
            }

            public function info($hashedValue)
            {
                return $this->real->info($hashedValue);
            }

            public function make($value, array $options = [])
            {
                return $this->real->make($value, $options);
            }

            public function needsRehash($hashedValue, array $options = [])
            {
                return $this->real->needsRehash($hashedValue, $options);
            }
        });

        return $checked;
    }

    public function test_an_unknown_identifier_pays_for_a_password_verification_against_the_dummy_hash(): void
    {
        $dummy = FamilyLogin::dummyHash();
        $checks = $this->recordHashChecks();

        $this->assertInvalid($this->login(self::UNKNOWN_ID));
        $this->assertSame([$dummy], $checks->getArrayCopy());

        // A known identifier: exactly one verification too, against its own hash.
        $this->assertInvalid($this->login(self::NATIONAL_ID, 'not-the-password', ip: '10.0.0.2'));
        $this->assertSame([$dummy, $this->head['user']->password], $checks->getArrayCopy());
    }

    public function test_the_dummy_hash_is_made_once_at_the_configured_cost(): void
    {
        $dummy = FamilyLogin::dummyHash();

        $this->assertSame($dummy, FamilyLogin::dummyHash());
        $this->assertSame('bcrypt', password_get_info($dummy)['algoName']);
        $this->assertSame((int) config('hashing.bcrypt.rounds'), password_get_info($dummy)['options']['cost']);
        $this->assertSame(password_get_info($this->head['user']->password)['options']['cost'], password_get_info($dummy)['options']['cost']);
        $this->assertSame($dummy, Cache::get('family-login|dummy-hash|'.config('hashing.bcrypt.rounds')));
        // It is nobody's password.
        $this->assertFalse(Hash::check(self::PASSWORD, $dummy));
    }

    public function test_the_context_is_only_evaluated_after_a_correct_password(): void
    {
        // The head died: a wrong password must still be recorded as a wrong password.
        $this->head['person']->forceFill(['life_status' => LifeStatus::DECEASED, 'death_date' => now()->toDateString()])->save();

        $this->assertInvalid($this->login(self::NATIONAL_ID, 'not-the-password'));
        $this->assertSame('WRONG_PASSWORD', $this->lastFailureReason());
    }

    // ------------------------------------------------------------------ gates

    public function test_while_login_is_disabled_nothing_happens(): void
    {
        config(['family_auth.login_enabled' => false]);
        $checks = $this->recordHashChecks();

        $this->login()->assertStatus(503)->assertExactJson(['message' => 'تسجيل الدخول غير متاح حاليًا.', 'code' => 'FAMILY_AUTH_UNAVAILABLE']);

        $this->assertSame([], $checks->getArrayCopy());
        $this->assertSame(0, AuthSecurityEvent::count());
        $this->assertGuest('web');
    }

    public function test_the_three_gates_are_independent_and_off_by_default(): void
    {
        $defaults = require base_path('config/family_auth.php');
        $this->assertFalse($defaults['activation_enabled']);
        $this->assertFalse($defaults['login_enabled']);

        // Login on, activation off: each answers for itself.
        config(['family_auth.login_enabled' => true, 'family_auth.activation_enabled' => false]);
        $this->login()->assertOk();
        $this->postJson('/api/v1/family/auth/activation/start', ['national_id' => self::NATIONAL_ID])->assertStatus(503);

        // /family/me and logout are never gated.
        config(['family_auth.login_enabled' => false]);
        $this->getJson('/api/v1/family/me')->assertOk();
        $this->postJson('/api/v1/family/auth/logout')->assertNoContent();
    }

    public function test_without_the_fingerprint_key_login_is_unavailable_for_everyone(): void
    {
        config(['family_auth.fingerprint.key' => null]);

        $this->login(self::NATIONAL_ID)->assertStatus(503)->assertJsonPath('code', 'FAMILY_AUTH_UNAVAILABLE');
        $this->login(self::UNKNOWN_ID)->assertStatus(503)->assertJsonPath('code', 'FAMILY_AUTH_UNAVAILABLE');
    }

    // ---------------------------------------------------------------- lockout

    private function assertThrottled(TestResponse $response): void
    {
        $response->assertStatus(429)->assertExactJson(['message' => 'محاولات كثيرة. حاول مجددًا بعد قليل.', 'code' => 'TOO_MANY_REQUESTS']);
    }

    #[DataProvider('identifiers')]
    public function test_five_failures_from_one_address_lock_that_identifier_there(string $id): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertInvalid($this->login($id, 'not-the-password'));
        }

        // Locked even with the right password — and for an unknown identifier alike.
        $this->assertThrottled($this->login($id, self::PASSWORD));
        $this->assertSame('THROTTLED', $this->lastFailureReason());

        // Another identifier from the same address, and this one from another address, are not.
        $this->assertInvalid($this->login('111111111', 'x'));
        if ($id === self::NATIONAL_ID) {
            $this->login($id, self::PASSWORD, ip: '10.0.0.9')->assertOk();
        }
    }

    /** @return array<string, array{0: string}> */
    public static function identifiers(): array
    {
        return ['known identifier' => [self::NATIONAL_ID], 'unknown identifier' => [self::UNKNOWN_ID]];
    }

    public function test_the_lock_lifts_after_fifteen_minutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login(password: 'not-the-password');
        }
        $this->assertThrottled($this->login());

        $this->travel(901)->seconds();

        $this->login()->assertOk();
    }

    public function test_twenty_failures_across_addresses_lock_the_identifier_everywhere(): void
    {
        // Four addresses, five failures each: none exceeds the per-address tier alone.
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4'] as $ip) {
            for ($i = 0; $i < 5; $i++) {
                $this->assertInvalid($this->login(password: 'not-the-password', ip: $ip));
            }
        }

        // A fifth, clean address is locked out too, right password or not.
        $this->assertThrottled($this->login(ip: '10.0.0.5'));

        $this->travel(901)->seconds();
        $this->login(ip: '10.0.0.5')->assertOk();
    }

    public function test_a_successful_login_clears_the_failure_counters(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->assertInvalid($this->login(password: 'not-the-password'));
        }
        $this->login()->assertOk();
        $this->postJson('/api/v1/family/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        // A fresh allowance of five, not one.
        for ($i = 0; $i < 4; $i++) {
            $this->assertInvalid($this->login(password: 'not-the-password'));
        }
        $this->login()->assertOk();
    }

    public function test_a_successful_login_does_not_charge_the_identifier(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->login()->assertOk();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_twenty_attempts_from_one_address_are_the_ceiling_whatever_the_identifier(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertInvalid($this->login('3000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'x'));
        }

        $this->assertThrottled($this->login());
        // The route ceiling answers before anything is looked up or recorded.
        $this->assertSame(20, AuthSecurityEvent::where('event_type', 'LOGIN_FAILED')->count());

        $this->login(ip: '10.0.0.7')->assertOk();
    }

    public function test_the_ceilings_are_configurable(): void
    {
        $this->assertSame(
            ['decay_seconds' => 900, 'ip_attempts' => 20, 'identifier_ip_failures' => 5, 'identifier_failures' => 20],
            (require base_path('config/family_auth.php'))['login']['limits'],
        );

        config(['family_auth.login.limits.identifier_ip_failures' => 2]);
        $this->assertInvalid($this->login(password: 'x'));
        $this->assertInvalid($this->login(password: 'x'));
        $this->assertThrottled($this->login());
    }

    // ------------------------------------------------------------- no leakage

    public function test_the_raw_identifier_and_the_password_never_reach_events_logs_or_keys(): void
    {
        $secret = 'synthetic-secret-passphrase';
        $this->login(self::NATIONAL_ID, $secret);
        $this->login(self::UNKNOWN_ID, $secret);
        $this->login()->assertOk();

        $events = AuthSecurityEvent::all()->map(fn ($e) => json_encode($e->getAttributes()))->implode("\n");
        $storage = new ReflectionProperty(Cache::getStore(), 'storage');
        $keys = implode("\n", array_keys($storage->getValue(Cache::getStore())));
        $this->assertStringContainsString('family-login|identifier', $keys);

        foreach ([self::NATIONAL_ID, self::UNKNOWN_ID, $secret, self::PASSWORD, '127.0.0.1'] as $raw) {
            $this->assertStringNotContainsString($raw, $keys);
            $this->assertStringNotContainsString($raw, implode("\n", $this->logged));
            if ($raw !== '127.0.0.1') {
                $this->assertStringNotContainsString($raw, $events);
            }
        }
    }

    public function test_the_registry_national_id_is_not_the_login_lookup(): void
    {
        // A Person carries the number in the registry but was never activated.
        [$person] = $this->eligibleHead('444444444');
        $this->trustedMobile($person);

        $this->assertInvalid($this->login('444444444'));
        $this->assertSame('UNKNOWN_IDENTIFIER', $this->lastFailureReason());
    }
}
