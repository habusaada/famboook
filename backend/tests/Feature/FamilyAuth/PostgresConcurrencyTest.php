<?php

namespace Tests\Feature\FamilyAuth;

use App\Actions\ActivateFamilyAccountAction;
use App\Actions\ResetFamilyPasswordAction;
use App\Contracts\SmsSender;
use App\Enums\OtpPurpose;
use App\Exceptions\FamilyAuthException;
use App\Models\AuthOtpChallenge;
use App\Models\Person;
use App\Models\User;
use App\Support\FamilyAuth\OtpChallenges;
use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\Support\FakeSmsSender;
use Tests\Support\FamilyIdentityFixtures;
use Tests\TestCase;

/**
 * PWA-1I: the PostgreSQL guarantees SQLite cannot prove — the row locks
 * that serialize parallel OTP verify / resend, parallel starts for one
 * Person, and activation / reset completions, and the re-read under lock
 * that stops a grant being consumed twice.
 *
 * A SECOND database session (the `other_runner` connection, as in the
 * import tests) holds a lock the way a parallel request would; this session
 * then runs the real operation with a short `lock_timeout`, and PostgreSQL's
 * lock_not_available (55P03) proves the operation WAITS for that lock instead
 * of reading past it. Fixtures are committed (DatabaseTruncation), because a
 * second session cannot see — or lock — uncommitted rows.
 *
 * PostgreSQL ONLY, against the dedicated `_test` database of
 * phpunit.pgsql.xml (TestDatabaseGuard refuses anything else). Skipped —
 * before anything connects — unless that database is configured and
 * reachable. Never creates a database. Synthetic data only.
 *
 *   vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/FamilyAuth
 */
class PostgresConcurrencyTest extends TestCase
{
    use DatabaseTruncation, FamilyIdentityFixtures;

    /**
     * Rows a MIGRATION created are reference data, not test data: the
     * canonical AL_BREEM Clan (FamilyFactory's default clan) must survive
     * truncation, as it survives every RefreshDatabase test.
     *
     * @var list<string>
     */
    protected $exceptTables = ['clans'];

    private const ACTIVATION = '/api/v1/family/auth/activation';

    private const RESET = '/api/v1/family/auth/password/reset';

    private const ACTIVATION_ID = '123456789';

    private const RESET_ID = '223456789';

    private const LOCK_NOT_AVAILABLE = '55P03';

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->useFamilyAuthKey();
        Cache::flush();
        config([
            'family_auth.activation_enabled' => true,
            'family_auth.password_reset_enabled' => true,
            'family_auth.activation.min_response_ms' => 0,
            'database.connections.other_runner' => config('database.connections.pgsql'),
        ]);
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            DB::connection('other_runner')->disconnect();
            // DatabaseTruncation empties the tables BEFORE each test only:
            // this test's committed rows must not reach the next test class,
            // whose RefreshDatabase transaction would see them.
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    /** The configured database is PostgreSQL, a *_test database, and answers. */
    private static function pgsqlTestDatabaseIsReachable(): bool
    {
        $env = static fn (string $key): ?string => ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) ?: null;
        $database = (string) $env('DB_DATABASE');
        if ($env('DB_CONNECTION') !== 'pgsql' || ! str_ends_with($database, '_test')) {
            return false;
        }
        try {
            new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST') ?? '127.0.0.1', $env('DB_PORT') ?? '5432', $database),
                $env('DB_USERNAME'),
                $env('DB_PASSWORD'),
            );

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function other(): Connection
    {
        return DB::connection('other_runner');
    }

    /**
     * Runs $operation in this session, inside an outer transaction (the
     * depth the suite normally provides), while the other session holds
     * `$lockSql FOR UPDATE`. Returns the SQLSTATE the operation ended with.
     */
    private function whileLocked(string $lockSql, array $bindings, Closure $operation): ?string
    {
        $this->other()->beginTransaction();
        $this->other()->select($lockSql.' FOR UPDATE', $bindings);
        DB::beginTransaction();
        DB::statement("SET LOCAL lock_timeout = '300ms'");
        try {
            $operation();

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        } finally {
            DB::rollBack();
            $this->other()->rollBack();
        }
    }

