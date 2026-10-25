<?php

namespace Tests\Feature\IdentityRef;

use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityImportPolicy;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P3 — comparison, versioning, presence and the release
 * lifecycle (P3 test list 1–11, 16, 20, 21, 27, 31–34). PostgreSQL only,
 * schema 00–03; every step runs as a real writer login; SYNTHETIC data only.
 */
class IdentityRefReleasePostgresTest extends TestCase
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

    /** 1, 6, 7, 32, 33 — first release: every identity NEW, keys kept verbatim, provenance recorded. */
    public function test_first_release_publishes_new_identities(): void
    {
        $report = $this->importAndPublish(['900000001' => [], '012345678' => [], '7' => [], '123' => []]);

        $this->assertSame('PUBLISHED', $report['outcome']);
        $this->assertSame(['staged' => 4, 'new' => 4, 'changed' => 0, 'unchanged' => 0, 'conflict' => 0, 'carried_forward' => 0,
            'blank_over_value' => 0, 'expected_presence' => 4], $report['counts']);
        $this->assertSame($report['release_id'], $this->activeReleaseId());
        $this->assertSame(['012345678:PRESENT', '123:PRESENT', '7:PRESENT', '900000001:PRESENT'], $this->activeSet());
        $this->assertSame(['MAINID_NOT_9_DIGITS' => 2], array_filter($report['warnings']), 'reported, never rejected');

        $run = DB::selectOne('SELECT status, new_records, new_versions, unchanged, carried_forward FROM identity_ref.import_runs WHERE id = ?', [$report['run_id']]);
        $this->assertSame(['SUCCEEDED', 4, 4, 0, 0], [$run->status, (int) $run->new_records, (int) $run->new_versions, (int) $run->unchanged, (int) $run->carried_forward]);
        $this->assertSame(4, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.record_versions v JOIN identity_ref.dataset_releases d
            ON d.run_id = v.created_run_id WHERE d.id = ? AND v.version_no = 1', [$report['release_id']])->n, 'every version attributable to its run and release');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.rows')->n, 'staging purged after publication');
    }

    /** 2, 31 — a second release with only UNCHANGED identities creates no version. */
    public function test_second_release_with_unchanged_identities_creates_no_version(): void
    {
        $first = $this->importAndPublish(['900000001' => [], '900000002' => []]);
        $second = $this->importAndPublish(['900000001' => [], '900000002' => []]);

        $this->assertSame([0, 0, 2, 0], [$second['counts']['new'], $second['counts']['changed'], $second['counts']['unchanged'], $second['counts']['carried_forward']]);
        $this->assertSame(2, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.record_versions')->n);
        $this->assertSame($first['release_id'], $second['base_release']);
        // Both releases point at the very same versions.
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.release_presence a JOIN identity_ref.release_presence b
            ON b.record_id = a.record_id WHERE a.release_id = ? AND b.release_id = ? AND a.record_version_id <> b.record_version_id',
            [$first['release_id'], $second['release_id']])->n);
    }

    /** 3, 4, 5, 27, 31, 33 — mixed NEW/CHANGED/UNCHANGED; missing retained; old versions immutable and still resolvable. */
    public function test_mixed_release_versions_changes_and_retains_missing_identities(): void
    {
        $first = $this->importAndPublish(['900000001' => [], '900000002' => [], '900000003' => []]);
        $v1 = DB::selectOne("SELECT v.id, v.source_payload FROM identity_ref.record_versions v JOIN identity_ref.records r ON r.id = v.record_id
            WHERE r.mainid = '900000002'");

        $second = $this->importAndPublish([
            '900000001' => [],                                // UNCHANGED
            '900000002' => ['city' => '16', 'street' => 'شارع-جديد'],  // CHANGED (value → value)
            '900000004' => [],                                // NEW
        ]);                                                   // 900000003 missing → CARRIED_FORWARD

        $this->assertSame(['staged' => 3, 'new' => 1, 'changed' => 1, 'unchanged' => 1, 'conflict' => 0, 'carried_forward' => 1,
            'blank_over_value' => 0, 'expected_presence' => 4], $second['counts']);
        $this->assertSame(['900000001:PRESENT', '900000002:PRESENT', '900000003:CARRIED_FORWARD', '900000004:PRESENT'], $this->activeSet());
        $this->assertSame('16', $this->activePayload('900000002')['city']);

        $versions = DB::select("SELECT v.version_no, v.created_run_id, v.source_payload->>'city' AS city FROM identity_ref.record_versions v
            JOIN identity_ref.records r ON r.id = v.record_id WHERE r.mainid = '900000002' ORDER BY v.version_no");
        $this->assertSame([[1, $first['run_id'], '15'], [2, $second['run_id'], '16']], array_map(fn ($v) => [(int) $v->version_no, $v->created_run_id, $v->city], $versions));
        $this->assertSame($v1->source_payload, DB::selectOne('SELECT source_payload FROM identity_ref.record_versions WHERE id = ?', [$v1->id])->source_payload);
        $this->assertSame('15', json_decode(DB::selectOne("SELECT v.source_payload FROM identity_ref.release_presence p
            JOIN identity_ref.record_versions v ON v.id = p.record_version_id JOIN identity_ref.records r ON r.id = p.record_id
            WHERE p.release_id = ? AND r.mainid = '900000002'", [$first['release_id']])->source_payload, true)['city'], 'release 1 still resolves to version 1');
        $this->assertSame(4, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.records')->n, 'nothing deleted');

        foreach (['UPDATE identity_ref.record_versions SET source_payload = source_payload', 'DELETE FROM identity_ref.record_versions',
            'DELETE FROM identity_ref.records', 'UPDATE identity_ref.release_presence SET source_state = source_state'] as $sql) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::unprepared($sql))), $sql);
            $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::OWNER, fn () => DB::unprepared($sql))), $sql);
        }
    }

    /** 3 — a value that reverts to an earlier version reuses that version (no duplicate payload). */
    public function test_a_reverted_value_reuses_the_earlier_version(): void
    {
        $this->importAndPublish(['900000001' => []]);
        $this->importAndPublish(['900000001' => ['city' => '16']]);
        $third = $this->importAndPublish(['900000001' => []]);

        $this->assertSame(1, $third['counts']['changed']);
        $this->assertSame(2, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.record_versions')->n);
        $this->assertSame(1, (int) DB::selectOne("SELECT v.version_no FROM identity_ref.active_records v WHERE v.mainid = '900000001'")->version_no);
    }

    /** 8, 9, 16 — duplicate and missing keys are quarantined; under an approved threshold the rest is published and existing versions kept. */
    public function test_row_level_quarantine_under_an_approved_threshold(): void
    {
        $this->importAndPublish(['900000001' => [], '900000002' => []]);
        $rows = $this->rows(['900000001' => ['city' => '99'], '900000003' => [], '900000004' => []]);
        $rows[] = SyntheticIdentityExport::row('900000001', ['city' => '98']);   // duplicate key
        $rows[] = SyntheticIdentityExport::row('', ['mainid' => null]);          // missing key
        $run = $this->stagedRun($this->export(...$rows));

        $policy = IdentityImportPolicy::synthetic(maxRows: 5, maxRatio: '0.6');   // 3 of 5 rows quarantined = 60 %
        $prepared = $this->prepareRun($run, $policy);
        $this->assertSame(['staged' => 5, 'new' => 2, 'changed' => 0, 'unchanged' => 0, 'conflict' => 3, 'carried_forward' => 2,
            'blank_over_value' => 0, 'expected_presence' => 4], $prepared['counts']);
        $this->verifyRelease($prepared['release_id']);
        $this->publishRelease($prepared['release_id']);

        $this->assertSame(['900000001:CARRIED_FORWARD', '900000002:CARRIED_FORWARD', '900000003:PRESENT', '900000004:PRESENT'], $this->activeSet());
        $this->assertSame('15', $this->activePayload('900000001')['city'], 'the quarantined identity keeps its published version');
        $this->assertSame(['MAINID_DUPLICATE' => 2, 'MAINID_MISSING' => 1], collect(DB::select("SELECT code, count(*) AS n FROM identity_ref.import_issues
            WHERE run_id = ? AND code IN ('MAINID_DUPLICATE', 'MAINID_MISSING') GROUP BY code ORDER BY code", [$run]))->mapWithKeys(fn ($r) => [$r->code => (int) $r->n])->all());
    }

    /** 10 — unmatched parent identifiers are warnings only: kept verbatim, no relationship created. */
    public function test_unmatched_parent_identifiers_are_reported_not_rejected(): void
    {
        $report = $this->importAndPublish([
            '900000001' => [],
            '900000002' => ['fatherid' => '900000001', 'motherid' => '800000009'],   // father resolves, mother does not
            '900000003' => ['fatherid' => '000000042', 'partnerid' => '900000002'],  // unresolved; partnerid populated
        ]);

        $this->assertSame(['FATHERID_UNRESOLVED' => 1, 'MOTHERID_UNRESOLVED' => 1, 'P0_EMPTY_COLUMN_POPULATED' => 1, 'PARTNERID_POPULATED' => 1],
            array_filter($report['warnings']), 'partnerid is also one of the P0 always-empty columns');
        $this->assertSame(3, $report['counts']['new']);
        $this->assertSame('800000009', $this->activePayload('900000002')['motherid']);
        $this->assertSame('000000042', $this->activePayload('900000003')['fatherid']);
        $this->assertSame([], DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema LIKE 'identity_ref%'
            AND table_name ~ '(relation|spouse|partner|parent|family|genealog)'"), 'no relationship structure exists');
    }

    /** 11 — a blank-over-value change is blocked while the blank-field policy is unresolved; approved policies behave as defined. */
    public function test_blank_over_value_follows_the_blank_field_policy(): void
    {
        $this->importAndPublish(['900000001' => [], '900000002' => []]);
        $blanked = $this->rows(['900000001' => ['city' => null], '900000002' => ['city' => '17']]);

        $run = $this->stagedRun($this->export(...$blanked));
        $e = $this->refused(fn () => $this->prepareRun($run, IdentityImportPolicy::approved('IDREF-POLICY-0')));
        $this->assertSame([IdentityImportException::POLICY_BLOCKED, ['POLICY_BLANK_FIELD_UNRESOLVED']], [$e->reason, $e->problems],
            'the earlier no-decision baseline still fails closed');
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.dataset_releases')->n, 'nothing was prepared');

        // QUARANTINE_ROW (with an approved quarantine threshold): the blanked identity keeps its version.
        $prepared = $this->prepareRun($run, IdentityImportPolicy::synthetic(IdentityImportPolicy::BLANK_QUARANTINE_ROW, 1, '0.5'));
        $this->assertSame([1, 1, 1], [$prepared['counts']['conflict'], $prepared['counts']['changed'], $prepared['counts']['blank_over_value']],
            'the blank-over-value row is counted and quarantined');
        $this->verifyRelease($prepared['release_id']);
        $this->publishRelease($prepared['release_id']);
        $this->assertSame('15', $this->activePayload('900000001')['city']);
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref.import_issues WHERE run_id = ? AND code = 'BLANK_OVER_VALUE'", [$run])->n);

        // ACCEPT_SOURCE: the source's blank becomes the new version.
        $run = $this->stagedRun($this->export(...$blanked));
        $prepared = $this->prepareRun($run, IdentityImportPolicy::synthetic(IdentityImportPolicy::BLANK_ACCEPT_SOURCE));
        $this->assertSame([1, 0], [$prepared['counts']['blank_over_value'], $prepared['counts']['conflict']]);
        $this->verifyRelease($prepared['release_id']);
        $this->publishRelease($prepared['release_id']);
        $this->assertNull($this->activePayload('900000001')['city']);
    }

    /** 20, 34 — preparation is idempotent and resumes after an interruption without duplicates. */
    public function test_preparation_resumes_and_repeats_without_duplicates(): void
    {
        $this->importAndPublish(['900000001' => [], '900000002' => []]);
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => ['city' => '16'], '900000002' => [], '900000003' => [], '900000004' => [], '900000005' => []])));

        $chunks = new \ArrayObject(['n' => 0]);
        $e = $this->refused(fn () => $this->prepareRun($run, null, function (string $event) use ($chunks) {
            if ($event === 'prepare.chunk_committed' && ++$chunks['n'] === 2) {
                throw new RuntimeException('simulated crash during preparation');
            }
        }));
        $this->assertSame(IdentityImportException::PREPARATION_INTERRUPTED, $e->reason);
        $this->assertSame(['900000001:PRESENT', '900000002:PRESENT'], $this->activeSet(), 'readers still see the previous release');
        $this->assertSame('PREPARING', DB::selectOne('SELECT status FROM identity_ref.release_preparations WHERE run_id = ?', [$run])->status);

        $resumed = $this->prepareRun($run);
        $again = $this->prepareRun($run);
        $this->assertSame($resumed['release_id'], $again['release_id']);
        $this->assertSame(['PREPARED', 'PREPARED'], [$resumed['preparation'], $again['preparation']]);
        $this->assertSame(0, $again['chunks_materialized'], 'a prepared release is not materialized again');
        $this->assertSame(5, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.records')->n);
        $this->assertSame(6, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.record_versions')->n, '5 identities + 1 changed version, no duplicate');
        $this->assertSame(5, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.release_presence WHERE release_id = ?', [$resumed['release_id']])->n);

        $this->verifyRelease($resumed['release_id']);
        $this->publishRelease($resumed['release_id']);
        $this->assertCount(5, $this->activeSet());
    }

    /** 21 — publishing again is a no-op. */
    public function test_repeated_publication_is_idempotent(): void
    {
        $first = $this->importAndPublish(['900000001' => []]);
        $before = $this->referenceCounts();

        $again = $this->publishRelease($first['release_id']);
        $this->assertSame('ALREADY_PUBLISHED', $again['outcome']);
        $this->assertSame($before, $this->referenceCounts());
        $this->assertSame($first['release_id'], $this->activeReleaseId());
    }

    /** 32 — preview (read-only) reports exactly what preparation then records. */
    public function test_preview_counts_match_the_prepared_release_and_write_nothing(): void
    {
        $this->importAndPublish(['900000001' => [], '900000002' => [], '900000003' => []]);
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => [], '900000002' => ['age' => '36'], '900000009' => []])));

        $before = $this->referenceCounts();
        $preview = $this->releases()->assess($this->writerConnection(), $run, IdentityImportPolicy::approved(), 'famboook_test');
        $this->assertSame($before, $this->referenceCounts(), 'assess writes nothing');
        $this->assertTrue($preview['publishable']);

        $prepared = $this->prepareRun($run);
        $this->assertSame($preview['counts'], $prepared['counts']);
        $this->assertSame(['staged' => 3, 'new' => 1, 'changed' => 1, 'unchanged' => 1, 'conflict' => 0, 'carried_forward' => 1,
            'blank_over_value' => 0, 'expected_presence' => 4], $prepared['counts']);
    }
}
