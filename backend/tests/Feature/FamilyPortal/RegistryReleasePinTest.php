<?php

namespace Tests\Feature\FamilyPortal;

use App\Exceptions\RegistryAdmissionReferenceException as RefException;
use App\Exceptions\RegistryAdmissionUnavailableException;
use App\Exceptions\RegistryReleasePinUnavailableException as PinUnavailable;
use App\Support\FamilyPortal\RegistryAdmission\PostgresRegistryReleasePin as Pin;
use App\Support\FamilyPortal\RegistryAdmission\RegistryAdmissionFailure as Failure;
use App\Support\FamilyPortal\RegistryAdmission\RegistryReleasePinVerifier;
use App\Support\FamilyPortal\RegistryAdmission\ReleasePinVerification;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FamilyPortal\SyntheticReleasePinFixture as Fixture;
use Tests\Support\FamilyPortal\SyntheticReleasePinVerifier as Verifier;
use Tests\TestCase;
use Throwable;

/**
 * FB-FAMILY-ADD-01 ADD-WP-07B-2b: the release pin against a synthetic registry schema, on
 * PostgreSQL, with real separate sessions (and one separate PHP process for a publication
 * that waits). No identity_ref object is read or changed; no registry data exists here.
 * The fixture is committed (other sessions must see it) and dropped after each test; the
 * admission sessions only ever write the fixture's own probe table.
 */
class RegistryReleasePinTest extends TestCase
{
    private const RELEASE = 7;

    private Fixture $fixture;

    private Connection $admin;

    private Connection $lookup;

