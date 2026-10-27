<?php

namespace Tests\Feature\IdentityRef;

use App\Actions\StageIdentityImportAction;
use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityManifest;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P2 — staging loader: tracking, preservation, key issues,
 * streaming, interruption, resume and rollback (P2 test list items 1, 2,
 * 9–17, 19). PostgreSQL only; the loader runs as a real writer login;
 * SYNTHETIC data only.
 */
class IdentityRefStagingPostgresTest extends TestCase
{
    use IdentityRefDatabase;
    use IdentityRefStaging;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityRef();
    }

    protected function tearDown(): void
    {
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    /** 1 — a valid export is staged; run and file tracking are consistent. */
    public function test_a_valid_export_is_staged_with_consistent_tracking(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(3, 7));
        $report = $this->stage($export, ['expected_files' => 3, 'expected_rows' => 21]);

        $this->assertFalse($report['resumed']);
        $this->assertSame('STAGED_AWAITING_PUBLICATION', $report['status']);
        $this->assertSame([3, 21, 21, 9, 0], [$report['files_staged_now'], $report['rows_loaded_now'], $report['rows_staged'],
            $report['chunks_loaded'], $report['files_reset']]);
        $this->assertSame([], $report['issues']);

        $run = $this->importRun($report['run_id']);
        $this->assertSame(['RUNNING', 21, 0], [$run->status, (int) $run->rows_loaded, (int) $run->issues], 'the run awaits P3');
        $files = DB::select('SELECT file_name, status, loaded_rows, expected_rows, actual_sha256 = expected_sha256 AS sha_ok
            FROM identity_ref.import_files WHERE run_id = ? ORDER BY file_name', [$report['run_id']]);
        $this->assertSame(['STAGED'], array_values(array_unique(array_column($files, 'status'))));
        foreach ($files as $f) {
            $this->assertSame([7, 7, true], [(int) $f->loaded_rows, (int) $f->expected_rows, (bool) $f->sha_ok]);
        }
        $this->assertSame(21, $this->stagedCount());
        $this->assertSame(hash_file('sha256', $export->path(IdentityManifest::FILE_NAME)),
            DB::selectOne('SELECT manifest_sha256 FROM identity_ref.import_runs WHERE id = ?', [$report['run_id']])->manifest_sha256);

        // Running the command again on a fully staged run does nothing more.
        $again = $this->stage($export);
        $this->assertSame([true, 0, 0, 21], [$again['resumed'], $again['files_staged_now'], $again['rows_loaded_now'], $again['rows_staged']]);
    }

    /** 2 (and 3–8 at run level) — invalid sources are REJECTED before any staging row exists; manifest refusals record nothing. */
    public function test_invalid_sources_are_rejected_and_recorded_without_staging(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(2, 4));
        $export->manifest['files'][1]['sha256'] = str_repeat('0', 64);
        $export->writeManifest();

        $e = $this->refused(fn () => $this->stage($export));
        $this->assertSame(IdentityImportException::SOURCE_INVALID, $e->reason);
        $this->assertSame(['CHECKSUM_MISMATCH t03maindata_part_00002.csv'], $e->problems);
        $run = $this->importRun($e->runId);
        $this->assertSame(['REJECTED', 'SOURCE_INVALID', 1], [$run->status, $run->error_code, (int) $run->issues]);
        $this->assertSame(['FAILED' => 1, 'PENDING' => 1], collect(DB::select('SELECT status FROM identity_ref.import_files WHERE run_id = ?', [$e->runId]))
            ->countBy('status')->sortKeys()->all(), 'the valid file was never verified or staged');
        $this->assertSame(0, $this->stagedCount());

        // A manifest-level refusal records no run at all.
        $runs = (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n;
        file_put_contents($export->path('unexpected.csv'), 'x');
        $e = $this->refused(fn () => $this->stage($export));
        $this->assertSame([IdentityImportException::MANIFEST_INVALID, ['UNEXPECTED_ENTRIES 1'], null], [$e->reason, $e->problems, $e->runId]);
        $this->assertSame($runs, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n);
    }

    /** 9, 11–14 — staged payloads equal the source values byte for byte. */
    public function test_staged_payloads_preserve_every_source_value(): void
    {
        $special = SyntheticIdentityExport::row('012345678', [
            'street' => 'شارع, رقم "5"',
            'fullname' => "سطر أول\nسطر ثان\r\nسطر ثالث",
            'grandfath' => '𝔘𝔫𝔦𝔠𝔬𝔡𝔢 😀 مُحَمَّد',
            'city' => '',
            'motherid' => null,
            'fatherid' => '000000042',
            'householdrel' => 'X9',
            'job' => 'back\\slash	tab',
        ]);
        $export = $this->syntheticExport(SyntheticIdentityExport::withFiles(['t03maindata_part_00001.csv' => [
            $special, SyntheticIdentityExport::row('7'), SyntheticIdentityExport::row('123'),
        ]]));
        $this->stage($export, ['chunk_size' => 2]);

        $stored = $this->stagedPayload('012345678');
        ksort($stored);
        ksort($special);
        $this->assertSame($special, $stored);
        $this->assertSame('null', DB::selectOne("SELECT jsonb_typeof(source_payload->'motherid') AS t FROM identity_ref_staging.rows WHERE mainid = '012345678'")->t);
        $this->assertSame('string', DB::selectOne("SELECT jsonb_typeof(source_payload->'city') AS t FROM identity_ref_staging.rows WHERE mainid = '012345678'")->t);
        $this->assertSame(['012345678', '123', '7'], array_column(DB::select('SELECT mainid FROM identity_ref_staging.rows ORDER BY mainid'), 'mainid'));
        $this->assertSame([[1, 2], [2, 5], [3, 6]], array_map(fn ($r) => [(int) $r->record_no, (int) $r->line_no],
            DB::select('SELECT record_no, line_no FROM identity_ref_staging.rows ORDER BY record_no')));
    }

    /** 10, 12 — duplicate, missing and non-standard keys are staged and reported as value-free issues. */
    public function test_key_findings_are_recorded_as_issues_without_excluding_rows(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::withFiles([
            'a.csv' => [SyntheticIdentityExport::row('900000001'), SyntheticIdentityExport::row('900000002'), SyntheticIdentityExport::row('7')],
            'b.csv' => [SyntheticIdentityExport::row('900000001', ['name1' => 'آخر']), SyntheticIdentityExport::row('', ['mainid' => null]),
                SyntheticIdentityExport::row(' 900000003')],
        ]));
        $report = $this->stage($export);

        $this->assertSame(6, $report['rows_staged'], 'nothing is excluded at staging');
        $this->assertSame(['MAINID_DUPLICATE' => 2, 'MAINID_MISSING' => 1, 'MAINID_NOT_9_DIGITS' => 2, 'MAINID_SURROUNDING_SPACE' => 1], $report['issues']);
        $issues = DB::select("SELECT file_name, line_number, column_name, key_fingerprint FROM identity_ref.import_issues
            WHERE run_id = ? AND code = 'MAINID_DUPLICATE' ORDER BY file_name", [$report['run_id']]);
        $this->assertSame([['a.csv', 2], ['b.csv', 2]], array_map(fn ($i) => [$i->file_name, (int) $i->line_number], $issues));
        $this->assertSame(['mainid'], array_values(array_unique(array_column($issues, 'column_name'))));
        $this->assertNull($issues[0]->key_fingerprint, 'no key-derived value is stored');
        $this->assertSame(6, (int) $this->importRun($report['run_id'])->issues);
    }

    /** 15 — large input streams in bounded memory. */
    public function test_large_synthetic_input_is_streamed_in_bounded_memory(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(4, 25_000));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = microtime(true);

        $report = $this->stage($export, ['chunk_size' => 5000]);

        $peakGrowth = memory_get_peak_usage() - $before;
        $this->assertSame(100_000, $report['rows_staged']);
        $this->assertSame(20, $report['chunks_loaded']);
        $this->assertSame(100_000, $this->stagedCount());
        $this->assertLessThan(64 * 1024 * 1024, $peakGrowth, 'peak memory growth stays bounded by the chunk, not the input');
        fwrite(STDERR, sprintf("\n[P2 perf] 100,000 rows: %.1f s, peak growth %.1f MB\n", microtime(true) - $started, $peakGrowth / 1048576));
    }

    /** 16, 17 — an interrupted load resumes without skipping or duplicating a row. */
    public function test_interrupted_load_resumes_without_duplication(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(2, 10));
        $commits = 0;
        $e = $this->refused(fn () => $this->stage($export, [], null, function (string $event) use (&$commits) {
            if ($event === 'chunk.committed' && ++$commits === 3) {
                throw new RuntimeException('simulated crash after the third chunk');
            }
        }));
        $this->assertSame(IdentityImportException::STAGING_INTERRUPTED, $e->reason);
        $this->assertSame('RUNNING', $this->importRun($e->runId)->status, 'the run stays resumable');
        $this->assertSame(9, $this->stagedCount(), 'exactly the three committed chunks');

        $report = $this->stage($export);
        $this->assertTrue($report['resumed']);
        $this->assertSame($e->runId, $report['run_id']);
        $this->assertSame([3, 11, 20, 0], [$report['chunks_verified'], $report['rows_loaded_now'], $report['rows_staged'], $report['files_reset']]);
        $this->assertSame(20, $this->stagedCount());
        $this->assertSame(20, (int) DB::selectOne('SELECT count(DISTINCT mainid) AS n FROM identity_ref_staging.rows')->n);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM (SELECT file_id, record_no FROM identity_ref_staging.rows
            GROUP BY 1, 2 HAVING count(*) > 1) d')->n);
    }

    /** 16 — the session is killed in the middle of a chunk; nothing partial survives and the run resumes. */
    public function test_a_killed_session_leaves_no_partial_chunk(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 10));
        $writer = $this->writerConnection();
        $killed = new \ArrayObject(['pid' => null, 'chunks' => 0]);   // arrow functions capture by value
        $e = $this->refused(fn () => $this->stage($export, [], null, function (string $event) use ($killed, $writer) {
            if ($event === 'chunk.before_commit' && ++$killed['chunks'] === 2) {
                $killed['pid'] = $writer->selectOne('SELECT pg_backend_pid() AS pid')->pid;
                DB::select('SELECT pg_terminate_backend(?)', [$killed['pid']]);
            }
        }, $writer));
        $this->assertSame(IdentityImportException::STAGING_INTERRUPTED, $e->reason);
        $this->assertNotNull($killed['pid'], 'the loader session was terminated mid-chunk');
        $this->assertStringContainsString('sqlstate=', $e->getMessage());
        $this->assertSame(3, $this->stagedCount(), 'only the first chunk committed');
        $this->assertSame(3, (int) DB::selectOne('SELECT loaded_rows FROM identity_ref.import_files')->loaded_rows);

        $report = $this->stage($export);
        $this->assertSame([true, 1, 10], [$report['resumed'], $report['chunks_verified'], $report['rows_staged']]);
    }

    /** 17 — a checkpoint that disagrees with the staged rows is never trusted: the file is reloaded. */
    public function test_an_inconsistent_checkpoint_resets_and_reloads_the_file(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 9));
        $commits = 0;
        $this->refused(fn () => $this->stage($export, [], null, function (string $event) use (&$commits) {
            if ($event === 'chunk.committed' && ++$commits === 2) {
                throw new RuntimeException('simulated crash');
            }
        }));
        // Simulated corruption of staged data (as the administrator).
        DB::delete('DELETE FROM identity_ref_staging.rows WHERE record_no = 2');

        $report = $this->stage($export);
        $this->assertSame([1, 0, 9, 9], [$report['files_reset'], $report['chunks_verified'], $report['rows_loaded_now'], $report['rows_staged']]);
        $this->assertSame(9, $this->stagedCount());
    }

    /** 17 — a file that changed since the interrupted attempt is caught by revalidation; the run is rejected and purged. */
    public function test_a_source_changed_before_resume_rejects_the_run(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 9));
        $commits = 0;
        $e = $this->refused(fn () => $this->stage($export, [], null, function (string $event) use (&$commits) {
            if ($event === 'chunk.committed' && ++$commits === 1) {
                throw new RuntimeException('simulated crash');
            }
        }));
        $bytes = (string) file_get_contents($export->path('t03maindata_part_00001.csv'));
        $export->replaceBytes('t03maindata_part_00001.csv', str_replace('900000005', '900000099', $bytes), false);

        $rejected = $this->refused(fn () => $this->stage($export));
        $this->assertSame([IdentityImportException::SOURCE_INVALID, $e->runId], [$rejected->reason, $rejected->runId]);
        $this->assertSame(['CHECKSUM_MISMATCH t03maindata_part_00001.csv'], $rejected->problems);
        $this->assertSame('REJECTED', $this->importRun($e->runId)->status);
        $this->assertSame(0, $this->stagedCount(), 'staging of the rejected run is purged');
    }

    /** 19 — a failure inside a chunk rolls the whole chunk back, counters included. */
    public function test_a_failed_chunk_is_rolled_back_completely(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 10));
        $chunks = 0;
        $e = $this->refused(fn () => $this->stage($export, [], null, function (string $event) use (&$chunks) {
            if ($event === 'chunk.before_commit' && ++$chunks === 2) {
                throw new RuntimeException('simulated failure before COMMIT');
            }
        }));
        $this->assertSame(IdentityImportException::STAGING_INTERRUPTED, $e->reason);
        $this->assertSame(3, $this->stagedCount());
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.file_chunks')->n);
        $this->assertSame(3, (int) DB::selectOne('SELECT loaded_rows FROM identity_ref.import_files')->loaded_rows);
        $this->assertSame(3, (int) $this->importRun($e->runId)->rows_loaded);
    }

    public function test_abort_closes_the_run_and_purges_its_staging(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 6));
        $report = $this->stage($export);
        $this->assertSame(6, $this->stagedCount());

        $result = app(StageIdentityImportAction::class)->abort($this->writerConnection(), 'famboook_test');
        $this->assertSame([$report['run_id'], 6, true], [$result['aborted_run'], $result['rows_purged'], $result['staging_purge_complete']]);
        $this->assertSame(['FAILED', 'ABORTED'], [$this->importRun($report['run_id'])->status, $this->importRun($report['run_id'])->error_code]);
        $this->assertSame(0, $this->stagedCount());
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.file_chunks')->n);

        // A new run can start afterwards.
        $this->assertSame(6, $this->stage($export)['rows_staged']);
    }

    /** 11 — without the P3 assessments table (P2 only), an interrupted abort purge resumes in batches. */
    public function test_the_batched_purge_works_without_the_assessments_table(): void
    {
        $this->assertNull(DB::selectOne("SELECT to_regclass('identity_ref_staging.assessments') AS t")->t);
        $report = $this->stage($this->syntheticExport(SyntheticIdentityExport::generated(2, 9)), ['chunk_size' => 4]);
        $crashed = 0;
        $result = app(StageIdentityImportAction::class)->abort($this->writerConnection(), 'famboook_test', null, function (string $event, array $context) use (&$crashed) {
            if ($context['rows'] > 0 && ++$crashed === 2) {
                throw new RuntimeException('simulated crash after a committed purge batch');
            }
        }, 4);
        $this->assertSame([$report['run_id'], 8, false], [$result['aborted_run'], $result['rows_purged'], $result['staging_purge_complete']]);
        $this->assertSame(['FAILED', 'ABORTED'], [$this->importRun($report['run_id'])->status, $this->importRun($report['run_id'])->error_code]);
        $this->assertSame(10, $this->stagedCount());

        $purge = app(StageIdentityImportAction::class)->purge($this->writerConnection(), 'famboook_test', 4);
        $this->assertSame([true, 10, 0, 6], [$purge['complete'], $purge['rows_purged'], $purge['assessments_purged'], $purge['chunks_purged']]);
        $this->assertSame(['files' => 0, 'rows' => 0, 'assessments' => 0, 'chunks' => 0, 'protected_rows' => 0], $purge['remaining']);
        $this->assertSame(0, $this->stagedCount());
    }
}
