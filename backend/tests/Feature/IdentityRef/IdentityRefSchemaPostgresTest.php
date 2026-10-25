<?php

namespace Tests\Feature\IdentityRef;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P1 — schema, constraints and raw-data preservation
 * (P1 test list items 1–9). PostgreSQL only; synthetic data only.
 */
class IdentityRefSchemaPostgresTest extends TestCase
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

    /** 1 — schema creation, ownership and constraints. */
    public function test_schema_objects_exist_and_are_owned_by_the_owner_role(): void
    {
        $tables = collect(DB::select("SELECT c.relname, pg_get_userbyid(c.relowner) AS owner FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'identity_ref' AND c.relkind IN ('r', 'v') ORDER BY 1"));
        $this->assertSame(['active_records', 'active_release', 'dataset_releases', 'import_files', 'import_issues', 'import_runs',
            'record_versions', 'records', 'release_presence'], $tables->pluck('relname')->all());
        $this->assertSame([self::OWNER], $tables->pluck('owner')->unique()->values()->all());
        $this->assertSame(self::OWNER, DB::selectOne("SELECT pg_get_userbyid(nspowner) AS o FROM pg_namespace WHERE nspname = 'identity_ref'")->o);

        // A few CHECKs: SHA-256 format, run status, single RUNNING run.
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::insert(
            "INSERT INTO identity_ref.import_runs (manifest_sha256, source_table, expected_rows) VALUES ('nothex', 't03maindata', 1)"))));
        $this->startRun();
        $this->assertSame('23505', $this->sqlStateOf(fn () => $this->startRun()), 'only one RUNNING run');
    }

    public function test_the_schema_refuses_to_be_applied_by_any_role_but_the_owner(): void
    {
        DB::unprepared($this->sqlFile('90-drop.local-test-only.sql'));
        $this->assertNotNull($this->sqlStateOf(fn () => DB::unprepared($this->sqlFile('01-schema.sql'))), 'as administrator');
        $this->assertNotNull($this->sqlStateOf(fn () => $this->asRole(self::APP, fn () => DB::unprepared($this->sqlFile('01-schema.sql')))), 'as app');
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM pg_namespace WHERE nspname = 'identity_ref'")->n);
    }

    /** 2 — duplicate mainid rejected within its scope. */
    public function test_duplicate_mainid_is_rejected_per_anchor_and_per_release(): void
    {
        $run = $this->startRun();
        $release = $this->newRelease($run, null);
        $this->present($run, $release, $this->payload('900000001'));

        $this->assertSame('23505', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::insert(
            'INSERT INTO identity_ref.records (mainid, first_seen_run_id) VALUES (?, ?)', ['900000001', $run]))), 'anchor unique');
        $this->assertSame('23505', $this->sqlStateOf(fn () => $this->present($run, $release, $this->payload('900000001', ['name1' => 'آخر']))),
            'one version per identity per release');

        // The same identity in another release is fine (release-scoped uniqueness).
        $this->publish($release);
        $this->finishRun($run);
        $run2 = $this->startRun();
        $release2 = $this->newRelease($run2, $release);
        $this->present($run2, $release2, $this->payload('900000001'));
        $this->assertSame(1, $this->publish($release2));
    }

    /** 3 and 4 — leading zeros and non-standard lengths are preserved. */
    public function test_leading_zeros_and_non_standard_lengths_are_preserved(): void
    {
        $this->publishedRelease(['012345678' => [], '7' => [], '123' => [], '900000001' => ['fatherid' => '000000042']]);
        // array_keys() would turn '123' into int 123; read the stored text directly.
        $this->assertSame(['012345678', '123', '7', '900000001'], $this->asRole(self::OWNER, fn () => collect(DB::select(
            'SELECT mainid FROM identity_ref.active_records ORDER BY mainid'))->pluck('mainid')->all()));
        $payload = $this->asRole(self::OWNER, fn () => json_decode(DB::selectOne(
            "SELECT source_payload FROM identity_ref.active_records WHERE mainid = '900000001'")->source_payload, true));
        $this->assertSame('000000042', $payload['fatherid']);
    }

    /** 5 and 6 — NULL vs '' and every raw field preserved exactly. */
    public function test_null_versus_empty_string_and_all_raw_fields_are_preserved(): void
    {
        $original = $this->payload('900000002', [
            'street' => null, 'city' => '', 'govern' => '  رمز غير معروف  ', 'householdrel' => 'X9', 'age' => '035',
            'datebirth' => '1890-02-30T00:00:00', 'partnerid' => null,
        ]);
        $run = $this->startRun();
        $release = $this->newRelease($run, null);
        $this->present($run, $release, $original);
        $this->publish($release);

        $row = $this->asRole(self::OWNER, fn () => DB::selectOne("SELECT source_payload, jsonb_typeof(source_payload->'street') AS street_type,
            jsonb_typeof(source_payload->'city') AS city_type FROM identity_ref.active_records WHERE mainid = '900000002'"));
        $this->assertSame('null', $row->street_type);
        $this->assertSame('string', $row->city_type);
        $stored = json_decode($row->source_payload, true);
        ksort($stored);
        ksort($original);
        $this->assertSame($original, $stored, 'all 36 values byte-identical: unknown codes, spaces, leading zeros, invalid dates untouched');
    }

    public function test_malformed_payloads_are_rejected(): void
    {
        $run = $this->startRun();
        $release = $this->newRelease($run, null);
        $missing = $this->payload('900000003');
        unset($missing['job']);
        $extra = $this->payload('900000004', ['unknown_column' => 'x']);
        $numeric = $this->payload('900000005');
        $numeric['age'] = 35;
        $mismatch = $this->payload('900000006');

        foreach (['35 keys' => $missing, 'extra key' => $extra, 'non-string value' => $numeric] as $label => $p) {
            $this->assertNotNull($this->sqlStateOf(fn () => $this->present($run, $release, $p)), $label);
        }
        // The payload's mainid must equal the anchor's.
        $this->assertNotNull($this->sqlStateOf(fn () => $this->asRole(self::WRITER, function () use ($run, $mismatch) {
            DB::insert('INSERT INTO identity_ref.records (mainid, first_seen_run_id) VALUES (?, ?)', ['900000007', $run]);
            $id = DB::selectOne("SELECT id FROM identity_ref.records WHERE mainid = '900000007'")->id;
            DB::insert("INSERT INTO identity_ref.record_versions (record_id, version_no, content_sha256, source_payload, created_run_id)
                        VALUES (?, 1, decode(?, 'hex'), ?::jsonb, ?)", [$id, str_repeat('00', 32), json_encode($mismatch), $run]);
        })), 'payload mainid differs from the anchor');
    }

    /** 7 — foreign-key integrity, no cascades. */
    public function test_foreign_keys_hold_and_never_cascade(): void
    {
        $run = $this->startRun();
        $release = $this->newRelease($run, null);
        $vA = $this->present($run, $release, $this->payload('900000010'));
        $this->present($run, $release, $this->payload('900000011'));
        $recordB = DB::selectOne("SELECT id FROM identity_ref.records WHERE mainid = '900000011'")->id;

        // Unknown record (the presence row is checked by its foreign key).
        $this->assertSame('23503', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::insert(
            "INSERT INTO identity_ref.release_presence VALUES (?, 999999, ?, 'PRESENT')", [$release, $vA]))));
        // Unknown run.
        $this->assertSame('23503', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::insert(
            "INSERT INTO identity_ref.import_files (run_id, file_name, expected_sha256, expected_rows) VALUES (?, 'a.csv', ?, 1)",
            ['00000000-0000-0000-0000-000000000000', str_repeat('b', 64)]))));

        // A version of A cannot be presented as B's version (composite FK).
        $run2 = $this->startRun2($run);
        $release2 = $this->newRelease($run2, null);
        $this->assertSame('23503', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::insert(
            "INSERT INTO identity_ref.release_presence VALUES (?, ?, ?, 'PRESENT')", [$release2, $recordB, $vA]))));

        $rules = collect(DB::select("SELECT confdeltype FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
            WHERE n.nspname = 'identity_ref' AND c.contype = 'f'"))->pluck('confdeltype')->unique()->values()->all();
        $this->assertSame(['r'], $rules, 'every foreign key is ON DELETE RESTRICT');
    }

    /** A second run needs the first finished (single RUNNING run) — the release of $run stays BUILDING. */
    private function startRun2(string $run): string
    {
        $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.abandon_release(id) FROM identity_ref.dataset_releases WHERE run_id = ?', [$run]));
        $this->finishRun($run, 'FAILED');

        return $this->startRun();
    }

    /** 8 and 9 — history is immutable and nothing can be deleted, for any role (triggers fire for owners too). */
    public function test_reference_data_cannot_be_updated_deleted_or_truncated_by_any_role(): void
    {
        $release = $this->publishedRelease(['900000020' => [], '900000021' => []]);

        $statements = [
            "UPDATE identity_ref.records SET mainid = '900000099' WHERE mainid = '900000020'",
            "UPDATE identity_ref.record_versions SET source_payload = source_payload || '{\"name1\": \"x\"}'",
            "UPDATE identity_ref.release_presence SET source_state = 'CARRIED_FORWARD'",
            'DELETE FROM identity_ref.records',
            'DELETE FROM identity_ref.record_versions',
            'DELETE FROM identity_ref.release_presence',
            'DELETE FROM identity_ref.dataset_releases',
            'DELETE FROM identity_ref.active_release',
            'DELETE FROM identity_ref.import_runs',
            'TRUNCATE identity_ref.records CASCADE',
            'TRUNCATE identity_ref.record_versions CASCADE',
            'TRUNCATE identity_ref.release_presence',
            "UPDATE identity_ref.dataset_releases SET status = 'ABANDONED', abandoned_at = now(), published_at = NULL",
        ];
        foreach ([[null, 'administrator'], [self::OWNER, 'owner'], [self::WRITER, 'writer']] as [$role, $label]) {
            foreach ($statements as $sql) {
                $state = $role === null ? $this->sqlStateOf(fn () => DB::unprepared($sql))
                    : $this->sqlStateOf(fn () => $this->asRole($role, fn () => DB::unprepared($sql)));
                $this->assertContains($state, ['42501', '23514'], "{$label}: {$sql}");
            }
        }
        $this->assertSame(['900000020' => 'PRESENT', '900000021' => 'PRESENT'], $this->activeIdentities());
        $this->assertSame($release, (int) DB::selectOne('SELECT release_id FROM identity_ref.active_release')->release_id);
    }
}