    /** @var list<string> */
    private array $databases = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('The release pin needs PostgreSQL.');
        }
        $this->fixture = new Fixture;
        $this->admin = Fixture::connection('admin');
        $this->lookup = Fixture::connection('lookup');
        $this->fixture->install($this->admin, self::RELEASE);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            foreach (array_keys(config('database.connections')) as $name) {
                if (str_starts_with($name, 'synthetic_pin_') && $name !== 'synthetic_pin_admin') {
                    try {
                        while (DB::connection($name)->transactionLevel() > 0) {
                            DB::connection($name)->rollBack();
                        }
                    } catch (Throwable) {
                    }
                    DB::disconnect($name);
                }
            }
            $this->fixture->uninstall($this->admin);
            foreach ($this->databases as $database) {
                $this->admin->unprepared("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
            }
            DB::disconnect('synthetic_pin_admin');
        }
        parent::tearDown();
    }

    private function pin(Connection $app, ?RegistryReleasePinVerifier $verifier = null): Pin
    {
        return new Pin($app, $verifier ?? new Verifier($this->lookup));
    }

    private function pinFails(callable $pin, string $detail): PinUnavailable
    {
        try {
            $pin();
            $this->fail("Pinned, expected {$detail}.");
        } catch (PinUnavailable $e) {
            $this->assertSame($detail, $e->detail);
            $this->assertSame($detail, $e->getMessage(), 'only the controlled code');

            return $e;
        }
    }

    /** @return list<object> pg_locks rows of the pin key */
    private function pinLocks(): array
    {
        [$high, $low] = Fixture::split(Pin::PIN_KEY);

        return $this->admin->select("SELECT pid, mode, granted, virtualtransaction FROM pg_locks
            WHERE locktype = 'advisory' AND classid = ?::oid AND objid = ?::oid AND objsubid = 1", [$high, $low]);
    }

    private function backendPid(Connection $c): int
    {
        return (int) $c->scalar('SELECT pg_backend_pid()');
    }

    private function probeRows(): int
    {
        return (int) $this->admin->scalar("SELECT count(*) FROM {$this->fixture->schema()}.probe_writes");
    }

    private function waitFor(callable $condition, string $label): void
    {
        $deadline = microtime(true) + 10;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail("Timed out waiting for: {$label}");
            }
            usleep(25000);
        }
    }

    // ---------------------------------------------------------------- the pin

    public function test_the_shared_pin_and_its_nonce_are_held_in_one_transaction_until_commit_or_rollback(): void
    {
        $app = Fixture::connection('app');
        $pid = $this->backendPid($app);

        foreach (['commit', 'rollBack'] as $end) {
            $app->beginTransaction();
            $this->pin($app)->pin(self::RELEASE);

            $locks = $this->admin->select("SELECT mode, granted, objsubid, virtualtransaction FROM pg_locks
                WHERE locktype = 'advisory' AND pid = ? ORDER BY objsubid", [$pid]);
            $this->assertCount(2, $locks, $end);
            $this->assertSame(['ShareLock', 'ExclusiveLock'], array_map(fn ($l) => $l->mode, $locks));
            $this->assertTrue($locks[0]->granted && $locks[1]->granted);
            $this->assertSame([1, 2], array_map(fn ($l) => (int) $l->objsubid, $locks), 'the bigint pin and the two-integer nonce');
            $this->assertSame($locks[0]->virtualtransaction, $locks[1]->virtualtransaction, 'one transaction');

            $app->{$end}();
            $this->assertSame([], $this->pinLocks(), "released on {$end}");
            $this->assertSame(0, (int) $this->admin->scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = ?", [$pid]));
        }

        // Several admissions share the pin.
        $other = Fixture::connection('app_b');
        $app->beginTransaction();
        $other->beginTransaction();
        $this->pin($app)->pin(self::RELEASE);
        $this->pin($other)->pin(self::RELEASE);
        $this->assertCount(2, $this->pinLocks());
    }

    public function test_a_publication_waits_for_an_admission_new_admissions_fail_meanwhile_and_it_completes_after(): void
    {
        $app = Fixture::connection('app');
        $app->beginTransaction();
        $this->pin($app)->pin(self::RELEASE);

        // A publication in another process: it takes 'FBCIV01A', then waits for the pin.
        $config = config('database.connections.pgsql');
        $code = <<<'PHP'
            $pdo = new PDO($argv[1], $argv[2], $argv[3]);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            try {
                $pdo->beginTransaction();
                $pdo->query('SELECT '.$argv[4].'.publish('.(int) $argv[5].', 10000)');
                $pdo->commit();
                echo 'PUBLISHED';
            } catch (PDOException $e) {
                echo 'FAILED:'.($e->errorInfo[0] ?? '?');
            }
        PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, '--',
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", (string) $config['username'], (string) ($config['password'] ?? ''),
            $this->fixture->schema(), (string) (self::RELEASE + 1)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        try {
            $this->waitFor(fn () => array_filter($this->pinLocks(), fn ($l) => $l->mode === 'ExclusiveLock' && ! $l->granted) !== [],
                'the publication to wait for the pin');
            $this->assertSame(self::RELEASE, $this->fixture->activeRelease($this->admin), 'not switched while the admission is open');

            // A new admission cannot pin while the publication waits: it is not starved.
            $late = Fixture::connection('app_late');
            $late->beginTransaction();
            $this->pinFails(fn () => $this->pin($late)->pin(self::RELEASE), PinUnavailable::BUSY);
            $late->rollBack();

            $app->commit();
            $this->waitFor(fn () => $this->fixture->activeRelease($this->admin) === self::RELEASE + 1, 'the publication to complete');
        } finally {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        $this->assertSame('PUBLISHED', $output);

        // The old release is gone for good; the reference must be discovered again.
        $app->beginTransaction();
        try {
            $this->pin($app)->pin(self::RELEASE);
            $this->fail('Pinned a replaced release.');
        } catch (RefException $e) {
            $this->assertSame(RefException::RELEASE_CHANGED, $e->reason);
        }
        $this->pin($app)->pin(self::RELEASE + 1);
    }

    public function test_a_publication_that_cannot_wait_long_enough_fails_and_changes_nothing(): void
    {
        $app = Fixture::connection('app');
        $app->beginTransaction();
        $this->pin($app)->pin(self::RELEASE);

        try {
            $this->fixture->publish(Fixture::connection('publisher'), self::RELEASE + 1, 200);
            $this->fail('Published over a held pin.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->errorInfo[0]);
        }
        $this->assertSame(self::RELEASE, $this->fixture->activeRelease($this->admin));
        $app->commit();
        $this->fixture->publish(Fixture::connection('publisher'), self::RELEASE + 1, 200);
        $this->assertSame(self::RELEASE + 1, $this->fixture->activeRelease($this->admin));
    }

    public function test_a_release_published_before_the_pin_is_release_changed(): void
    {
        $this->fixture->publish(Fixture::connection('publisher'), self::RELEASE + 1, 200);

        $app = Fixture::connection('app');
        $app->beginTransaction();
        try {
            $this->pin($app)->pin(self::RELEASE);
            $this->fail('Pinned a replaced release.');
        } catch (RefException $e) {
            $this->assertSame(RefException::RELEASE_CHANGED, $e->reason);
        }
        $this->assertSame(409, $e->status());
        $this->assertSame([], array_filter($this->pinLocks(), fn ($l) => $l->mode === 'ExclusiveLock'));
    }

    public function test_the_pointer_only_moves_forward_and_only_under_the_exclusive_pin(): void
    {
        $writer = Fixture::connection('publisher');
        foreach ([
            'rewind' => fn () => $this->fixture->publish($writer, self::RELEASE - 1, 200),
            'same' => fn () => $this->fixture->publish($writer, self::RELEASE, 200),
            'unpinned update' => fn () => $writer->update("UPDATE {$this->fixture->schema()}.active_release SET release_id = ? WHERE id = 1", [self::RELEASE + 3]),
        ] as $label => $write) {
            try {
                $write();
                $this->fail("Accepted: {$label}");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0], $label);
            }
        }
        $this->assertSame(self::RELEASE, $this->fixture->activeRelease($this->admin));
    }

    // ---------------------------------------------------------------- the proof

    public function test_a_pid_alone_proves_nothing(): void
    {
        $a = Fixture::connection('app');
        $b = Fixture::connection('app_b');
        $a->beginTransaction();
        $b->beginTransaction();
        $this->pin($a)->pin(self::RELEASE);
        $this->pin($b)->pin(self::RELEASE);
        $verifier = new Verifier($this->lookup);

        // Capture a's real nonce through a recording verifier.
        $recorded = new class($verifier) implements RegistryReleasePinVerifier
        {
            public ?array $call = null;

            public function __construct(private RegistryReleasePinVerifier $inner) {}

            public function releasePinHeld(int $holderPid, int $nonce): ReleasePinVerification
            {
                $this->call = [$holderPid, $nonce];

                return $this->inner->releasePinHeld($holderPid, $nonce);
            }
        };
        $this->pin($a, $recorded)->pin(self::RELEASE);
        [$pidA, $nonceA] = $recorded->call;
        $pidB = $this->backendPid($b);

        $this->assertTrue($verifier->releasePinHeld($pidA, $nonceA)->held, 'the real holder');
        // b holds the shared pin too, but not a's nonce: its pid does not prove a's transaction.
        $this->assertFalse($verifier->releasePinHeld($pidB, $nonceA)->held, 'another pinned session');
        $this->assertFalse($verifier->releasePinHeld($pidA, $nonceA === 1 ? 2 : $nonceA - 1)->held, 'a wrong nonce');
        $this->assertFalse($verifier->releasePinHeld(999999, $nonceA)->held, 'a pid that does not exist');
        $this->assertFalse($verifier->releasePinHeld($pidA, 0)->held, 'no nonce');
        $this->assertFalse($verifier->releasePinHeld($pidA, -$nonceA)->held, 'a negative nonce');

        // After a's transaction ends, the same pid and nonce prove nothing (no reuse).
        $a->commit();
        $this->assertFalse($verifier->releasePinHeld($pidA, $nonceA)->held, 'after commit');

        // A transaction holding a nonce-shaped lock but no pin is not pinned.
        $a->beginTransaction();
        $a->select('SELECT pg_try_advisory_xact_lock(?::integer, ?::integer)', [Pin::NONCE_NAMESPACE, 4242]);
        $this->assertFalse($verifier->releasePinHeld($pidA, 4242)->held, 'nonce without the pin');

        // A pin held on another session's pid: a verifier pointed at the wrong pid refuses it.
        $wrongPid = new class($verifier, $pidB) implements RegistryReleasePinVerifier
        {
            public function __construct(private RegistryReleasePinVerifier $inner, private int $pid) {}

            public function releasePinHeld(int $holderPid, int $nonce): ReleasePinVerification
            {
                return $this->inner->releasePinHeld($this->pid, $nonce);
            }
        };
        $this->pinFails(fn () => $this->pin($a, $wrongPid)->pin(self::RELEASE), PinUnavailable::NOT_PROVEN);
    }

    public function test_session_level_locks_of_an_idle_session_are_not_a_pin(): void
    {
        $idle = Fixture::connection('idle');
        $idle->select('SELECT pg_advisory_lock_shared(?::bigint), pg_advisory_lock(?::integer, ?::integer)', [Pin::PIN_KEY, Pin::NONCE_NAMESPACE, 4242]);
        $pid = $this->backendPid($idle);
        $verifier = new Verifier($this->lookup);

        $this->assertCount(1, array_filter($this->pinLocks(), fn ($l) => (int) $l->pid === $pid && $l->granted), 'the session lock exists');
        $this->assertFalse($verifier->releasePinHeld($pid, 4242)->held, 'no live transaction holds it');

        // Limitation (documented): pg_locks does not tell session-level from transaction-level
        // advisory locks, so the same session inside a transaction would be accepted. Only
        // PostgresRegistryReleasePin takes the nonce, and only at transaction level.
        $idle->beginTransaction();
        $idle->select('SELECT 1');
        $this->assertTrue($verifier->releasePinHeld($pid, 4242)->held, 'session locks inside a live transaction');
        $idle->rollBack();

        // A session-level pin outlives transactions and blocks publication (a denial of
        // service any database role can cause, as on 'FBCIV01A' today): nothing is published.
        try {
            $this->fixture->publish(Fixture::connection('publisher'), self::RELEASE + 1, 200);
            $this->fail('Published over a session-level pin.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->errorInfo[0]);
        }
        $idle->select('SELECT pg_advisory_unlock_all()');
        $this->fixture->publish(Fixture::connection('publisher'), self::RELEASE + 1, 200);
        $this->assertSame(self::RELEASE + 1, $this->fixture->activeRelease($this->admin));
    }

    public function test_a_nonce_collision_is_retryable_and_leaves_nothing_held(): void
    {
        $other = Fixture::connection('other');
        $other->beginTransaction();
        $other->select('SELECT pg_advisory_xact_lock(?::integer, ?::integer)', [Pin::NONCE_NAMESPACE, 4242]);

        $app = Fixture::connection('app');
        $app->beginTransaction();
        $e = $this->pinFails(fn () => (new Pin($app, new Verifier($this->lookup), fn () => 4242))->pin(self::RELEASE), PinUnavailable::NONCE_BUSY);
        $this->assertTrue($e->retryable);
        $app->rollBack();
        $this->assertSame([], $this->pinLocks(), 'the shared pin taken in the same statement ended with the rollback');

        // Another nonce succeeds while the colliding lock is still held.
        $app->beginTransaction();
        (new Pin($app, new Verifier($this->lookup), fn () => 4243))->pin(self::RELEASE);
        $app->rollBack();
        $other->rollBack();

        foreach ([0, -1, Pin::NONCE_MAX + 1] as $bad) {
            $app->beginTransaction();
            try {
                (new Pin($app, new Verifier($this->lookup), fn () => $bad))->pin(self::RELEASE);
                $this->fail("Accepted nonce {$bad}");
            } catch (\LogicException) {
                $this->assertSame([], $this->pinLocks(), "nothing locked for nonce {$bad}");
            }
            $app->rollBack();
        }
    }

    public function test_a_lookup_session_in_an_older_snapshot_proves_nothing(): void
    {
        $stale = Fixture::connection('stale_lookup');
        $stale->beginTransaction();
        $stale->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertSame(self::RELEASE, $this->fixture->activeRelease($stale), 'its snapshot is taken');

        $this->fixture->publish(Fixture::connection('publisher'), self::RELEASE + 1, 200);
        $this->assertSame(self::RELEASE, $this->fixture->activeRelease($stale), 'it still sees the old release');

        $app = Fixture::connection('app');
        $app->beginTransaction();
        // Without the isolation condition this old snapshot would confirm the replaced release.
        $this->pinFails(fn () => $this->pin($app, new Verifier($stale))->pin(self::RELEASE), PinUnavailable::NOT_PROVEN);
        $this->pinFails(fn () => $this->pin($app, new Verifier($stale))->pin(self::RELEASE + 1), PinUnavailable::NOT_PROVEN);
        $stale->rollBack();

        try {
            $this->pin($app, new Verifier($stale))->pin(self::RELEASE);
            $this->fail('Pinned a replaced release.');
        } catch (RefException $e) {
            $this->assertSame(RefException::RELEASE_CHANGED, $e->reason);
        }
    }

    public function test_the_documented_dba3_function_behaves_like_the_fixture(): void
    {
        // The ADR §10a SQL as written, pointed at the synthetic schema (no identity_ref object
        // exists here; the grants name roles the test cluster does not have, so they are left out).
        $s = $this->fixture->schema();
        $sql = RegistryReleasePinContractTest::documentedDba3();
        $this->assertNotSame('', $sql);
        $function = strstr($sql, 'REVOKE ALL', true);
        $function = str_replace(['identity_ref_lookup.release_pin_held', 'identity_ref.active_release'], ["{$s}.documented_pin_held", "{$s}.active_release"], $function);
        $this->admin->unprepared($function);
        $documented = new class($this->lookup, $s) implements RegistryReleasePinVerifier
        {
            public function __construct(private Connection $lookup, private string $schema) {}

            public function releasePinHeld(int $holderPid, int $nonce): ReleasePinVerification
            {
                return ReleasePinVerification::fromRow($this->lookup->selectOne(
                    "SELECT held, active_release_id FROM {$this->schema}.documented_pin_held(?, ?)", [$holderPid, $nonce]));
            }
        };

        $app = Fixture::connection('app');
        $app->beginTransaction();
        $this->pin($app, $documented)->pin(self::RELEASE);
        $this->pinFails(fn () => $this->pin($app, new Verifier($app, $s))->pin(self::RELEASE), PinUnavailable::NOT_PROVEN);
        $app->rollBack();

        $idle = Fixture::connection('idle');
        $idle->select('SELECT pg_advisory_lock_shared(?::bigint), pg_advisory_lock(?::integer, ?::integer)', [Pin::PIN_KEY, Pin::NONCE_NAMESPACE, 4242]);
        $this->assertFalse($documented->releasePinHeld($this->backendPid($idle), 4242)->held, 'an idle session');
        $idle->select('SELECT pg_advisory_unlock_all()');

        $stale = Fixture::connection('stale_lookup');
        $stale->beginTransaction();
        $stale->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $stale->select('SELECT 1');
        $app->beginTransaction();
        $staleDocumented = new class($stale, $s) implements RegistryReleasePinVerifier
        {
            public function __construct(private Connection $lookup, private string $schema) {}

            public function releasePinHeld(int $holderPid, int $nonce): ReleasePinVerification
            {
                return ReleasePinVerification::fromRow($this->lookup->selectOne(
                    "SELECT held, active_release_id FROM {$this->schema}.documented_pin_held(?, ?)", [$holderPid, $nonce]));
            }
        };
        $this->pinFails(fn () => $this->pin($app, $staleDocumented)->pin(self::RELEASE), PinUnavailable::NOT_PROVEN);
        $stale->rollBack();
    }

    public function test_the_verifier_on_the_admission_session_itself_proves_nothing(): void
    {
        $app = Fixture::connection('app');
        $app->beginTransaction();
        $this->pinFails(fn () => $this->pin($app, new Verifier($app))->pin(self::RELEASE), PinUnavailable::NOT_PROVEN);
    }

    public function test_a_registry_in_another_database_is_not_proven(): void
    {
        $database = 'famboook_test_pin_other';
        $this->admin->unprepared("DROP DATABASE IF EXISTS {$database} WITH (FORCE)");
        $this->admin->unprepared("CREATE DATABASE {$database}");
        $this->databases[] = $database;
        $elsewhere = Fixture::connection('elsewhere', $database);
        $this->fixture->install($elsewhere, self::RELEASE);

        $app = Fixture::connection('app');
        $app->beginTransaction();
        // The same function, the same release, in another database: our locks are invisible there.
        $this->pinFails(fn () => $this->pin($app, new Verifier($elsewhere))->pin(self::RELEASE), PinUnavailable::NOT_PROVEN);
        $this->assertCount(1, $this->pinLocks(), 'the pin itself was taken, in our database');
        $app->rollBack();
        DB::disconnect('synthetic_pin_elsewhere');
    }

    // ---------------------------------------------------------------- fail closed

    public function test_only_read_committed_inside_a_transaction_may_pin(): void
    {
        $app = Fixture::connection('app');
        $this->pinFails(fn () => $this->pin($app)->pin(self::RELEASE), PinUnavailable::NOT_IN_TRANSACTION);

        foreach (['REPEATABLE READ', 'SERIALIZABLE'] as $level) {
            $app->beginTransaction();
            $app->statement("SET TRANSACTION ISOLATION LEVEL {$level}");
            $this->pinFails(fn () => $this->pin($app)->pin(self::RELEASE), PinUnavailable::ISOLATION_UNSUPPORTED);
            $this->assertSame([], $this->pinLocks(), "{$level}: nothing locked");
            $app->rollBack();
        }
    }

    public function test_a_busy_pin_or_an_unavailable_verifier_fails_closed(): void
    {
        $publisher = Fixture::connection('publisher');
        $publisher->beginTransaction();
        $publisher->select('SELECT pg_advisory_xact_lock(?::bigint)', [Pin::PIN_KEY]);

        $app = Fixture::connection('app');
        $app->beginTransaction();
        $this->assertTrue($this->pinFails(fn () => $this->pin($app)->pin(self::RELEASE), PinUnavailable::BUSY)->retryable);
        $app->rollBack();
        $publisher->rollBack();

        // The verifier function is missing (DBA-3 not applied), or its session cannot be used.
        $missing = new Verifier($this->lookup, 'synthetic_release_pin_missing');
        $badLookup = Fixture::connection('bad_lookup', 'famboook_no_such_database');
        foreach (['missing function' => $missing, 'unreachable lookup' => new Verifier($badLookup)] as $label => $verifier) {
            $app->beginTransaction();
            $e = $this->pinFails(fn () => $this->pin($app, $verifier)->pin(self::RELEASE), PinUnavailable::VERIFIER_UNAVAILABLE);
            $this->assertFalse($e->retryable, $label);
            $app->rollBack();
        }
    }

    public function test_failures_roll_back_through_the_failure_contract_and_log_no_detail_beyond_the_code(): void
    {
        $logged = [];
        Log::shouldReceive('error')->andReturnUsing(function (string $message, array $context) use (&$logged) {
            $logged[] = [$message, $context];
        });
        $app = Fixture::connection('app');
        $probe = fn () => $app->insert("INSERT INTO {$this->fixture->schema()}.probe_writes (note) VALUES (?)", ['written before the pin']);

        // 1. The pin is busy: nothing the transaction wrote survives.
        $publisher = Fixture::connection('publisher');
        $publisher->beginTransaction();
        $publisher->select('SELECT pg_advisory_xact_lock(?::bigint)', [Pin::PIN_KEY]);
        $busy = $this->unavailable(fn () => $app->transaction(function () use ($app, $probe) {
            $probe();
            $this->pin($app)->pin(self::RELEASE);
        }));
        $publisher->rollBack();
        $this->assertTrue($busy->retryable);
        $this->assertSame(0, $this->probeRows());

        // 2. Pinned, then a row lock times out: rolled back, and the pin is released with it.
        $holder = Fixture::connection('holder');
        $holder->beginTransaction();
        $holder->select("SELECT id FROM {$this->fixture->schema()}.active_release WHERE id = 1 FOR UPDATE");
        $timeout = $this->unavailable(fn () => $app->transaction(function () use ($app, $probe) {
            $this->pin($app)->pin(self::RELEASE);
            $probe();
            $app->statement("SET LOCAL lock_timeout = '150ms'");
            $app->select("SELECT id FROM {$this->fixture->schema()}.active_release WHERE id = 1 FOR UPDATE");
        }));
        $holder->rollBack();
        $this->assertTrue($timeout->retryable);
        $this->assertSame(0, $this->probeRows());
        $this->assertSame([], $this->pinLocks(), 'the pin ended with the transaction');

        // 3. The verifier is unavailable: not retryable, nothing written.
        $missing = $this->unavailable(fn () => $app->transaction(function () use ($app, $probe) {
            $probe();
            $this->pin($app, new Verifier($this->lookup, 'synthetic_release_pin_missing'))->pin(self::RELEASE);
        }));
        $this->assertFalse($missing->retryable);
        $this->assertSame(0, $this->probeRows());

        // 4. RELEASE_CHANGED is an answer (409), not a failure: it passes through unlogged.
        try {
            Failure::run(fn () => $app->transaction(function () use ($app, $probe) {
                $probe();
                $this->pin($app)->pin(self::RELEASE + 9);
            }));
            $this->fail('Pinned a release that is not active.');
        } catch (RefException $e) {
            $this->assertSame(RefException::RELEASE_CHANGED, $e->reason);
        }
        $this->assertSame(0, $this->probeRows());

        $this->assertSame([PinUnavailable::BUSY, Failure::LOCK_TIMEOUT, PinUnavailable::VERIFIER_UNAVAILABLE],
            array_map(fn ($l) => $l[1]['failure'], $logged));
        foreach ($logged as [$message, $context]) {
            $this->assertSame('Registry admission failed closed.', $message);
            $this->assertSame(['correlation_id', 'failure', 'exception', 'sqlstate'], array_keys($context));
        }
        $everything = json_encode($logged).json_encode([$busy->render()->getData(true), $timeout->render()->getData(true), $missing->render()->getData(true)]);
        foreach (['written before the pin', 'probe_writes', 'release_pin_held', 'pg_locks', 'SELECT'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything, $secret);
        }
    }

    private function unavailable(callable $work): RegistryAdmissionUnavailableException
    {
        try {
            Failure::run($work);
            $this->fail('Nothing failed.');
        } catch (RegistryAdmissionUnavailableException $e) {
            $this->assertSame(503, $e->render()->getStatusCode());

            return $e;
        }
    }
}
