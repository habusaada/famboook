<?php

namespace Tests\Feature\IdentityRef;

use App\Actions\StageIdentityImportAction;
use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityStagingPurger;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\RecordingLogger;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A — staging purge remediation: bounded, independently committed
 * batches (IdentityStagingPurger), resumable and idempotent, never touching a
 * RUNNING run, the active release or reference data; abort, rejection and
 * publication stay consistent when their purge is interrupted. PostgreSQL
 * only, real writer logins, SYNTHETIC data only. (Assessments absent: see
 * IdentityRefStagingPostgresTest, which runs without 03-publication.sql.)
 */
class IdentityRefStagingPurgePostgresTest extends TestCase
{
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

    /** 3 files × 40 rows, staged in chunks of 7: 18 chunks, 120 rows, 120 assessments. */
    private const FILES = 3;

    private const ROWS = 40;

    private const STAGING_CHUNK = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityRef(withPublication: true);
    }

    protected function tearDown(): void
    {
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    private function stager(): StageIdentityImportAction
    {
        return app(StageIdentityImportAction::class);
    }

    /** A staged and prepared (assessed) run of the synthetic 3 × 40 export; returns [runId, releaseId]. */
    private function preparedRun(int $firstId = 900_000_001): array
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(self::FILES, self::ROWS, $firstId));
        $run = $this->stage($export, ['chunk_size' => self::STAGING_CHUNK])['run_id'];

        return [$run, $this->prepareRun($run, null, null, 50)['release_id']];
    }

    /** Throws once the purge has committed $after batches of rows (a crash between batches). */
    private static function crashAfter(int $after): Closure
    {
        $seen = 0;

        return function (string $event, array $context) use (&$seen, $after) {
            if ($event === 'purge.batch_committed' && $context['rows'] > 0 && ++$seen >= $after) {
                throw new RuntimeException('simulated crash after a committed purge batch');
            }
        };
    }

    /**
     * Publishes the prepared run while its post-publication purge crashes after the first
     * committed batch: the release is active and the staging of the finished run is left over.
     *
     * @return array{run: string, release: int, report: array<string, mixed>, logger: RecordingLogger}
     */
    private function publishedWithLeftovers(int $firstId = 900_000_001): array
    {
        [$run, $release] = $this->preparedRun($firstId);
        $this->verifyRelease($release);
        $logger = new RecordingLogger;
        $report = $this->publishRelease($release, self::crashAfter(1), $logger);

        return ['run' => $run, 'release' => $release, 'report' => $report, 'logger' => $logger];
    }

    /** @return array{rows: int, assessments: int, chunks: int} staging of one run (read as the administrator) */
    private function stagingOf(string $runId): array
    {
        $count = fn (string $table) => (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref_staging.{$table} t
            JOIN identity_ref.import_files f ON f.id = t.file_id WHERE f.run_id = ?", [$runId])->n;

        return ['rows' => $count('rows'), 'assessments' => $count('assessments'), 'chunks' => $count('file_chunks')];
    }

    /** @return array<string, mixed> everything published: pointer, active set, reference tables, release states */
    private function publishedState(): array
    {
        return ['active' => $this->activeReleaseId(), 'set' => $this->activeSet(), 'counts' => $this->referenceCounts(),
            'releases' => array_map(fn ($r) => (array) $r, DB::select('SELECT id, status, record_count FROM identity_ref.dataset_releases ORDER BY id'))];
    }

    /** 1, 5, 9 — a finished run is purged in bounded committed batches; repeating finds nothing; the active release never moves. */
    public function test_a_finished_run_is_purged_in_bounded_batches_and_repeating_is_harmless(): void
    {
        $published = $this->publishedWithLeftovers();
        $run = $published['run'];
        // The default batch (1000) took the whole first file before the crash: its rows are gone, its chunks remain.
        $this->assertSame(['rows' => 80, 'assessments' => 80, 'chunks' => 18], $this->stagingOf($run));
        $before = $this->publishedState();

        $events = [];
        $report = $this->stager()->purge($this->writerConnection(), 'famboook_test', 10, null, function (string $event, array $context) use (&$events, $before) {
            $events[] = $context;
            $this->assertSame($before, $this->publishedState(), 'the active release never moves during the purge');
        });

        $this->assertSame(['complete' => true, 'files_purged' => 3, 'rows_purged' => 80, 'assessments_purged' => 80, 'chunks_purged' => 18, 'batches' => 8],
            array_diff_key($report, ['remaining' => 0]));
        $this->assertSame(['files' => 0, 'rows' => 0, 'assessments' => 0, 'chunks' => 0, 'protected_rows' => 0], $report['remaining']);
        $rowBatches = array_values(array_filter($events, fn ($e) => $e['rows'] > 0));
        $this->assertCount(8, $rowBatches);
        foreach ($rowBatches as $batch) {
            $this->assertLessThanOrEqual(10, $batch['rows'], 'bounded');
            $this->assertSame($batch['rows'], $batch['assessments'], 'each batch removes the assessments of exactly its rows');
        }
        $this->assertSame(['rows' => 0, 'assessments' => 0, 'chunks' => 0], $this->stagingOf($run));
        $this->assertSame($before, $this->publishedState());

        // 5 — repeated after completion: nothing in scope, nothing deleted.
        $again = $this->stager()->purge($this->writerConnection(), 'famboook_test', 10);
        $this->assertSame([true, 0, 0, 0, 0, 0], [$again['complete'], $again['files_purged'], $again['rows_purged'], $again['assessments_purged'],
            $again['chunks_purged'], $again['batches']]);
        $this->assertSame($before, $this->publishedState());
    }

    /** 2 — the staging of a RUNNING run is never purged: fully staged, or interrupted mid-file (where the database guard would not protect it). */
    public function test_the_staging_of_a_running_run_is_never_purged(): void
    {
        $finished = $this->publishedWithLeftovers()['run'];

        // A fully staged RUNNING run.
        $running = $this->stage($this->syntheticExport(SyntheticIdentityExport::generated(2, 9, 910_000_001)), ['chunk_size' => 4])['run_id'];
        $report = $this->stager()->purge($this->writerConnection(), 'famboook_test', 5);
        $this->assertTrue($report['complete']);
        $this->assertSame(['rows' => 0, 'assessments' => 0, 'chunks' => 0], $this->stagingOf($finished));
        $this->assertSame(['rows' => 18, 'assessments' => 0, 'chunks' => 6], $this->stagingOf($running));
        $this->assertSame(18, $report['remaining']['protected_rows']);
        $this->stager()->abort($this->writerConnection(), 'famboook_test');

        // A RUNNING run interrupted inside a file (VERIFIED, not STAGED): only the purge scope protects it.
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 12, 920_000_001));
        $commits = 0;
        $interrupted = $this->refused(fn () => $this->stage($export, ['chunk_size' => 4], null, function (string $event) use (&$commits) {
            if ($event === 'chunk.committed' && ++$commits === 2) {
                throw new RuntimeException('simulated crash');
            }
        }));
        $this->assertSame(IdentityImportException::STAGING_INTERRUPTED, $interrupted->reason);
        $this->assertSame('VERIFIED', DB::selectOne('SELECT status FROM identity_ref.import_files WHERE run_id = ?', [$interrupted->runId])->status);
        $this->assertSame(['rows' => 8, 'assessments' => 0, 'chunks' => 2], $this->stagingOf($interrupted->runId));
        $this->assertTrue($this->stager()->purge($this->writerConnection(), 'famboook_test', 3)['complete']);
        $this->assertSame(['rows' => 8, 'assessments' => 0, 'chunks' => 2], $this->stagingOf($interrupted->runId));

        // The interrupted run still resumes from its checkpoints.
        $resumed = $this->stage($export, ['chunk_size' => 4]);
        $this->assertSame([true, 2, 12], [$resumed['resumed'], $resumed['chunks_verified'], $resumed['rows_staged']]);
    }

    /** 3, 4 — interrupted after committed batches (a crash, then a real lock timeout): no orphan, atomic batches, resumable. */
    public function test_an_interrupted_purge_leaves_no_orphan_and_resumes(): void
    {
        $run = $this->publishedWithLeftovers()['run'];
        $logger = new RecordingLogger;

        // A crash after three committed batches of 10 (file 1 had no rows left: only its 6 chunks went first).
        $first = $this->stager()->purge($this->writerConnection(), 'famboook_test', 10, $logger, self::crashAfter(3));
        $this->assertSame([false, 30, 30, 6], [$first['complete'], $first['rows_purged'], $first['assessments_purged'], $first['chunks_purged']]);
        $this->assertSame(['rows' => 50, 'assessments' => 50, 'chunks' => 12], $this->stagingOf($run), 'assessments go with their rows; chunks last');
        $this->assertSame(['files' => 2, 'rows' => 50, 'assessments' => 50, 'chunks' => 12, 'protected_rows' => 0], $first['remaining']);
        $this->assertSame($first['remaining'], array_diff_key($this->stager()->purgePreview($this->writerConnection(), 'famboook_test'), ['runs' => 0]),
            'the dry run counts the same');
        $this->assertContains('identity_ref.staging.purge_interrupted', $logger->messages());

        // A real database interruption: another session holds ONE row of a later batch (the 16th remaining row:
        // picked first, then locked alone — FOR UPDATE with OFFSET would lock the skipped rows too); lock_timeout cancels that batch.
        $holder = $this->connection();
        $holder->beginTransaction();
        $holder->query("SELECT 1 FROM identity_ref_staging.rows WHERE (file_id, record_no) = (SELECT r.file_id, r.record_no
            FROM identity_ref_staging.rows r JOIN identity_ref.import_files f ON f.id = r.file_id
            WHERE f.run_id = '{$run}' ORDER BY r.file_id, r.record_no OFFSET 15 LIMIT 1) FOR UPDATE");
        $writer = $this->writerConnection();
        $writer->statement("SET lock_timeout = '300ms'");
        $diag = new RecordingLogger;
        $second = $this->stager()->purge($writer, 'famboook_test', 10, $diag);
        $holder->rollBack();
        $this->assertSame([false, 10, 10], [$second['complete'], $second['rows_purged'], $second['assessments_purged']]);
        $this->assertStringContainsString('sqlstate=55P03', $diag->text());
        // File 2 finished (10 rows + 6 chunks); file 3's first batch hit the locked row and rolled back as a whole.
        $this->assertSame(['rows' => 40, 'assessments' => 40, 'chunks' => 6], $this->stagingOf($run), 'the cancelled batch rolled back as a whole');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.assessments a
            WHERE NOT EXISTS (SELECT 1 FROM identity_ref_staging.rows r WHERE r.file_id = a.file_id AND r.record_no = a.record_no)')->n);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.rows r
            WHERE NOT EXISTS (SELECT 1 FROM identity_ref_staging.file_chunks c WHERE c.file_id = r.file_id AND c.chunk_no = r.chunk_no)')->n);

        // Resumed: the rest, once.
        $third = $this->stager()->purge($this->writerConnection(), 'famboook_test', 10);
        $this->assertSame([true, 40, 40, 6], [$third['complete'], $third['rows_purged'], $third['assessments_purged'], $third['chunks_purged']]);
        $this->assertSame(['rows' => 0, 'assessments' => 0, 'chunks' => 0], $this->stagingOf($run));
        $this->assertSame(120, 40 + $first['rows_purged'] + $second['rows_purged'] + $third['rows_purged'], 'every row purged exactly once');
    }

    /** 3 — foreign keys decide the order: rows cannot go before their assessments, chunks not before their rows. */
    public function test_the_foreign_keys_enforce_the_deletion_order(): void
    {
        $run = $this->publishedWithLeftovers()['run'];
        $writer = $this->connection(self::WRITER);
        $file = (int) DB::selectOne('SELECT f.id FROM identity_ref.import_files f JOIN identity_ref_staging.rows r ON r.file_id = f.id
            WHERE f.run_id = ? LIMIT 1', [$run])->id;
        $this->assertSame('23001', $this->sqlStateOf(fn () => $writer->exec("DELETE FROM identity_ref_staging.rows WHERE file_id = {$file}")));
        $this->assertSame('23001', $this->sqlStateOf(fn () => $writer->exec("DELETE FROM identity_ref_staging.file_chunks WHERE file_id = {$file}")));
        $this->assertSame(['rows' => 80, 'assessments' => 80, 'chunks' => 18], $this->stagingOf($run));
    }

    /** 6 + Decision 1 — abort commits the closure first; an interrupted purge never reopens the run and is reported. */
    public function test_abort_closes_the_run_before_purging_and_reports_an_incomplete_purge(): void
    {
        [$run, $release] = $this->preparedRun();
        $logger = new RecordingLogger;

        $result = $this->stager()->abort($this->writerConnection(), 'famboook_test', $logger, self::crashAfter(2), 10);
        $this->assertSame(['aborted_run' => $run, 'abandoned_release' => $release, 'rows_purged' => 20, 'staging_purge_complete' => false], $result);
        $this->assertSame(['FAILED', 'ABORTED'], [$this->importRun($run)->status, $this->importRun($run)->error_code]);
        $this->assertSame('ABANDONED', DB::selectOne('SELECT status FROM identity_ref.dataset_releases WHERE id = ?', [$release])->status);
        $this->assertSame(['rows' => 100, 'assessments' => 100, 'chunks' => 18], $this->stagingOf($run));
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref.import_runs WHERE status = 'RUNNING'")->n);
        $this->assertContains('identity_ref.stage.aborted_purge_incomplete', $logger->messages());
        $this->assertStringNotContainsString('900000', $logger->text());

        // Nothing contradictory remains: a new run can start, and the next abort finishes the purge.
        $next = $this->stage($this->syntheticExport(SyntheticIdentityExport::generated(1, 5, 930_000_001)))['run_id'];
        $this->assertSame('RUNNING', $this->importRun($next)->status);
        $again = $this->stager()->abort($this->writerConnection(), 'famboook_test');
        $this->assertSame([$next, null, 105, true], [$again['aborted_run'], $again['abandoned_release'], $again['rows_purged'], $again['staging_purge_complete']]);
        $this->assertSame(0, $this->stagedCount());
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.assessments')->n);
    }

    /** 7 — a rejected source: the run is REJECTED (committed) whatever happens to its purge; the leftovers are purged later. */
    public function test_a_rejected_source_is_rejected_consistently_even_if_its_purge_is_interrupted(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 30));
        $commits = 0;
        $crash = $this->refused(fn () => $this->stage($export, ['chunk_size' => 5], null, function (string $event) use (&$commits) {
            if ($event === 'chunk.committed' && ++$commits === 4) {
                throw new RuntimeException('simulated crash');
            }
        }));
        $run = $crash->runId;
        $bytes = (string) file_get_contents($export->path('t03maindata_part_00001.csv'));
        $export->replaceBytes('t03maindata_part_00001.csv', str_replace('900000005', '900000099', $bytes), false);

        // The re-validation rejects the source; its purge crashes after the first committed batch (default 1000: all 20 rows).
        $rejected = $this->refused(fn () => $this->stage($export, [], null, self::crashAfter(1)));
        $this->assertSame([IdentityImportException::SOURCE_INVALID, $run], [$rejected->reason, $rejected->runId]);
        $this->assertSame(['REJECTED', 'SOURCE_INVALID'], [$this->importRun($run)->status, $this->importRun($run)->error_code]);
        $this->assertSame('FAILED', DB::selectOne('SELECT status FROM identity_ref.import_files WHERE run_id = ?', [$run])->status);
        $this->assertSame(['rows' => 0, 'assessments' => 0, 'chunks' => 4], $this->stagingOf($run));

        $purge = $this->stager()->purge($this->writerConnection(), 'famboook_test');
        $this->assertSame([true, 1, 0, 4], [$purge['complete'], $purge['files_purged'], $purge['rows_purged'], $purge['chunks_purged']]);
        $this->assertSame(['rows' => 0, 'assessments' => 0, 'chunks' => 0], $this->stagingOf($run));
        $this->assertSame('REJECTED', $this->importRun($run)->status);
    }

    /** 8, 9 — a publication stays successful when its purge fails: a crash, then a real database error. */
    public function test_a_publication_succeeds_even_if_its_purge_fails(): void
    {
        $published = $this->publishedWithLeftovers();
        $report = $published['report'];
        $this->assertSame(['PUBLISHED', null, $published['release']], [$report['outcome'], $report['staging_rows_purged'], $report['active_release']]);
        $this->assertSame($published['release'], $this->activeReleaseId());
        $this->assertSame('SUCCEEDED', $this->importRun($published['run'])->status);
        $this->assertContains('identity_ref.release.staging_purge_failed', $published['logger']->messages());
        $this->assertStringNotContainsString('900000', $published['logger']->text());
        $this->assertSame('ALREADY_PUBLISHED', $this->publishRelease($published['release'])['outcome']);

        // A second release whose purge hits a real lock timeout: still published; the leftovers wait for the next purge.
        [$run2, $release2] = $this->preparedRun(900_000_101);
        $this->verifyRelease($release2);
        $holder = $this->connection();
        $holder->beginTransaction();
        $holder->query("SELECT 1 FROM identity_ref_staging.rows WHERE (file_id, record_no) = (SELECT r.file_id, r.record_no
            FROM identity_ref_staging.rows r JOIN identity_ref.import_files f ON f.id = r.file_id
            WHERE f.run_id = '{$published['run']}' ORDER BY r.file_id, r.record_no LIMIT 1) FOR UPDATE");
        $writer = $this->writerConnection();
        $writer->statement("SET lock_timeout = '300ms'");
        $second = $this->releases()->publish($writer, $release2, 'famboook_test');
        $holder->rollBack();
        $this->assertSame(['PUBLISHED', null, $release2], [$second['outcome'], $second['staging_rows_purged'], $second['active_release']]);
        $this->assertSame($release2, $this->activeReleaseId());
        $this->assertSame(['SUCCEEDED', 'SUCCEEDED'], [$this->importRun($published['run'])->status, $this->importRun($run2)->status]);

        $purge = $this->stager()->purge($this->writerConnection(), 'famboook_test');
        $this->assertTrue($purge['complete']);
        $this->assertSame(0, $this->stagedCount());
        $this->assertSame($release2, $this->activeReleaseId());
    }

    /** 10 — while a purge holds the import lock, staging, publication and a second purge are refused; and the reverse. */
    public function test_the_purge_is_serialized_with_imports_and_publication(): void
    {
        $this->publishedWithLeftovers();
        [, $release2] = $this->preparedRun(900_000_101);
        $this->verifyRelease($release2);
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 3, 940_000_001));

        $seen = new \ArrayObject;
        $report = $this->stager()->purge($this->writerConnection(), 'famboook_test', 10, null, function (string $event) use ($seen, $release2, $export) {
            if (isset($seen['stage'])) {
                return;
            }
            $seen['stage'] = $this->refused(fn () => $this->stage($export))->reason;
            $seen['publish'] = $this->refused(fn () => $this->publishRelease($release2))->reason;
            $seen['purge'] = $this->refused(fn () => $this->stager()->purge($this->writerConnection(), 'famboook_test'))->reason;
            $seen['abort'] = $this->refused(fn () => $this->stager()->abort($this->writerConnection(), 'famboook_test'))->reason;
        });
        $this->assertTrue($report['complete']);
        $this->assertSame(array_fill_keys(['stage', 'publish', 'purge', 'abort'], IdentityImportException::CONCURRENT_IMPORT), $seen->getArrayCopy());

        // The reverse: a purge while a publication holds the lock is refused.
        $this->publishRelease($release2, function (string $event) use ($seen) {
            if ($event === 'publish.before_commit') {
                $seen['purge_during_publication'] = $this->refused(fn () => $this->stager()->purge($this->writerConnection(), 'famboook_test'))->reason;
            }
        });
        $this->assertSame(IdentityImportException::CONCURRENT_IMPORT, $seen['purge_during_publication']);
        $this->assertSame($release2, $this->activeReleaseId());

        // The purger refuses to run inside a transaction: no batch would commit on its own.
        $writer = $this->writerConnection();
        $writer->unprepared('SET ROLE '.self::WRITER);
        $this->expectException(LogicException::class);
        $writer->transaction(fn () => (new IdentityStagingPurger($writer))->purge());
    }

    /** 12 — least privilege is unchanged and the staging tables really are UNLOGGED (no WAL for their data). */
    public function test_least_privilege_and_unlogged_staging_are_preserved(): void
    {
        $privileges = fn (string $role, string $table) => array_values(array_filter(['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'],
            fn ($p) => DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, "identity_ref_staging.{$table}", $p])->ok));
        $this->assertSame(['SELECT', 'INSERT', 'DELETE'], $privileges(self::WRITER, 'rows'));
        $this->assertSame(['SELECT', 'INSERT', 'DELETE'], $privileges(self::WRITER, 'file_chunks'));
        $this->assertSame(['SELECT', 'DELETE'], $privileges(self::WRITER, 'assessments'));
        foreach (['rows', 'file_chunks', 'assessments'] as $table) {
            $this->assertSame([], $privileges(self::APP, $table), "famboook_app on {$table}");
        }
        $this->assertSame(['assessments' => 'u', 'file_chunks' => 'u', 'rows' => 'u'], collect(DB::select("SELECT relname, relpersistence FROM pg_class
            WHERE relnamespace = 'identity_ref_staging'::regnamespace AND relkind = 'r' ORDER BY relname"))->mapWithKeys(fn ($r) => [$r->relname => $r->relpersistence])->all());

        // The purge needs nothing more than the writer has: it runs as a non-superuser writer login.
        $run = $this->publishedWithLeftovers()['run'];
        $writer = $this->writerConnection();
        $this->assertTrue($this->stager()->purge($writer, 'famboook_test')['complete']);
        $this->assertSame(['famboook_identity_writer', false], array_values((array) $writer->selectOne('SELECT current_user AS u, r.rolsuper AS su
            FROM pg_roles r WHERE r.rolname = current_user')));
        $this->assertSame(['rows' => 0, 'assessments' => 0, 'chunks' => 0], $this->stagingOf($run));
        // TRUNCATE stays refused for every role, the writer included.
        $this->assertSame('42501', $this->sqlStateOf(fn () => $this->connection(self::WRITER)->exec('TRUNCATE identity_ref_staging.rows CASCADE')));
    }

    /** The dry run writes nothing (it works in a read-only session, where a real purge fails) and names exactly the eligible runs. */
    public function test_the_dry_run_is_read_only_and_identifies_the_eligible_runs(): void
    {
        $succeeded = $this->publishedWithLeftovers()['run'];
        // An aborted run whose purge stopped after file 2 of the published run: both finished runs keep staging.
        $aborted = $this->stage($this->syntheticExport(SyntheticIdentityExport::generated(2, 9, 950_000_001)))['run_id'];
        $this->assertFalse($this->stager()->abort($this->writerConnection(), 'famboook_test', null, self::crashAfter(1))['staging_purge_complete']);
        $running = $this->stage($this->syntheticExport(SyntheticIdentityExport::generated(1, 5, 960_000_001)))['run_id'];
        $staging = fn () => [$this->stagingOf($succeeded), $this->stagingOf($aborted), $this->stagingOf($running)];
        $before = [$staging(), $this->publishedState()];

        $readOnly = $this->writerConnection();
        $readOnly->statement('SET default_transaction_read_only = on');
        $preview = $this->stager()->purgePreview($readOnly, 'famboook_test');

        $this->assertSame([
            ['run_id' => $succeeded, 'status' => 'SUCCEEDED', 'error_code' => null, 'files' => 2, 'chunks' => 12, 'rows' => 40],
            ['run_id' => $aborted, 'status' => 'FAILED', 'error_code' => 'ABORTED', 'files' => 2, 'chunks' => 6, 'rows' => 18],
        ], $preview['runs']);
        $this->assertSame(['files' => 4, 'rows' => 58, 'assessments' => 40, 'chunks' => 18, 'protected_rows' => 5], array_diff_key($preview, ['runs' => 0]));
        $this->assertNotContains($running, array_column($preview['runs'], 'run_id'), 'a RUNNING run is never eligible');
        $this->assertSame($before, [$staging(), $this->publishedState()]);

        // The same read-only session cannot purge: the setting is effective, so the dry run above really wrote nothing.
        $refused = $this->stager()->purge($readOnly, 'famboook_test');
        $this->assertSame([false, 0], [$refused['complete'], $refused['rows_purged']]);
        $this->assertSame($before, [$staging(), $this->publishedState()]);
    }

    /** Decision 2 — the --purge command: dry run is read-only; the summary shows deleted, remaining and completion. */
    public function test_the_purge_command_dry_runs_and_reports_deleted_and_remaining(): void
    {
        $run = $this->publishedWithLeftovers()['run'];
        $before = $this->publishedState();
        config(['database.connections.identity_ref_import' => $this->writerConnection()->getConfig()]);

        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--dry-run' => true, '--target' => 'famboook_test']));
        $this->assertStringContainsString('[INVALID_USAGE]', Artisan::output());
        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--purge' => true, '--purge-batch' => 0, '--target' => 'famboook_test']));
        $this->assertStringContainsString('[INVALID_USAGE]', Artisan::output());

        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['--purge' => true, '--dry-run' => true, '--target' => 'famboook_test']));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/Rows\s*\|\s*80\s*\|/', $output);
        $this->assertMatchesRegularExpression('/Assessments\s*\|\s*80\s*\|/', $output);
        $this->assertStringContainsString('Dry run; nothing was deleted.', $output);
        $this->assertMatchesRegularExpression('/'.preg_quote($run, '/').'\s*\|\s*SUCCEEDED\s*\|\s*\|\s*3\s*\|\s*18\s*\|\s*80\s*\|/', $output);
        $this->assertSame(['rows' => 80, 'assessments' => 80, 'chunks' => 18], $this->stagingOf($run));

        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['--purge' => true, '--purge-batch' => 25, '--target' => 'famboook_test']));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/Rows\s*\|\s*80\s*\|\s*0\s*\|/', $output);
        $this->assertMatchesRegularExpression('/Chunks\s*\|\s*18\s*\|\s*0\s*\|/', $output);
        $this->assertStringContainsString('Staging purge complete.', $output);
        $this->assertStringNotContainsString('900000', $output);
        $this->assertSame(['rows' => 0, 'assessments' => 0, 'chunks' => 0], $this->stagingOf($run));
        $this->assertSame($before, $this->publishedState());

        // Production: the shared approval gate applies; a dry run needs no confirmation.
        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--purge' => true, '--dry-run' => true, '--target' => 'famboook_test']));
        $this->assertStringContainsString('[NOT_APPROVED]', Artisan::output());
    }
}
