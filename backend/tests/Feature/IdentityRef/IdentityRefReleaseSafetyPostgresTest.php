<?php

namespace Tests\Feature\IdentityRef;

use App\Actions\StageIdentityImportAction;
use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityImportPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\RecordingLogger;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P3 — fail-closed validation, the publication gate,
 * concurrency, failure handling, isolation and redaction (P3 test list
 * 12–19, 22–26, 28–30, 35, 36). Real LOGIN sessions; SYNTHETIC data only.
 */
class IdentityRefReleaseSafetyPostgresTest extends TestCase
{
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

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

    /** 12 — a source mapped to an unsupported table is a fatal, release-level failure. */
    public function test_an_invalid_source_mapping_is_fatal(): void
    {
        $export = $this->export(...$this->rows(['900000001' => []]));
        $export->manifest['table'] = 'other_table';
        $export->writeManifest();
        $run = $this->stagedRun($export);

        $e = $this->refused(fn () => $this->prepareRun($run));
        $this->assertSame([IdentityImportException::VALIDATION_FAILED, ['SOURCE_TABLE_UNSUPPORTED']], [$e->reason, $e->problems]);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.dataset_releases')->n);
    }

    /** 13, 15 — incomplete staging is fatal; nothing is written; the database refuses too. */
    public function test_incomplete_staging_is_a_fatal_validation_failure(): void
    {
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(2, 4));
        $stops = new \ArrayObject(['n' => 0]);
        $staging = $this->refused(fn () => $this->stage($export, [], null, function (string $event) use ($stops) {
            if ($event === 'chunk.committed' && ++$stops['n'] === 2) {
                throw new RuntimeException('stop staging half-way');
            }
        }));

        $e = $this->refused(fn () => $this->prepareRun($staging->runId));
        $this->assertSame(IdentityImportException::VALIDATION_FAILED, $e->reason);
        $this->assertSame(['STAGING_INCOMPLETE', 'ROW_COUNT_INCONSISTENT'], $e->problems);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.dataset_releases')->n);

