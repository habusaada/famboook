<?php

namespace Tests\Feature\IdentityRef;

use App\Support\IdentityRef\IdentityRefErrorRedactor;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P1 — error/log redaction (item 19) and isolation from the
 * operational registry and RBAC (item 20).
 */
class IdentityRefIsolationPostgresTest extends TestCase
{
    use IdentityRefDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityRef();
    }

    protected function tearDown(): void
    {
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    private function caught(\Closure $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('expected a database error');
    }

    /** 19 — PostgreSQL and Laravel put values in error texts; the redactor keeps none of them. */
    public function test_database_errors_are_redacted_before_logging(): void
    {
        $run = $this->startRun();
        $release = $this->newRelease($run, null);
        $this->present($run, $release, $this->payload('900000201', ['name1' => 'قيمة-سرية-تجريبية']));

        $cases = [
            'unique' => [fn () => $this->asRole(self::WRITER, fn () => DB::insert(
                'INSERT INTO identity_ref.records (mainid, first_seen_run_id) VALUES (?, ?)', ['900000201', $run])), '23505', 'records_mainid_key'],
            'check' => [fn () => $this->asRole(self::WRITER, fn () => DB::insert(
                'INSERT INTO identity_ref.records (mainid, first_seen_run_id) VALUES (?, ?)', [' 900000202', $run])), '23514', 'records_mainid_check'],
            'immutable' => [fn () => DB::update("UPDATE identity_ref.records SET mainid = '900000203' WHERE mainid = '900000201'"), '42501', null],
            'presence' => [fn () => $this->present($run, $release, $this->payload('900000201', ['name1' => 'قيمة-سرية-تجريبية-2'])), '23505', null],
        ];
        foreach ($cases as $label => [$fn, $state, $constraint]) {
            $e = $this->caught($fn);
            $redacted = IdentityRefErrorRedactor::redact($e);

            $this->assertStringContainsString("sqlstate={$state}", $redacted, $label);
            if ($constraint !== null) {
                $this->assertStringContainsString("constraint={$constraint}", $redacted, $label);
            }
            foreach (['900000201', '900000202', '900000203', 'قيمة-سرية', 'Failing row', 'Key (', 'insert into', 'update '] as $value) {
                $this->assertStringNotContainsStringIgnoringCase($value, $redacted, "{$label}: {$value}");
            }
        }

        // The leak vector is real: unredacted, the duplicate key error carries the identity.
        $raw = $this->caught($cases['unique'][0])->getMessage();
        $this->assertStringContainsString('900000201', $raw);

        // SQLSTATE found on a wrapped PDOException; the schema's code is kept.
        $pdoError = new \PDOException('x');
        $pdoError->errorInfo = ['55P03', 7, 'x'];
        $this->assertSame('identity_ref database error sqlstate=55P03 code=IDENTITY_REF_PUBLICATION_IN_PROGRESS',
            IdentityRefErrorRedactor::redact(new \RuntimeException('ERROR: IDENTITY_REF_PUBLICATION_IN_PROGRESS', 0, $pdoError)));
    }

    /** 19 — the schema's own messages and issue records carry no values. */
    public function test_schema_messages_and_issue_rows_cannot_carry_values(): void
    {
        $bodies = collect(DB::select("SELECT p.prosrc FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'identity_ref'"))
            ->pluck('prosrc')->implode("\n");
        preg_match_all('/RAISE EXCEPTION\s+\'([^\']*)\'\s*,?\s*([^;]*?)(USING|;)/s', $bodies, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[2] as $i => $args) {
            $this->assertStringStartsWith('IDENTITY_REF_', $m[1][$i]);
            $this->assertDoesNotMatchRegularExpression('/NEW|OLD|p_release|payload|mainid/i', $args, 'message arguments: '.$m[1][$i]);
        }

        $run = $this->startRun();
        foreach ([
            ['line_number' => 1, 'code' => 'bad code with spaces'],
            ['line_number' => 1, 'code' => 'BAD', 'column_name' => '900000204'],
            ['line_number' => 1, 'code' => 'BAD', 'file_name' => '../900000204 name.csv'],
        ] as $row) {
            $this->assertSame('23514', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::table('identity_ref.import_issues')
                ->insert(['run_id' => $run] + $row))));
        }
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::update(
            "UPDATE identity_ref.import_runs SET error_code = 'Duplicate 900000204' WHERE id = ?", [$run]))));
    }

    /** 20 — applying the reference schema changes nothing in the operational registry or RBAC. */
    public function test_operational_tables_and_rbac_are_untouched(): void
    {
        $snapshot = fn () => [
            'objects' => DB::select("SELECT n.nspname, c.relname, c.relkind, pg_get_userbyid(c.relowner) AS owner, c.relacl::text AS acl
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname NOT IN ('identity_ref', 'pg_catalog', 'information_schema', 'pg_toast')
                AND n.nspname NOT LIKE 'pg_temp%' AND n.nspname NOT LIKE 'pg_toast_temp%' ORDER BY 1, 2"),
            'columns' => DB::select("SELECT table_schema, table_name, column_name, data_type FROM information_schema.columns
                WHERE table_schema NOT IN ('identity_ref', 'pg_catalog', 'information_schema') ORDER BY 1, 2, 3"),
            'triggers' => DB::select("SELECT tgrelid::regclass::text AS t, tgname FROM pg_trigger WHERE NOT tgisinternal
                AND tgrelid NOT IN (SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'identity_ref') ORDER BY 1, 2"),
            'app_role' => DB::select("SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolinherit FROM pg_roles WHERE rolname = 'famboook_app'"),
            'memberships' => DB::select('SELECT pg_get_userbyid(roleid) AS r, pg_get_userbyid(member) AS m FROM pg_auth_members ORDER BY 1, 2'),
            'rows' => collect(['persons', 'families', 'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'])
                ->filter(fn ($t) => DB::selectOne('SELECT to_regclass(?) AS r', ['public.'.$t])->r !== null)
                ->mapWithKeys(fn ($t) => [$t => (int) DB::selectOne("SELECT count(*) AS n FROM public.{$t}")->n])->all(),
            'public_schema_acl' => DB::selectOne("SELECT nspacl::text AS acl FROM pg_namespace WHERE nspname = 'public'")->acl,
        ];

        DB::unprepared($this->sqlFile('90-drop.local-test-only.sql'));
        $before = $snapshot();
        DB::unprepared($this->sqlFile('00-roles.dba.sql'));
        $this->asRole(self::OWNER, fn () => DB::unprepared($this->sqlFile('01-schema.sql')));
        $this->publishedRelease(['900000210' => []]);
        $this->assertEquals($before, $snapshot());

        // No identity_ref object depends on anything outside identity_ref.
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
            JOIN pg_class t ON t.oid = c.confrelid JOIN pg_namespace tn ON tn.oid = t.relnamespace
            WHERE n.nspname = 'identity_ref' AND c.contype = 'f' AND tn.nspname <> 'identity_ref'")->n);
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_depend d
            JOIN pg_rewrite rw ON rw.oid = d.objid JOIN pg_class v ON v.oid = rw.ev_class JOIN pg_namespace vn ON vn.oid = v.relnamespace
            JOIN pg_class t ON t.oid = d.refobjid JOIN pg_namespace tn ON tn.oid = t.relnamespace
            WHERE vn.nspname = 'identity_ref' AND tn.nspname NOT IN ('identity_ref', 'pg_catalog')")->n);
        foreach (['00-roles.dba.sql', '01-schema.sql'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(persons|families|households|users|roles|permissions|model_has_\w+|public)\s*\./i',
                preg_replace('/--.*$/m', '', $this->sqlFile($file)), $file.' (comments stripped)');
        }
    }
}
