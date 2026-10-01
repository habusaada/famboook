<?php

namespace Tests\Feature\Import;

use App\Actions\RecordImportApplyEffectAction;
use App\Enums\ImportApplyEffect as E;
use App\Enums\ImportApplyOutcome as O;
use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportApplyExecutionException;
use App\Models\ImportApplyRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Import\Apply\ImportApplyLifecycle;
use App\Support\Import\Apply\PostgresApplyRunnerLock;
use App\Support\NationalIdGuard;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

/**
 * Database-level Apply guarantees (docs/03 §96b). The PostgreSQL-only tests
 * run with `phpunit -c phpunit.pgsql.xml` against the dedicated `_test`
 * database and are SKIPPED on SQLite — SQLite never proves PostgreSQL
 * locking. The savepoint and lifecycle tests run on both.
 */
class ApplyDatabaseIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $extraConnections = [];

    protected function tearDown(): void
    {
        foreach ($this->extraConnections as $name) {
            DB::purge($name);
        }
        parent::tearDown();
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against the dedicated _test database.');
        }
    }

    /** An independent session (its own connection) to the SAME test database. */
    private function pgSession(string $name): Connection
    {
        config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);
        $this->extraConnections[] = $name;

        return DB::connection($name);
    }

    private function startedRow(): ImportRow
    {
        $batch = ImportBatch::factory()->create(['status' => ImportBatchStatus::APPLYING, 'apply_started_at' => now(), 'apply_plan_fingerprint' => hash('sha256', 'synthetic-plan')]);

        return ImportRow::factory()->create(['import_batch_id' => $batch->id, 'row_number' => 2]);
    }

    // ================================================== PostgreSQL only

    public function test_the_postgres_suite_runs_on_a_dedicated_test_database(): void
    {
        $this->requirePostgres();
        $server = DB::selectOne("SELECT current_database() AS d, inet_server_port() AS p, current_setting('server_encoding') AS e");
        $env = base_path('.env');

        $this->assertStringEndsWith('_test', $server->d);
        // Arabic registry text requires UTF8 (as in development).
        $this->assertSame('UTF8', $server->e);
        $this->assertNotSame(TestDatabaseGuard::developmentDatabase($env), $server->d);
        // Connected to the configured TEST server port — never the development server's.
        $this->assertSame((int) config('database.connections.pgsql.port'), (int) $server->p);
        $this->assertNotSame((int) TestDatabaseGuard::developmentValue($env, 'DB_PORT'), (int) $server->p);
    }

    public function test_the_runner_lock_excludes_a_second_postgres_session(): void
    {
        $this->requirePostgres();
        $b = $this->pgSession('apply_session_b');
        $raw = fn ($c) => (bool) $c->selectOne('SELECT pg_try_advisory_lock(?, ?) AS l', [PostgresApplyRunnerLock::NAMESPACE, 424242])->l;
        $unlock = fn ($c) => $c->selectOne('SELECT pg_advisory_unlock(?, ?) AS u', [PostgresApplyRunnerLock::NAMESPACE, 424242]);

        // Raw SQL, two sessions.
        $this->assertTrue($raw(DB::connection()));
        $this->assertFalse($raw($b));
        $unlock(DB::connection());
        $this->assertTrue($raw($b));
        $unlock($b);

        // The actual implementation, one instance per session.
        $a = new PostgresApplyRunnerLock;
        $other = new PostgresApplyRunnerLock('apply_session_b');
        $this->assertTrue($a->acquire(424242));
        $this->assertFalse($other->acquire(424242));
        $a->release(424242);
        $this->assertTrue($other->acquire(424242));
        $this->assertFalse($a->acquire(424242));
        $other->release(424242);
        $this->assertTrue($a->acquire(424242));
        $a->release(424242);
    }

    public function test_the_national_id_guard_lock_excludes_a_concurrent_session(): void
    {
        $this->requirePostgres();
        $a = $this->pgSession('guard_session_a');
        $b = $this->pgSession('guard_session_b');
        $tryLock = fn () => (bool) $b->selectOne('SELECT pg_try_advisory_xact_lock(hashtext(?)) AS l', ['famboook.national_id:950000001'])->l;

        // Session A runs the real guard inside its own transaction.
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('guard_session_a');
        try {
            $a->beginTransaction();
            NationalIdGuard::assertAvailable('950000001', 'national_id');

            $b->beginTransaction();
            $this->assertFalse($tryLock()); // B cannot claim the same National ID meanwhile
            $b->rollBack();

            $a->rollBack(); // A's transaction ends: its lock is released
            $b->beginTransaction();
            $this->assertTrue($tryLock());
            $b->rollBack();
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    // ================================================== both drivers

    public function test_the_provenance_writer_recovers_from_a_unique_conflict_inside_its_savepoint(): void
    {
        $row = $this->startedRow();
        $user = User::factory()->create();
        // A concurrent writer: the lookup misses once, so the INSERT itself hits
        // the unique (import_row_id, effect_key) index inside the savepoint.
        $writer = new class extends RecordImportApplyEffectAction
        {
            public bool $miss = true;

            protected function existing(ImportRow $row, E $effect): ?ImportApplyRecord
            {
                if ($this->miss) {
                    $this->miss = false;

                    return null;
                }

                return parent::existing($row, $effect);
            }
        };

        DB::transaction(function () use ($row, $user, $writer) {
            ImportApplyRecord::record($row, E::RESIDENCE, O::OMITTED, null, 'NO_ORIGINAL_RESIDENCE', $user->id);

            // Equivalent → the existing record, and the row transaction is still usable.
            $same = $writer->handle($row, E::RESIDENCE, O::OMITTED, null, 'NO_ORIGINAL_RESIDENCE', $user->id);
            $this->assertSame('NO_ORIGINAL_RESIDENCE', $same->reason_code);
            $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS one')->one);

            // Different → PROVENANCE_CONFLICT, and still no aborted transaction.
            $writer->miss = true;
            try {
                $writer->handle($row, E::RESIDENCE, O::OMITTED, null, 'OTHER_REASON', $user->id);
                $this->fail('Expected PROVENANCE_CONFLICT.');
            } catch (ImportApplyExecutionException $e) {
                $this->assertSame('PROVENANCE_CONFLICT', $e->errorCode);
            }
            ImportApplyRecord::record($row, E::HOUSEHOLD_DECLARATION, O::OMITTED, null, 'NO_DECLARED_VALUES', $user->id);
        });

        $this->assertSame(2, ImportApplyRecord::where('import_row_id', $row->id)->count());
    }

    public function test_the_lifecycle_records_errors_only_on_partially_applied_batches_through_the_model(): void
    {
        $fp = hash('sha256', 'synthetic-plan');
        $partial = ImportBatch::factory()->create(['status' => ImportBatchStatus::PARTIALLY_APPLIED, 'apply_started_at' => now(), 'apply_plan_fingerprint' => $fp]);

        ImportApplyLifecycle::recordPartialError($partial, 'APPLY_PLAN_CHANGED');
        $this->assertSame(['PARTIALLY_APPLIED', 'APPLY_PLAN_CHANGED', null], [$partial->fresh()->status->value, $partial->fresh()->apply_error_code, $partial->fresh()->apply_error_row_number]);

        // Model invariants apply: raw text is refused and nothing changes.
        try {
            ImportApplyLifecycle::recordPartialError($partial, 'SQLSTATE[23505]: duplicate key 950000001', 4);
            $this->fail('Raw text must be refused.');
        } catch (LogicException) {
        }
        $this->assertSame('APPLY_PLAN_CHANGED', $partial->fresh()->apply_error_code);

        // Any other status is refused, and the status itself never changes here.
        $applying = ImportBatch::factory()->create(['status' => ImportBatchStatus::APPLYING, 'apply_started_at' => now(), 'apply_plan_fingerprint' => $fp]);
        try {
            ImportApplyLifecycle::recordPartialError($applying, 'X_CODE');
            $this->fail('Only PARTIALLY_APPLIED batches take an error here.');
        } catch (ImportApplyExecutionException $e) {
            $this->assertSame('APPLY_STATE_INVALID', $e->errorCode);
        }
        $this->assertSame(['APPLYING', null], [$applying->fresh()->status->value, $applying->fresh()->apply_error_code]);
    }
}