    /** Runs $operation inside an outer transaction, then undoes it. */
    private function inOuterTransaction(Closure $operation): mixed
    {
        DB::beginTransaction();
        try {
            return $operation();
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{0: string, 1: string} reference, code — committed */
    private function realChallenge(string $base, string $nationalId): array
    {
        $reference = $this->postJson($base.'/start', ['national_id' => $nationalId])->assertOk()->json('challenge');

        return [$reference, $this->sms->lastCode()];
    }

    private function activationHead(): Person
    {
        [$person] = $this->eligibleHead(self::ACTIVATION_ID);
        $this->trustedMobile($person, '0591234567');

        return $person;
    }

    public function test_a_parallel_verify_waits_for_the_challenge_row_lock(): void
    {
        $this->activationHead();
        [$reference, $code] = $this->realChallenge(self::ACTIVATION, self::ACTIVATION_ID);

        $state = $this->whileLocked('SELECT id FROM auth_otp_challenges WHERE uuid = ?', [$reference],
            fn () => app(OtpChallenges::class)->verify($reference, OtpPurpose::ACTIVATION, $code === '000000' ? '111111' : '000000'));

        $this->assertSame(self::LOCK_NOT_AVAILABLE, $state);
        $this->assertSame(0, AuthOtpChallenge::sole()->attempts, 'The waiting attempt counted nothing.');
        // Once released it runs, exactly once.
        $this->assertTrue($this->inOuterTransaction(fn () => app(OtpChallenges::class)->verify($reference, OtpPurpose::ACTIVATION, $code)->succeeded()));
    }

    public function test_a_resend_waits_for_a_verify_holding_the_challenge(): void
    {
        $this->activationHead();
        [$reference] = $this->realChallenge(self::ACTIVATION, self::ACTIVATION_ID);
        $this->travel(61)->seconds();

        $state = $this->whileLocked('SELECT id FROM auth_otp_challenges WHERE uuid = ?', [$reference],
            fn () => app(OtpChallenges::class)->resend($reference, OtpPurpose::ACTIVATION));

        $this->assertSame(self::LOCK_NOT_AVAILABLE, $state);
        $this->assertSame(1, AuthOtpChallenge::sole()->send_count);
    }

    public function test_parallel_starts_for_one_person_serialize_on_the_person_row(): void
    {
        $person = $this->activationHead();

        $state = $this->whileLocked('SELECT id FROM persons WHERE id = ?', [$person->id],
            fn () => app(OtpChallenges::class)->issue(OtpPurpose::ACTIVATION, $person));

        $this->assertSame(self::LOCK_NOT_AVAILABLE, $state);
        $this->assertSame(0, AuthOtpChallenge::count());
    }

    public function test_one_open_challenge_per_person_and_purpose_is_enforced_by_the_database(): void
    {
        $this->activationHead();
        $this->realChallenge(self::ACTIVATION, self::ACTIVATION_ID);
        $row = collect(AuthOtpChallenge::sole()->getAttributes())->except(['id', 'uuid'])->all();

        try {
            DB::table('auth_otp_challenges')->insert([...$row, 'uuid' => (string) Str::uuid()]);
            $this->fail('A second open challenge was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('uq_auth_otp_challenges_open', $e->getMessage());
        }
    }

    public function test_an_activation_completion_waits_for_a_parallel_one_and_cannot_consume_twice(): void
    {
        $person = $this->activationHead();
        [$reference, $code] = $this->realChallenge(self::ACTIVATION, self::ACTIVATION_ID);
        $this->postJson(self::ACTIVATION.'/verify', ['challenge' => $reference, 'code' => $code])->assertOk();
        $users = User::count();

        // A parallel completion holds the Person: this one waits, creating nothing.
        $state = $this->whileLocked('SELECT id FROM persons WHERE id = ?', [$person->id],
            fn () => app(ActivateFamilyAccountAction::class)->handle($reference, 'synthetic-pass-1'));
        $this->assertSame(self::LOCK_NOT_AVAILABLE, $state);
        $this->assertSame($users, User::count());

        // The parallel one committed its consumption: this one, re-reading
        // the grant under the lock, is refused.
        $this->other()->table('auth_otp_challenges')->where('uuid', $reference)->update(['consumed_at' => now()]);
        $error = $this->inOuterTransaction(function () use ($reference) {
            try {
                app(ActivateFamilyAccountAction::class)->handle($reference, 'synthetic-pass-1');
            } catch (FamilyAuthException $e) {
                return $e->error->value;
            }

            return null;
        });
        $this->assertSame('ACTIVATION_FAILED', $error);
        $this->assertSame($users, User::count());
    }

    public function test_a_reset_grant_cannot_be_consumed_twice(): void
    {
        $account = $this->activatedHead(self::RESET_ID);
        $account['user']->forceFill(['password' => Hash::make('old-password-1')])->save();
        $this->trustedMobile($account['person'], '0597654321');
        [$reference, $code] = $this->realChallenge(self::RESET, self::RESET_ID);
        $this->postJson(self::RESET.'/verify', ['challenge' => $reference, 'code' => $code])->assertOk();

        // A parallel completion holds the account: this one waits.
        $state = $this->whileLocked('SELECT id FROM users WHERE id = ?', [$account['user']->id],
            fn () => app(ResetFamilyPasswordAction::class)->handle($reference, 'new-password-1'));
        $this->assertSame(self::LOCK_NOT_AVAILABLE, $state);

        $this->other()->table('auth_otp_challenges')->where('uuid', $reference)->update(['consumed_at' => now()]);
        $error = $this->inOuterTransaction(function () use ($reference) {
            try {
                app(ResetFamilyPasswordAction::class)->handle($reference, 'new-password-1');
            } catch (FamilyAuthException $e) {
                return $e->error->value;
            }

            return null;
        });
        $this->assertSame('RESET_FAILED', $error);
        $this->assertTrue(Hash::check('old-password-1', $account['user']->fresh()->password));
    }
}
