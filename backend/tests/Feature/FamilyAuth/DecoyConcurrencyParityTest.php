<?php

namespace Tests\Feature\FamilyAuth;

use App\Contracts\SmsSender;
use App\Exceptions\FamilyAuthException;
use App\Support\FamilyAuth\FamilyActivation;
use App\Support\FamilyAuth\FamilyPasswordReset;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I, finding A-1: parallel requests must not tell a decoy from a real
 * challenge. A real challenge is row-locked (its parallel requests behave as
 * if serialized — proven on PostgreSQL by PostgresConcurrencyTest); a decoy
 * is cache state. Here parallel decoy requests are INTERLEAVED for real: a
 * request is suspended right after it has read the decoy state, and the
 * other requests run to completion inside that window — the exact schedule
 * that used to lose updates. The public answers must be the ones the
 * serialized real challenge gives. Synthetic data only.
 */
class DecoyConcurrencyParityTest extends TestCase
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

    /** @return array<string, array{0: string, 1: string, 2: class-string}> */
    public static function flows(): array
    {
        return [
            'activation' => [self::ACTIVATION, self::ACTIVATION_ID, FamilyActivation::class],
            'password reset' => [self::RESET, self::RESET_ID, FamilyPasswordReset::class],
        ];
    }

    private function start(string $base, string $nationalId): string
    {
        return $this->postJson($base.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');
    }

    /** A public outcome: OK or the public error code (with retry_after when given). */
    private static function outcome(Closure $call): string
    {
        try {
            $call();

            return 'OK';
        } catch (FamilyAuthException $e) {
            return $e->error->value.($e->retryAfterSeconds !== null ? ':'.$e->retryAfterSeconds : '');
        }
    }

    /**
     * Runs $call $n times so that every run is suspended right after reading
     * the decoy state and the next run starts inside that window: all $n
     * have read the same state before any of them writes.
     *
     * @return list<string> outcomes, innermost first
     */
    private function interleaved(string $reference, int $n, Closure $call): array
    {
        $outcomes = [];
        $started = 0;
        $run = function () use ($call, &$outcomes) {
            $outcomes[] = self::outcome($call);
        };
        Event::listen(CacheHit::class, function (CacheHit $event) use ($reference, $n, $run, &$started) {
            if ($event->key === 'family-auth-decoy|'.$reference && $started < $n) {
                $started++;
                $run();
            }
        });
        $started = 1;
        $run();

        $this->assertSame($n, $started, 'Every run must start inside the previous run\'s window.');

        return $outcomes;
    }

    /** @param  list<string>  $outcomes */
    private static function sorted(array $outcomes): array
    {
        sort($outcomes);

        return $outcomes;
    }

    #[DataProvider('flows')]
    public function test_parallel_wrong_codes_on_a_decoy_lock_exactly_like_a_real_challenge(string $base, string $nationalId, string $service): void
    {
        $real = $this->start($base, $nationalId);
        $wrong = $this->sms->lastCode() === '000000' ? '111111' : '000000';
        $serialized = [];
        for ($i = 0; $i < 8; $i++) {
            $serialized[] = self::outcome(fn () => app($service)->verify($real, $wrong));
        }
        $this->assertSame([...array_fill(0, 4, 'OTP_INVALID'), ...array_fill(0, 4, 'OTP_LOCKED')], $serialized);

        $decoy = $this->start($base, self::UNKNOWN_ID);
        $parallel = $this->interleaved($decoy, 8, fn () => app($service)->verify($decoy, $wrong));

        $this->assertSame(self::sorted($serialized), self::sorted($parallel));
        // And afterwards it is locked, as the real one is.
        $this->assertSame('OTP_LOCKED', self::outcome(fn () => app($service)->verify($decoy, $wrong)));
    }

    #[DataProvider('flows')]
    public function test_two_parallel_attempts_below_the_limit_never_both_lock(string $base, string $nationalId, string $service): void
    {
        $decoy = $this->start($base, self::UNKNOWN_ID);
        for ($i = 0; $i < 3; $i++) {
            self::outcome(fn () => app($service)->verify($decoy, '000000'));
        }

        // Attempts 4 and 5 in parallel: one INVALID, one LOCKED — each judged
        // by its own count, as two serialized real attempts are.
        $parallel = $this->interleaved($decoy, 2, fn () => app($service)->verify($decoy, '000000'));

        $this->assertSame(['OTP_INVALID', 'OTP_LOCKED'], self::sorted($parallel));
    }

    #[DataProvider('flows')]
    public function test_parallel_resends_on_a_decoy_have_one_winner_like_a_real_challenge(string $base, string $nationalId, string $service): void
    {
        $real = $this->start($base, $nationalId);
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $this->travel(61)->seconds();

        $serialized = [
            self::outcome(fn () => app($service)->resend($real)),
            self::outcome(fn () => app($service)->resend($real)),
        ];
        $parallel = $this->interleaved($decoy, 2, fn () => app($service)->resend($decoy));

        $this->assertSame(['OK', 'OTP_COOLDOWN:60'], $serialized);
        $this->assertSame(self::sorted($serialized), self::sorted($parallel));
        $this->assertSame(2, $this->sms->attempts, 'One real start SMS and one real resend SMS.');
    }

    #[DataProvider('flows')]
    public function test_parallel_resends_never_exceed_the_send_limit(string $base, string $nationalId, string $service): void
    {
        $real = $this->start($base, $nationalId);
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $this->travel(61)->seconds();
        $this->assertSame('OK', self::outcome(fn () => app($service)->resend($real)));
        $this->assertSame('OK', self::outcome(fn () => app($service)->resend($decoy)));
        $this->travel(61)->seconds();

        // The third and last send, raced by three requests: one wins. A real
        // challenge checks the send limit before the cooldown, so the losers
        // see SEND_LIMIT.
        $serialized = [];
        for ($i = 0; $i < 3; $i++) {
            $serialized[] = self::outcome(fn () => app($service)->resend($real));
        }
        $parallel = $this->interleaved($decoy, 3, fn () => app($service)->resend($decoy));

        $this->assertSame(['OK', 'OTP_SEND_LIMIT', 'OTP_SEND_LIMIT'], $serialized);
        $this->assertSame(self::sorted($serialized), self::sorted($parallel));
        $this->travel(61)->seconds();
        $this->assertSame('OTP_SEND_LIMIT', self::outcome(fn () => app($service)->resend($decoy)));
    }

    #[DataProvider('flows')]
    public function test_a_resend_racing_a_new_start_cannot_revive_a_superseded_decoy(string $base, string $nationalId, string $service): void
    {
        $decoy = $this->start($base, self::UNKNOWN_ID);
        $this->travel(61)->seconds();

        // While the resend holds the old state, a new start supersedes it.
        $nested = false;
        Event::listen(CacheHit::class, function (CacheHit $event) use ($decoy, $base, &$nested) {
            if ($event->key === 'family-auth-decoy|'.$decoy && ! $nested) {
                $nested = true;
                $this->start($base, self::UNKNOWN_ID);
            }
        });
        self::outcome(fn () => app($service)->resend($decoy));

        // A real challenge superseded by a new start is dead for good.
        $this->assertTrue($nested);
        $this->assertSame('OTP_LOCKED', self::outcome(fn () => app($service)->verify($decoy, '000000')));
    }

    public function test_attempts_are_counted_by_an_atomic_counter_not_the_state_record(): void
    {
        $decoy = $this->start(self::ACTIVATION, self::UNKNOWN_ID);
        $before = Cache::get('family-auth-decoy|'.$decoy);

        self::outcome(fn () => app(FamilyActivation::class)->verify($decoy, '000000'));

        // The state record is untouched by an attempt; the counter moved.
        $this->assertSame($before, Cache::get('family-auth-decoy|'.$decoy));
        $this->assertSame(1, Cache::get('family-auth-decoy-attempts|'.$decoy));
    }
}
