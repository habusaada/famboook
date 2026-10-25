<?php

namespace Tests\Feature\IdentityRef;

use App\Actions\StageIdentityImportAction;
use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityRefErrorRedactor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\RecordingLogger;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P2 — concurrency, roles, isolation and redaction of the
 * staging loader (P2 test list items 18, 20–24). Real LOGIN sessions of
 * throwaway roles; SYNTHETIC data only.
 */
class IdentityRefStagingSecurityPostgresTest extends TestCase
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

    /** 18 — a second staging process is refused; so is a different manifest while a run is RUNNING. */
    public function test_concurrent_imports_are_refused(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 4));
        $holder = $this->writerConnection();
        $holder->selectOne('SELECT pg_advisory_lock(?)', [StageIdentityImportAction::LOCK_KEY]);
        $started = microtime(true);
        $e = $this->refused(fn () => $this->stage($export));
        $this->assertSame([IdentityImportException::CONCURRENT_IMPORT, null], [$e->reason, $e->runId]);
        $this->assertLessThan(5.0, microtime(true) - $started, 'refuses at once, never waits');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n);
        $holder->selectOne('SELECT pg_advisory_unlock(?)', [StageIdentityImportAction::LOCK_KEY]);

        $first = $this->stage($export);
        $other = $this->syntheticExport(SyntheticIdentityExport::generated(1, 4, 900_100_001));
        $e = $this->refused(fn () => $this->stage($other));
        $this->assertSame([IdentityImportException::RUN_CONFLICT, $first['run_id']], [$e->reason, $e->runId]);
        $this->assertSame(4, $this->stagedCount(), 'the RUNNING run is untouched');
    }

    /** 20 — the application role can neither run the loader nor write to staging. */
    public function test_the_application_role_cannot_stage_or_write(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 4));
        $app = $this->loginConnection(self::APP);
        $e = $this->refused(fn () => $this->stage($export, [], null, null, $app));
        $this->assertSame(IdentityImportException::WRONG_ROLE, $e->reason);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n);

        $app->unprepared('RESET ROLE');
        $app->unprepared('SET ROLE '.self::APP);
        foreach ([
            'SELECT count(*) FROM identity_ref_staging.rows',
            'SELECT count(*) FROM identity_ref_staging.file_chunks',
            "INSERT INTO identity_ref_staging.file_chunks (file_id, chunk_no, first_record, last_record, row_count, byte_start, byte_end, chunk_sha256)
                VALUES (1, 1, 1, 1, 1, 0, 1, decode(repeat('00', 32), 'hex'))",
            'DELETE FROM identity_ref_staging.rows',
            'TRUNCATE identity_ref_staging.rows',
            'CREATE TABLE identity_ref_staging.intruder (id int)',
        ] as $sql) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $app->unprepared($sql)), $sql);
        }
        foreach ([self::APP, self::READER] as $role) {
            $this->assertFalse((bool) DB::selectOne("SELECT has_schema_privilege(?, 'identity_ref_staging', 'USAGE') AS p", [$role])->p, $role);
        }
    }

    /** 21 — the writer gets exactly SELECT/INSERT/DELETE on staging, and the database enforces the loader's rules. */
    public function test_writer_least_privilege_and_staging_integrity_rules(): void
    {
        foreach (['file_chunks', 'rows'] as $table) {
            $privileges = collect(['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'])
                ->filter(fn ($p) => DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS p', [self::WRITER, 'identity_ref_staging.'.$table, $p])->p)
                ->values()->all();
            $this->assertSame(['SELECT', 'INSERT', 'DELETE'], $privileges, $table);
        }
        $this->assertFalse((bool) DB::selectOne("SELECT has_schema_privilege(?, 'identity_ref_staging', 'CREATE') AS p", [self::WRITER])->p);
        $this->assertSame(['famboook_identity_owner'], array_values(array_unique(array_column(DB::select("SELECT pg_get_userbyid(c.relowner) AS o
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'identity_ref_staging'"), 'o'))));
        foreach (DB::select("SELECT p.proname, p.proconfig::text AS cfg, p.prosecdef FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'identity_ref_staging'") as $f) {
            $this->assertStringContainsString('search_path=pg_catalog, pg_temp', $f->cfg, $f->proname);
            $this->assertFalse((bool) $f->prosecdef, $f->proname.' runs with the caller\'s privileges');
        }

        $export = $this->syntheticExport(SyntheticIdentityExport::generated(1, 6));
        $commits = new \ArrayObject(['n' => 0]);
        $this->refused(fn () => $this->stage($export, [], null, function (string $event) use ($commits) {
            if ($event === 'chunk.committed' && ++$commits['n'] === 1) {
                throw new \RuntimeException('stop after the first chunk');
            }
        }));
        $fileId = (int) DB::selectOne('SELECT id FROM identity_ref.import_files')->id;
        $payload = json_encode(SyntheticIdentityExport::row('900000099'), JSON_UNESCAPED_UNICODE);
        $writer = $this->writerConnection();
        $writer->unprepared('SET ROLE '.self::WRITER);

        $denied = [
            'UPDATE identity_ref_staging.rows SET line_no = 99' => '42501',
            'TRUNCATE identity_ref_staging.rows' => '42501',
            'CREATE TABLE identity_ref_staging.intruder (id int)' => '42501',
            'ALTER TABLE identity_ref_staging.rows DISABLE TRIGGER ALL' => '42501',
            // A row into a chunk committed by ANOTHER transaction (record 2 was just deleted, so no key collision).
            "DELETE FROM identity_ref_staging.rows WHERE file_id = {$fileId} AND record_no = 2" => null,
            "INSERT INTO identity_ref_staging.rows (file_id, chunk_no, record_no, line_no, source_payload) VALUES ({$fileId}, 1, 2, 3, '{$payload}'::jsonb)" => '23514',
            // A non-contiguous checkpoint.
            "INSERT INTO identity_ref_staging.file_chunks (file_id, chunk_no, first_record, last_record, row_count, byte_start, byte_end, chunk_sha256)
                VALUES ({$fileId}, 3, 10, 10, 1, 9000, 9001, decode(repeat('00', 32), 'hex'))" => '23514',
            // A checkpoint whose rows never arrive (deferred check at COMMIT).
            "INSERT INTO identity_ref_staging.file_chunks (file_id, chunk_no, first_record, last_record, row_count, byte_start, byte_end, chunk_sha256)
                SELECT {$fileId}, 2, 4, 6, 3, byte_end, byte_end + 10, decode(repeat('00', 32), 'hex') FROM identity_ref_staging.file_chunks WHERE file_id = {$fileId} AND chunk_no = 1" => '23514',
        ];
        foreach ($denied as $sql => $state) {
            $this->assertSame($state, $this->sqlStateOf(fn () => $writer->unprepared($sql)), $sql);
        }

        // The resume notices the deleted row, reloads the file; completed staging of a RUNNING run cannot then be deleted.
        $this->assertSame(1, $this->stage($export)['files_reset']);
        $this->assertSame('42501', $this->sqlStateOf(fn () => $writer->unprepared('DELETE FROM identity_ref_staging.rows')));
        $this->assertSame(6, $this->stagedCount());
    }

    /** 22 — staging never publishes: no release, no pointer move, no reference record. */
    public function test_staging_never_changes_the_active_release_or_reference_data(): void
    {
        $release = $this->publishedRelease(['900000001' => [], '900000002' => []]);
        $before = $this->referenceState();

        $export = $this->syntheticExport(SyntheticIdentityExport::generated(2, 5));
        $this->stage($export);

        $this->assertSame($before, $this->referenceState());
        $this->assertSame($release, (int) DB::selectOne('SELECT release_id FROM identity_ref.active_release')->release_id);
        $this->assertSame(10, $this->stagedCount());

        // The staging code has no publication path at all. (Since P3, --abort may ABANDON the run's
        // BUILDING release — which makes it permanently unpublishable — so only that file may name
        // dataset_releases / abandon_release.)
        $publication = 'publish_release|carry_forward_missing|active_release|record_versions|identity_ref\.records\b|release_presence|begin_preparation|verify_release';
        foreach (['app/Actions/StageIdentityImportAction.php' => $publication,
            'app/Support/IdentityRef/IdentityStagingLoader.php' => $publication.'|abandon_release|dataset_releases'] as $file => $pattern) {
            $this->assertDoesNotMatchRegularExpression('/'.$pattern.'/', (string) file_get_contents(base_path($file)), $file);
        }
    }

    /** @return array<string, int> */
    private function referenceState(): array
    {
        return collect(['records', 'record_versions', 'dataset_releases', 'release_presence', 'active_release'])
            ->mapWithKeys(fn ($t) => [$t => (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref.{$t}")->n])
            ->put('published', (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref.dataset_releases WHERE status = 'PUBLISHED'")->n)
            ->all();
    }

    /** 23 — the operational registry and RBAC are untouched. */
    public function test_operational_registry_and_rbac_are_untouched(): void
    {
        $snapshot = fn () => [
            'objects' => DB::select("SELECT n.nspname, c.relname, c.relkind, c.relacl::text AS acl FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' ORDER BY 2"),
            'rows' => collect(['persons', 'families', 'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'])
                ->filter(fn ($t) => DB::selectOne('SELECT to_regclass(?) AS r', ['public.'.$t])->r !== null)
                ->mapWithKeys(fn ($t) => [$t => (int) DB::selectOne("SELECT count(*) AS n FROM public.{$t}")->n])->all(),
            'app_role' => DB::select("SELECT rolsuper, rolcreaterole, rolbypassrls FROM pg_roles WHERE rolname = 'famboook_app'"),
        ];
        $before = $snapshot();
        $this->assertNotEmpty($before['objects']);

        $this->stage($this->syntheticExport(SyntheticIdentityExport::generated(2, 5)));
        app(StageIdentityImportAction::class)->abort($this->writerConnection(), 'famboook_test');

        $this->assertEquals($before, $snapshot());
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
            JOIN pg_class t ON t.oid = c.confrelid JOIN pg_namespace tn ON tn.oid = t.relnamespace
            WHERE n.nspname = 'identity_ref_staging' AND c.contype = 'f' AND tn.nspname NOT IN ('identity_ref', 'identity_ref_staging')")->n);
        foreach (['app/Actions/StageIdentityImportAction.php', 'app/Support/IdentityRef/IdentityStagingLoader.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(persons|families|users|roles|permissions|model_has_\w+)\b/',
                preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents(base_path($file))), $file);
        }
    }

    /** 24 — logs, exceptions, console output and issues never carry a value from the export. */
    public function test_logs_errors_and_output_are_redacted(): void
    {
        $secretName = 'اسم-سري-تجريبي';
        $export = $this->syntheticExport(SyntheticIdentityExport::withFiles(['t03maindata_part_00001.csv' => [
            SyntheticIdentityExport::row('900000777', ['name1' => $secretName]),
            SyntheticIdentityExport::row('900000777', ['name1' => $secretName.'2']),
            SyntheticIdentityExport::row('900000778'),
            SyntheticIdentityExport::row('900000779'),
        ]]));
        $forbidden = ['900000777', '900000778', '900000779', $secretName, 'Failing row', 'Key (', 'insert into', 'COPY'];

        // A database error raised inside a chunk, carrying values in its SQL, bindings and DETAIL.
        $logger = new RecordingLogger;
        $writer = $this->writerConnection();
        $e = $this->refused(fn () => $this->stage($export, [], $logger, function (string $event) use ($writer) {
            if ($event === 'chunk.before_commit') {
                $writer->insert('INSERT INTO identity_ref_staging.rows (file_id, chunk_no, record_no, line_no, source_payload) SELECT file_id, chunk_no, record_no, line_no, source_payload FROM identity_ref_staging.rows LIMIT 1');
            }
        }, $writer));
        $this->assertSame(IdentityImportException::STAGING_INTERRUPTED, $e->reason);
        $this->assertNull($e->getPrevious(), 'the original database exception is not chained');
        $this->assertStringContainsString('sqlstate=23505', $e->getMessage());
        $this->assertContains('identity_ref.stage.interrupted', $logger->messages());
        foreach ($forbidden as $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, $e->getMessage(), $value);
            $this->assertStringNotContainsStringIgnoringCase($value, $logger->text(), $value);
        }

        // COPY's own error text carries the record (CONTEXT: COPY … line …); the redactor drops it.
        $pdo = $this->writerConnection()->getPdo();
        $pdo->exec('SET ROLE '.self::WRITER);
        try {
            $pdo->pgsqlCopyFromArray('identity_ref_staging.rows', ["1\t1\t1\t2\t{\"mainid\": \"900000777\", \"name1\": \"{$secretName}\"}"], "\t", '\\\\N',
                'file_id,chunk_no,record_no,line_no,source_payload');
            $this->fail('expected the COPY to fail');
        } catch (\PDOException $copyError) {
            $this->assertStringContainsString('900000777', $copyError->getMessage(), 'the leak vector is real');
            $redacted = IdentityRefErrorRedactor::redact($copyError);
            $this->assertStringStartsWith('identity_ref database error sqlstate=', $redacted);
            foreach ($forbidden as $value) {
                $this->assertStringNotContainsStringIgnoringCase($value, $redacted, $value);
            }
        }

        // A full, successful run: logs, report and console output carry counts and codes only.
        $logger = new RecordingLogger;
        $report = $this->stage($export, [], $logger);
        $this->assertSame(['MAINID_DUPLICATE' => 2], $report['issues']);
        foreach ($forbidden as $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, $logger->text(), $value);
            $this->assertStringNotContainsStringIgnoringCase($value, (string) json_encode($report, JSON_UNESCAPED_UNICODE), $value);
        }
        $issueText = (string) json_encode(DB::select('SELECT * FROM identity_ref.import_issues'), JSON_UNESCAPED_UNICODE);
        foreach ($forbidden as $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, $issueText, $value);
        }
    }

    /** The operator command: explicit target, counts-only output, refused in production. */
    public function test_the_command_requires_an_explicit_target_and_prints_counts_only(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(2, 3));
        $writer = $this->writerConnection();
        config(['database.connections.identity_ref_import' => $writer->getConfig()]);

        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['directory' => $export->dir, '--target' => 'famboook_production']));
        $this->assertStringContainsString('[UNSAFE_TARGET]', Artisan::output());
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n);

        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['directory' => $export->dir, '--target' => 'famboook_test',
            '--expected-files' => 2, '--expected-rows' => 6, '--chunk' => 2]));
        $output = Artisan::output();
        $this->assertStringContainsString('Staged; awaiting publication (P3). Nothing was published.', $output);
        $this->assertMatchesRegularExpression('/Rows staged \(run total\)\s*\|\s*6/', $output);
        $this->assertStringNotContainsString('900000001', $output);
        $this->assertStringNotContainsString('اسم-تجريبي', $output);

        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['--abort' => true, '--target' => 'famboook_test']));
        $this->assertSame(0, $this->stagedCount());

        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['directory' => $export->dir, '--target' => 'famboook_test']));
        $this->assertStringContainsString('[NOT_APPROVED]', Artisan::output());
    }
}
