<?php

namespace Tests\Feature\FamilyAuth;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Models\AuthSecurityEvent;
use App\Models\User;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\SignInHistory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * FB-FAMILY-DASHBOARD-01: the previous successful sign-in of a Family Portal
 * session (SignInHistory), on /family/me as previous_sign_in_at (UTC ISO
 * 8601 or null) and first_sign_in. Synthetic data only.
 */
class PreviousSignInTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const LOGIN = '/api/v1/family/auth/login';

    private const ME = '/api/v1/family/me';

    private const NATIONAL_ID = '123456789';

    private const PASSWORD = 'password';

    private const BROWSER = ['Referer' => 'http://localhost:3000'];

    private array $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config(['family_auth.login_enabled' => true, 'family_auth.password_change_enabled' => true]);
        $this->head = $this->activatedHead(self::NATIONAL_ID);
        $this->freezeSecond();
    }

    /**
     * Signs in like a browser: the session cookie of a successful sign-in is
     * sent with the following requests (the test client does not keep it
     * by itself), so /me reads THAT session.
     */
    private function login(string $password = self::PASSWORD): TestResponse
    {
        $response = $this->postJson(self::LOGIN, ['national_id' => self::NATIONAL_ID, 'password' => $password], self::BROWSER);
        $this->keepSession($response);

        return $response;
    }

    private function keepSession(TestResponse $response): void
    {
        $cookie = $response->getCookie(config('session.cookie'));
        if ($cookie !== null) {
            $this->withCookie(config('session.cookie'), $cookie->getValue());
        }
        // Like a new browser request: no user object cached from the last one.
        $this->app['auth']->forgetGuards();
    }

    private function logout(): void
    {
        $this->postJson('/api/v1/family/auth/logout')->assertNoContent();
    }

    private function lastSignInAt(): string
    {
        return AuthSecurityEvent::where('user_id', $this->head['user']->id)
            ->where('event_type', AuthSecurityEventType::LOGIN_SUCCEEDED->value)
            ->orderByDesc('id')->firstOrFail()->created_at->utc()->toIso8601String();
    }

    public function test_an_account_without_earlier_history_is_unknown_never_a_first_sign_in(): void
    {
        // The fixture account was activated outside the logged flow: no earlier event exists.
        $this->login()->assertOk()
            ->assertJsonPath('user.previous_sign_in_at', null)
            ->assertJsonPath('user.first_sign_in', false);
    }

    public function test_each_sign_in_reports_the_one_before_it_not_itself(): void
    {
        $this->login()->assertOk();
        $first = $this->lastSignInAt();
        $this->logout();

        $this->travel(2)->hours();
        $this->login()->assertOk()->assertJsonPath('user.previous_sign_in_at', $first)->assertJsonPath('user.first_sign_in', false);
        $second = $this->lastSignInAt();
        $this->assertNotSame($first, $second);
        // Resolved once: /me keeps answering the same during the session.
        $this->travel(10)->minutes();
        $this->getJson(self::ME, self::BROWSER)->assertOk()->assertJsonPath('user.previous_sign_in_at', $first);
        $this->logout();

        $this->travel(1)->days();
        $this->login()->assertOk()->assertJsonPath('user.previous_sign_in_at', $second);
    }

    public function test_failures_password_changes_and_other_users_never_count(): void
    {
        $this->login()->assertOk();
        $real = $this->lastSignInAt();
        $this->logout();

        $this->travel(1)->hours();
        $this->login('wrong-password')->assertStatus(401);
        $other = $this->activatedHead('223456789')['user'];
        AuthSecurityLog::record(AuthSecurityEventType::LOGIN_SUCCEEDED, AuthSecurityEventOutcome::SUCCESS, user: $other);
        AuthSecurityLog::record(AuthSecurityEventType::PASSWORD_CHANGED, AuthSecurityEventOutcome::SUCCESS, user: $this->head['user']);
        AuthSecurityLog::record(AuthSecurityEventType::LOGIN_SUCCEEDED, AuthSecurityEventOutcome::DENIED, user: $this->head['user']);

        $this->travel(1)->hours();
        $this->login()->assertOk()->assertJsonPath('user.previous_sign_in_at', $real);
    }

    public function test_a_password_change_refreshes_the_session_but_keeps_the_previous_sign_in(): void
    {
        $this->login()->assertOk();
        $first = $this->lastSignInAt();
        $this->logout();
        $this->travel(1)->hours();
        $this->login()->assertOk()->assertJsonPath('user.previous_sign_in_at', $first);

        $this->travel(5)->minutes();
        $changed = $this->postJson('/api/v1/family/account/password', [
            'current_password' => self::PASSWORD, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ], self::BROWSER)->assertOk()->assertJsonPath('user.previous_sign_in_at', $first)->assertJsonPath('user.first_sign_in', false);
        $this->keepSession($changed);
        $this->getJson(self::ME, self::BROWSER)->assertOk()->assertJsonPath('user.previous_sign_in_at', $first);
    }

    public function test_a_session_that_did_not_start_with_a_sign_in_is_unknown(): void
    {
        // E.g. a session started before this feature: nothing was resolved.
        $this->actingAs($this->head['user'])->getJson(self::ME)->assertOk()
            ->assertJsonPath('user.previous_sign_in_at', null)
            ->assertJsonPath('user.first_sign_in', false);
    }

    public function test_me_exposes_only_the_timestamp_never_ip_device_or_event_data(): void
    {
        $this->login()->assertOk();
        $this->logout();
        $this->travel(1)->hours();
        $this->login()->assertOk();

        $user = $this->getJson(self::ME, self::BROWSER)->assertOk()->json('user');
        $this->assertSame(['context', 'coordinator', 'coordinator_notifications', 'coordinator_space', 'display_name', 'first_sign_in', 'notifications', 'previous_sign_in_at', 'roles'], collect($user)->keys()->sort()->values()->all());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $user['previous_sign_in_at']);
        $json = json_encode($user);
        foreach (['127.0.0.1', 'ip', 'user_agent', 'login_key', 'uuid', 'event'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($json), $forbidden);
        }
    }

    public function test_a_concurrent_sign_in_on_another_device_is_never_taken_for_this_one(): void
    {
        $user = $this->head['user'];
        $earlier = AuthSecurityLog::record(AuthSecurityEventType::LOGIN_SUCCEEDED, AuthSecurityEventOutcome::SUCCESS, user: $user);
        $this->travel(1)->hours();

        // This request's own sign-in, then another device's sign-in committed before this session starts.
        $history = new SignInHistory;
        $mine = AuthSecurityLog::record(AuthSecurityEventType::PASSWORD_RESET_COMPLETED, AuthSecurityEventOutcome::SUCCESS, user: $user);
        $history->remember($mine);
        $this->travel(1)->seconds();
        AuthSecurityLog::record(AuthSecurityEventType::LOGIN_SUCCEEDED, AuthSecurityEventOutcome::SUCCESS, user: $user);

        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $history->start($request, $user);

        $this->assertSame([
            'previous_sign_in_at' => $earlier->created_at->utc()->toIso8601String(),
            'first_sign_in' => false,
        ], SignInHistory::forSession($request));
    }

    public function test_a_remembered_event_of_another_user_is_never_used(): void
    {
        $other = User::factory()->familySide()->create();
        $history = new SignInHistory;
        $history->remember(AuthSecurityLog::record(AuthSecurityEventType::ACTIVATION_COMPLETED, AuthSecurityEventOutcome::SUCCESS, user: $other));

        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $history->start($request, $this->head['user']);

        $this->assertSame(['previous_sign_in_at' => null, 'first_sign_in' => false], SignInHistory::forSession($request));
    }
}
