<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Enums\FamilyAuthError;
use App\Enums\OtpPurpose;
use App\Exceptions\FamilyAuthException;
use App\Support\FamilyAuth\FamilyActivation;
use App\Support\FamilyAuth\FamilyAuthIdentities;
use App\Support\FamilyAuth\FamilyLogin;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I, findings L-1 / A-4: login and the per-identifier start ceiling
 * COUNT an attempt atomically before doing the work, so parallel attempts
 * cannot overrun a ceiling. Parallelism is reproduced by interleaving: each
 * attempt starts the next one inside its own critical window (the password
 * check for login, the ceiling read for start). Synthetic data only.
 */
class AuthAttemptAccountingTest extends TestCase
{
    use FamilyIdentityFixtures, RefreshDatabase;

    private const NATIONAL_ID = '123456789';

    private const UNKNOWN_ID = '987654321';

    private const PASSWORD = 'synthetic-pass-1';

    private const IP = '203.0.113.7';

    private ProbeHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config([
            'family_auth.login_enabled' => true,
            'family_auth.activation_enabled' => true,
            'family_auth.activation.min_response_ms' => 0,
        ]);
        $this->app->instance(SmsSender::class, new FakeSmsSender);

        $this->hasher = new ProbeHasher(['rounds' => 4]);
        Hash::extend('probe', fn () => $this->hasher);
        config(['hashing.driver' => 'probe']);

        $head = $this->activatedHead(self::NATIONAL_ID);
        $head['user']->forceFill(['password' => Hash::make(self::PASSWORD)])->save();
        $this->hasher->checks = 0;
        $this->freezeSecond();
    }

    private function key(string $nationalId): string
    {
        return app(FamilyAuthIdentities::class)->keyFor($nationalId);
    }

    private static function outcome(Closure $call): string
    {
        try {
            $call();

            return 'OK';
        } catch (FamilyAuthException $e) {
            return $e->error->value;
        }
    }

    private function attempt(string $nationalId, string $password): string
    {
        return self::outcome(fn () => app(FamilyLogin::class)->attempt($nationalId, $password, self::IP));
    }

    /**
     * $n login attempts, each started inside the previous one's ceiling read
     * — the window between "is it over the ceiling?" and counting: all of
     * them have read the counter before any of them counts.
     *
     * @return list<string>
     */
    private function parallelLogins(int $n, string $nationalId, string $password): array
    {
        $key = "family-login|identifier-ip|{$this->key($nationalId)}|".hash('sha256', self::IP);
        $outcomes = [];
        $started = 1;
        Event::listen(RetrievingKey::class, function (RetrievingKey $e) use ($key, $n, $nationalId, $password, &$started, &$outcomes) {
            if ($e->key === $key && $started < $n) {
                $started++;
                $outcomes[] = $this->attempt($nationalId, $password);
            }
        });
        $outcomes[] = $this->attempt($nationalId, $password);
        $this->assertSame($n, $started);

        return $outcomes;
    }

    public function test_an_attempt_is_counted_before_the_password_is_checked(): void
    {
        $seen = [];
        $this->hasher->onCheck = function () use (&$seen) {
            $seen[] = RateLimiter::attempts('family-login|identifier|'.$this->key(self::NATIONAL_ID));
        };

        $this->attempt(self::NATIONAL_ID, 'wrong-password-1');

        $this->assertSame([1], $seen);
    }

    public function test_parallel_wrong_passwords_never_reach_the_check_more_often_than_the_ceiling(): void
    {
        $outcomes = $this->parallelLogins(10, self::NATIONAL_ID, 'wrong-password-1');

        // Identifier + IP ceiling: 5. Exactly five reach bcrypt.
        $this->assertSame(5, $this->hasher->checks);
        $counts = array_count_values($outcomes);
        $this->assertSame(5, $counts['INVALID_CREDENTIALS']);
        $this->assertSame(5, $counts['TOO_MANY_REQUESTS']);
    }

    public function test_an_unknown_identifier_is_accounted_exactly_like_a_known_one(): void
    {
        $known = $this->parallelLogins(10, self::NATIONAL_ID, 'wrong-password-1');
        $knownChecks = $this->hasher->checks;
        $this->hasher->checks = 0;
        $unknown = $this->parallelLogins(10, self::UNKNOWN_ID, 'wrong-password-1');

        sort($known);
        sort($unknown);
        $this->assertSame($known, $unknown);
        $this->assertSame($knownChecks, $this->hasher->checks, 'One bcrypt per admitted attempt, known or not.');
    }

    public function test_the_global_identifier_ceiling_holds_across_addresses(): void
    {
        config(['family_auth.login.limits.identifier_ip_failures' => 100, 'family_auth.login.limits.identifier_failures' => 3]);

        $outcomes = $this->parallelLogins(6, self::NATIONAL_ID, 'wrong-password-1');

        $this->assertSame(3, $this->hasher->checks);
        $this->assertSame(3, array_count_values($outcomes)['TOO_MANY_REQUESTS']);
    }

    public function test_a_success_clears_the_counters_and_is_not_penalised(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame('INVALID_CREDENTIALS', $this->attempt(self::NATIONAL_ID, 'wrong-password-1'));
        }
        $this->assertSame('OK', $this->attempt(self::NATIONAL_ID, self::PASSWORD));

        $this->assertSame(0, RateLimiter::attempts('family-login|identifier|'.$this->key(self::NATIONAL_ID)));
        $this->assertSame('INVALID_CREDENTIALS', $this->attempt(self::NATIONAL_ID, 'wrong-password-1'));
        $this->assertSame('OK', $this->attempt(self::NATIONAL_ID, self::PASSWORD));
    }

    public function test_a_locked_identifier_is_refused_without_a_password_check(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt(self::NATIONAL_ID, 'wrong-password-1');
        }
        $this->hasher->checks = 0;

        $this->assertSame('TOO_MANY_REQUESTS', $this->attempt(self::NATIONAL_ID, self::PASSWORD));
        $this->assertSame(0, $this->hasher->checks);
    }

    public function test_parallel_starts_cannot_overrun_the_identifier_ceiling(): void
    {
        $key = 'family-activation|start-identifier|'.$this->key(self::UNKNOWN_ID);
        $outcomes = [];
        $started = 1;
        $start = fn () => self::outcome(fn () => app(FamilyActivation::class)->start(self::UNKNOWN_ID));
        // Every start begins inside the previous one's ceiling read.
        Event::listen(RetrievingKey::class, function (RetrievingKey $e) use ($key, $start, &$started, &$outcomes) {
            if ($e->key === $key && $started < 8) {
                $started++;
                $outcomes[] = $start();
            }
        });
        $outcomes[] = $start();

        $counts = array_count_values($outcomes);
        $this->assertSame(8, $started);
        // Five admitted (an unknown identifier is refused, FP-ADR-054), then the ceiling.
        $this->assertSame(5, $counts[FamilyAuthError::ACTIVATION_REFUSED->value] ?? 0, 'Ceiling: 5 starts per identifier per hour.');
        $this->assertSame(3, $counts[FamilyAuthError::TOO_MANY_REQUESTS->value] ?? 0);
        $this->assertNotNull(OtpPurpose::ACTIVATION);
    }
}

/** bcrypt that counts its checks and can run a hook inside one. */
final class ProbeHasher extends BcryptHasher
{
    public int $checks = 0;

    public ?Closure $onCheck = null;

    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->checks++;
        if ($this->onCheck !== null) {
            ($this->onCheck)();
        }

        return parent::check($value, $hashedValue, $options);
    }
}