        // Bypassing the application: the definer function re-checks.
        $state = $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::select(
            "SELECT identity_ref.begin_preparation(?::uuid, 'IDREF-POLICY-0', repeat('a', 64), 'IDREF-CMP-1', 'UNRESOLVED', NULL, NULL)", [$staging->runId])));
        $this->assertSame('23514', $state);
    }

    /** 14 — a run whose source failed checksum verification can never be prepared. */
    public function test_a_run_with_failed_checksum_verification_cannot_be_prepared(): void
    {
        $export = $this->export(...$this->rows(['900000001' => []]));
        $export->manifest['files'][0]['sha256'] = str_repeat('0', 64);
        $export->writeManifest();
        $rejected = $this->refused(fn () => $this->stage($export));
        $this->assertSame(IdentityImportException::SOURCE_INVALID, $rejected->reason);

        $e = $this->refused(fn () => $this->prepareRun($rejected->runId));
        $this->assertSame(IdentityImportException::VALIDATION_FAILED, $e->reason);
        $this->assertContains('RUN_NOT_RUNNING', $e->problems);
        $this->assertContains('CHECKSUM_NOT_VERIFIED', $e->problems);
    }

    /** 8, 9, 16, 17 — quarantine with the unresolved policy, or above an approved threshold, fails closed. */
    public function test_quarantine_fails_closed_without_an_approved_threshold(): void
    {
        $rows = $this->rows(['900000001' => [], '900000002' => [], '900000003' => ['street' => "a\x07b"]]);
        $rows[] = SyntheticIdentityExport::row('900000001');
        $run = $this->stagedRun($this->export(...$rows));

        $validation = $this->releases()->assess($this->writerConnection(), $run, IdentityImportPolicy::approved('IDREF-POLICY-0'), 'famboook_test');
        $this->assertSame(['MAINID_DUPLICATE' => 2, 'VALUE_CONTROL_CHARACTER' => 1], $validation['conflicts']);
        $this->assertSame(['POLICY_QUARANTINE_UNRESOLVED'], $validation['blocked']);
        $this->assertFalse($validation['publishable']);

        $e = $this->refused(fn () => $this->prepareRun($run, IdentityImportPolicy::approved('IDREF-POLICY-0')));
        $this->assertSame([IdentityImportException::POLICY_BLOCKED, ['POLICY_QUARANTINE_UNRESOLVED']], [$e->reason, $e->problems]);
        $e = $this->refused(fn () => $this->prepareRun($run));   // default IDREF-POLICY-1: ZERO_UNRESOLVED_QUARANTINE
        $this->assertSame([IdentityImportException::POLICY_BLOCKED, ['QUARANTINE_NOT_ALLOWED']], [$e->reason, $e->problems]);
        $e = $this->refused(fn () => $this->prepareRun($run, IdentityImportPolicy::synthetic(maxRows: 2, maxRatio: '0.9')));
        $this->assertSame([IdentityImportException::POLICY_BLOCKED, ['QUARANTINE_THRESHOLD_EXCEEDED']], [$e->reason, $e->problems]);
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.dataset_releases')->n);

        // The database enforces the same gate when called directly.
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::select(
            "SELECT identity_ref.begin_preparation(?::uuid, 'IDREF-POLICY-0', repeat('a', 64), 'IDREF-CMP-1', 'UNRESOLVED', NULL, NULL)", [$run]))));
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.dataset_releases')->n);
    }

    /** 35 — an unsupported comparison-policy version is refused before anything is written, and by the database. */
    public function test_an_unsupported_comparison_policy_is_refused(): void
    {
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => []])));
        $e = $this->refused(fn () => $this->prepareRun($run, IdentityImportPolicy::synthetic(comparison: 'IDREF-CMP-9')));
        $this->assertSame([IdentityImportException::POLICY_NOT_APPROVED, ['COMPARISON_POLICY_UNSUPPORTED']], [$e->reason, $e->problems]);
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::select(
            "SELECT identity_ref.begin_preparation(?::uuid, 'IDREF-POLICY-0', repeat('a', 64), 'IDREF-CMP-9', 'UNRESOLVED', NULL, NULL)", [$run]))));
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.dataset_releases')->n);
        $this->assertSame(['IDREF-POLICY-0', 'IDREF-POLICY-1'], IdentityImportPolicy::approvedIds());
        $this->assertSame('IDREF-POLICY-1', IdentityImportPolicy::approved()->id, 'the approved P3.1 policy is the default');
    }

    /** 18 — an unprepared or unverified release cannot be published, not even through publish_release() directly. */
    public function test_an_unprepared_or_unverified_release_cannot_be_published(): void
    {
        $this->importAndPublish(['900000001' => []]);
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => [], '900000002' => []])));
        $prepared = $this->prepareRun($run);
        $this->assertSame('PREPARED', $prepared['preparation']);

        $e = $this->refused(fn () => $this->publishRelease($prepared['release_id']));
        $this->assertSame([IdentityImportException::NOT_VERIFIED, ['PREPARED']], [$e->reason, $e->problems]);
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::select(
            'SELECT identity_ref.publish_release(?)', [$prepared['release_id']]))), 'the gate fires inside publish_release');

        // A bare BUILDING release created directly by the writer has no preparation at all.
        $this->assertSame(['900000001:PRESENT'], $this->activeSet());
    }

    /** 19 — a release whose content was tampered with fails verification; an abandoned release can never be published. */
    public function test_an_invalid_release_cannot_be_verified_or_published(): void
    {
        $this->importAndPublish(['900000001' => [], '900000002' => []]);
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => ['city' => '16'], '900000002' => [], '900000003' => []])));
        $prepared = $this->prepareRun($run);

        // Simulated corruption by the administrator, bypassing the triggers: one presence row removed.
        DB::unprepared('SET session_replication_role = replica');
        DB::delete("DELETE FROM identity_ref.release_presence WHERE release_id = ? AND record_id = (SELECT id FROM identity_ref.records WHERE mainid = '900000003')",
            [$prepared['release_id']]);
        DB::unprepared('SET session_replication_role = origin');

        $e = $this->refused(fn () => $this->verifyRelease($prepared['release_id']));
        $this->assertSame([IdentityImportException::VERIFICATION_FAILED, ['VERIFY_PRESENCE_MISMATCH']], [$e->reason, $e->problems]);
        $this->assertSame('PREPARED', DB::selectOne('SELECT status FROM identity_ref.release_preparations WHERE release_id = ?', [$prepared['release_id']])->status);

        $aborted = app(StageIdentityImportAction::class)->abort($this->writerConnection(), 'famboook_test');
        $this->assertSame($prepared['release_id'], $aborted['abandoned_release']);
        $e = $this->refused(fn () => $this->publishRelease($prepared['release_id']));
        $this->assertSame(IdentityImportException::RELEASE_STATE_INVALID, $e->reason);
        $this->assertSame(['900000001:PRESENT', '900000002:PRESENT'], $this->activeSet());
    }

    /** 22 — concurrent publication attempts are refused at once; state stays consistent. */
    public function test_concurrent_publication_attempts_are_refused(): void
    {
        $this->importAndPublish(['900000001' => []]);
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => [], '900000002' => []])));
        $release = $this->prepareRun($run)['release_id'];
        $this->verifyRelease($release);

        $seen = new \ArrayObject;
        $report = $this->publishRelease($release, function () use ($release, $seen) {
            // While the first activation transaction is open: a second process and a direct call.
            $seen['process'] = $this->refused(fn () => $this->publishRelease($release))->reason;
            $direct = $this->connection(self::WRITER);
            try {
                $direct->query("SELECT identity_ref.publish_release({$release})");
            } catch (\PDOException $e) {
                $seen['direct'] = $e->errorInfo[0];
            }
        });
        $this->assertSame([IdentityImportException::CONCURRENT_IMPORT, '55P03'], [$seen['process'], $seen['direct']]);
        $this->assertSame('PUBLISHED', $report['outcome']);
        $this->assertSame($release, $this->activeReleaseId());
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref.dataset_releases WHERE status = 'PUBLISHED' AND id = ?", [$release])->n);
    }

    /** 23, 24, 25, 26 — failures before or during activation leave the active release untouched; readers never see partial state. */
    public function test_failures_before_and_during_activation_leave_the_active_release_unchanged(): void
    {
        $first = $this->importAndPublish(['900000001' => [], '900000002' => []]);
        $reader = $this->connection(self::OWNER);
        $readerSees = fn () => $reader->query('SELECT mainid FROM identity_ref.active_records ORDER BY mainid')->fetchAll(PDO::FETCH_COLUMN);
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => ['city' => '16'], '900000003' => [], '900000004' => []])));

        // 23 — interrupted before activation (during preparation): readers still see release 1.
        $stop = new \ArrayObject(['n' => 0]);
        $this->refused(fn () => $this->prepareRun($run, null, function (string $event) use ($stop) {
            if ($event === 'prepare.chunk_committed' && ++$stop['n'] === 1) {
                throw new RuntimeException('crash after the first committed chunk');
            }
        }));
        $this->assertSame(['900000001', '900000002'], $readerSees(), 'committed preparation chunks are invisible');
        $release = $this->prepareRun($run)['release_id'];
        $this->verifyRelease($release);

        // 24 — failure inside the activation transaction: rolled back completely.
        $during = new \ArrayObject;
        $e = $this->refused(fn () => $this->publishRelease($release, function () use ($during, $readerSees) {
            $during['reader'] = $readerSees();
            throw new RuntimeException('failure before COMMIT of the activation');
        }));
        $this->assertSame(IdentityImportException::PUBLICATION_FAILED, $e->reason);
        $this->assertSame(['900000001', '900000002'], $during['reader'], '26: the uncommitted switch is invisible');

        // 25 — nothing changed: pointer, release state, run state.
        $this->assertSame($first['release_id'], $this->activeReleaseId());
        $this->assertSame(['900000001', '900000002'], $readerSees());
        $this->assertSame(['BUILDING', 'VERIFIED', 'RUNNING'], array_values((array) DB::selectOne('SELECT d.status, p.status AS prep, r.status AS run
            FROM identity_ref.dataset_releases d JOIN identity_ref.release_preparations p ON p.release_id = d.id
            JOIN identity_ref.import_runs r ON r.id = d.run_id WHERE d.id = ?', [$release])));

        // Safe retry.
        $this->assertSame('PUBLISHED', $this->publishRelease($release)['outcome']);
        $this->assertSame(['900000001', '900000002', '900000003', '900000004'], $readerSees());
    }

    /** 28 — the writer cannot forge, bypass or alter preparation state or history. */
    public function test_writer_privilege_isolation(): void
    {
        $first = $this->importAndPublish(['900000001' => []]);
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => [], '900000002' => []])));
        $release = $this->prepareRun($run)['release_id'];
        $writer = $this->writerConnection();
        $writer->unprepared('SET ROLE '.self::WRITER);

        foreach ([
            "UPDATE identity_ref.release_preparations SET status = 'VERIFIED', verified_at = now() WHERE release_id = {$release}",
            "INSERT INTO identity_ref.release_preparations (release_id, run_id, policy_id, policy_fingerprint, comparison_policy, blank_field_policy,
                staged_rows, new_count, changed_count, unchanged_count, conflict_count, carried_forward_count, blank_over_value_count, expected_presence, warnings)
                VALUES ({$release}, '{$run}', 'X', repeat('a', 64), 'IDREF-CMP-1', 'UNRESOLVED', 0, 0, 0, 0, 0, 0, 0, 0, '{}')",
            'DELETE FROM identity_ref.release_preparations',
            'INSERT INTO identity_ref_staging.assessments SELECT * FROM identity_ref_staging.assessments LIMIT 1',
            'UPDATE identity_ref_staging.assessments SET classification = classification',
            "UPDATE identity_ref.dataset_releases SET status = 'PUBLISHED', published_at = now(), record_count = 2 WHERE id = {$release}",
            "UPDATE identity_ref.active_release SET release_id = {$release}",
            "SELECT identity_ref.assert_run_complete('{$run}'::uuid)",
            'DROP TRIGGER dataset_releases_publication_gate ON identity_ref.dataset_releases',
            'ALTER TABLE identity_ref.dataset_releases DISABLE TRIGGER dataset_releases_publication_gate',
            'CREATE OR REPLACE FUNCTION identity_ref.check_publication_gate() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$',
            'SET session_replication_role = replica',
            'DELETE FROM identity_ref_staging.assessments',
        ] as $sql) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $writer->unprepared($sql)), $sql);
        }
        $this->assertSame($first['release_id'], $this->activeReleaseId());

        $definers = array_column(DB::select("SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname LIKE 'identity_ref%' AND p.prosecdef ORDER BY 1"), 'proname');
        $this->assertSame(['abandon_release', 'begin_preparation', 'complete_preparation', 'publish_release', 'refresh_statistics', 'verify_release'], $definers);
        foreach (DB::select("SELECT p.proname, p.proconfig::text AS cfg, pg_get_userbyid(p.proowner) AS owner FROM pg_proc p
            JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname LIKE 'identity_ref%'") as $f) {
            $this->assertStringContainsString('search_path=pg_catalog, pg_temp', (string) $f->cfg, $f->proname);
            $this->assertSame(self::OWNER, $f->owner, $f->proname);
        }
    }

    /** 29 — the application role reaches nothing of P3. */
    public function test_the_application_role_has_no_access(): void
    {
        $app = $this->loginConnection(self::APP);
        $app->unprepared('SET ROLE '.self::APP);
        foreach ([
            'SELECT count(*) FROM identity_ref.release_preparations',
            'SELECT count(*) FROM identity_ref_staging.assessments',
            'SELECT identity_ref.verify_release(1)',
            'SELECT identity_ref.complete_preparation(1)',
            "SELECT identity_ref.begin_preparation('00000000-0000-0000-0000-000000000000', 'X', repeat('a', 64), 'IDREF-CMP-1', 'UNRESOLVED', NULL, NULL)",
            "SELECT * FROM identity_ref_staging.assess_run('00000000-0000-0000-0000-000000000000', NULL, 'UNRESOLVED')",
        ] as $sql) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $app->unprepared($sql)), $sql);
        }
        $e = $this->refused(fn () => $this->releases()->status($this->loginConnection(self::APP), 'famboook_test'));
        $this->assertSame(IdentityImportException::WRONG_ROLE, $e->reason);
    }

    /** 30 — publishing releases changes nothing in the operational registry or RBAC. */
    public function test_operational_tables_are_untouched(): void
    {
        $snapshot = fn () => [
            'acl' => DB::select("SELECT c.relname, c.relacl::text AS acl FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' ORDER BY 1"),
            'rows' => collect(['persons', 'families', 'family_memberships', 'users', 'roles', 'permissions', 'model_has_roles', 'role_has_permissions'])
                ->filter(fn ($t) => DB::selectOne('SELECT to_regclass(?) AS r', ['public.'.$t])->r !== null)
                ->mapWithKeys(fn ($t) => [$t => (int) DB::selectOne("SELECT count(*) AS n FROM public.{$t}")->n])->all(),
        ];
        $before = $snapshot();
        $this->assertNotEmpty($before['acl']);

        $this->importAndPublish(['900000001' => [], '012345678' => []]);
        $this->importAndPublish(['900000001' => ['city' => '16'], '900000003' => []]);

        $this->assertEquals($before, $snapshot());
        foreach (['app/Actions/PublishIdentityReleaseAction.php', '../deploy/sql/identity-ref/03-publication.sql'] as $file) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*|--[^\n]*#s', '', (string) file_get_contents(base_path($file)));
            $this->assertDoesNotMatchRegularExpression('/\b(public\.|persons|families|family_memberships|households|users|roles|permissions)\b/', $code, $file);
        }
    }

    /** 36 — command output and logs carry ids, counts and codes only; publication needs confirmation. */
    public function test_cli_output_and_logs_carry_no_values(): void
    {
        $secret = 'اسم-سري-تجريبي';
        config(['database.connections.identity_ref_import' => $this->writerConnection()->getConfig()]);
        $export = $this->export(...$this->rows(['900000771' => ['name1' => $secret, 'fatherid' => '900000772'], '012345678' => ['phonenum' => '0599000000']]));
        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['directory' => $export->dir, '--target' => 'famboook_test']));
        $run = DB::selectOne("SELECT id FROM identity_ref.import_runs WHERE status = 'RUNNING'")->id;

        $outputs = [];
        foreach ([['--validate' => true], ['--preview' => true], ['--prepare' => true]] as $mode) {
            $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', $mode + ['--run' => $run, '--target' => 'famboook_test']), json_encode($mode));
            $outputs[] = Artisan::output();
        }
        $release = (int) DB::selectOne('SELECT id FROM identity_ref.dataset_releases')->id;
        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['--verify' => true, '--release' => $release, '--target' => 'famboook_test']));
        $outputs[] = Artisan::output();

        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--publish' => true, '--release' => $release, '--target' => 'famboook_test', '--no-interaction' => true]));
        $this->assertStringContainsString('[NOT_CONFIRMED]', Artisan::output());
        $this->assertNull($this->activeReleaseId(), 'nothing is activated without confirmation');

        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['--publish' => true, '--release' => $release, '--target' => 'famboook_test', '--yes' => true]));
        $outputs[] = Artisan::output();
        $this->assertSame(0, Artisan::call('famboook:identity-ref-stage', ['--status' => true, '--target' => 'famboook_test']));
        $outputs[] = Artisan::output();
        $this->assertSame($release, $this->activeReleaseId());
        $this->assertSame(1, Artisan::call('famboook:identity-ref-stage', ['--prepare' => true, '--run' => $run, '--target' => 'famboook_test', '--policy' => 'TEST-SYNTHETIC']));
        $this->assertStringContainsString('[POLICY_NOT_APPROVED]', Artisan::output(), 'synthetic policies are not selectable from the CLI');

        // A failing activation, logged through the action.
        $logger = new RecordingLogger;
        $run2 = $this->stagedRun($this->export(...$this->rows(['900000771' => ['name1' => $secret.'2', 'fatherid' => '900000772'], '012345678' => ['phonenum' => '0599000000']])));
        $release2 = $this->prepareRun($run2, null, null, 2, $logger)['release_id'];
        $this->verifyRelease($release2);
        $this->refused(fn () => $this->publishRelease($release2, function () {
            DB::connection(collect(array_keys(config('database.connections')))->last(fn ($n) => str_starts_with($n, 'identity_ref_p2_')))
                ->insert("INSERT INTO identity_ref.records (mainid, first_seen_run_id) SELECT '900000771', first_seen_run_id FROM identity_ref.records LIMIT 1");
        }, $logger));

        $all = implode("\n", $outputs).$logger->text();
        foreach (['900000771', '900000772', '012345678', '0599000000', $secret, 'Key (', 'Failing row', 'insert into'] as $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, $all, $value);
        }
        $this->assertStringContainsString('identity_ref.release.failed', $logger->text());
    }
}
