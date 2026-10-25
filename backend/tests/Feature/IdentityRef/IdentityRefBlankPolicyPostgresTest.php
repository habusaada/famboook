<?php

namespace Tests\Feature\IdentityRef;

use App\Exceptions\IdentityImportException;
use App\Support\IdentityRef\IdentityImportPolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P3.1 — approved policy IDREF-POLICY-1:
 * PRESERVE_EXISTING_ON_BLANK (D-01) and ZERO_UNRESOLVED_QUARANTINE (D-02).
 * PostgreSQL only, schema 00–03, real writer logins, SYNTHETIC data only.
 */
class IdentityRefBlankPolicyPostgresTest extends TestCase
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

    /** @return array<string, string|null> the raw incoming row preserved for $mainid in $releaseId */
    private function incoming(int $releaseId, string $mainid): ?array
    {
        $row = DB::selectOne('SELECT i.source_payload FROM identity_ref.release_incoming i JOIN identity_ref.records r ON r.id = i.record_id
            WHERE i.release_id = ? AND r.mainid = ?', [$releaseId, $mainid]);

        return $row === null ? null : json_decode($row->source_payload, true);
    }

    public function test_blank_incoming_values_keep_existing_values_and_the_raw_row_is_preserved(): void
    {
        $first = $this->importAndPublish([
            '900000001' => ['street' => 'شارع-قديم', 'birthplace' => 'غزة'],
            '900000002' => ['street' => 'شارع-ثابت'],
            '900000003' => [],
        ]);
        $this->assertSame('IDREF-POLICY-1', $first['policy_id']);

        $second = $this->importAndPublish([
            '900000001' => ['street' => null, 'birthplace' => 'خانيونس'],   // blank + real change → CHANGED, street kept
            '900000002' => ['street' => ''],                              // only a blank (quoted "") → UNCHANGED
            '900000004' => ['street' => null],                            // NEW: nothing to preserve
        ]);                                                              // 900000003 missing → CARRIED_FORWARD

        $this->assertSame(['staged' => 3, 'new' => 1, 'changed' => 1, 'unchanged' => 1, 'conflict' => 0, 'carried_forward' => 1,
            'blank_over_value' => 2, 'expected_presence' => 4], $second['counts']);
        $this->assertSame('PRESERVE_EXISTING_ON_BLANK', $second['blank_field_policy']);

        // Published (effective) values.
        $this->assertSame(['شارع-قديم', 'خانيونس'], [$this->activePayload('900000001')['street'], $this->activePayload('900000001')['birthplace']]);
        $this->assertSame('شارع-ثابت', $this->activePayload('900000002')['street']);
        $this->assertNull($this->activePayload('900000004')['street']);
        $this->assertSame(['900000001:PRESENT', '900000002:PRESENT', '900000003:CARRIED_FORWARD', '900000004:PRESENT'], $this->activeSet());

        // Original incoming data preserved exactly (NULL and "" as received), only where the published version differs.
        $this->assertNull($this->incoming($second['release_id'], '900000001')['street']);
        $this->assertSame('خانيونس', $this->incoming($second['release_id'], '900000001')['birthplace']);
        $this->assertSame('', $this->incoming($second['release_id'], '900000002')['street']);
        $this->assertNull($this->incoming($second['release_id'], '900000004'));
        $this->assertSame(2, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.release_incoming')->n);

        // Change history: version 1 untouched, version 2 = effective payload, attributed to run 2; the UNCHANGED identity got no version.
        $history = DB::select("SELECT v.version_no, v.created_run_id, v.source_payload->>'street' AS street, v.source_payload->>'birthplace' AS birthplace
            FROM identity_ref.record_versions v JOIN identity_ref.records r ON r.id = v.record_id WHERE r.mainid = '900000001' ORDER BY v.version_no");
        $this->assertSame([[1, $first['run_id'], 'شارع-قديم', 'غزة'], [2, $second['run_id'], 'شارع-قديم', 'خانيونس']],
            array_map(fn ($v) => [(int) $v->version_no, $v->created_run_id, $v->street, $v->birthplace], $history));
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref.record_versions v JOIN identity_ref.records r ON r.id = v.record_id
            WHERE r.mainid = '900000002'")->n);

        // A later source that supplies the value again is UNCHANGED against the effective version.
        $third = $this->importAndPublish(['900000001' => ['street' => 'شارع-قديم', 'birthplace' => 'خانيونس'], '900000002' => ['street' => 'شارع-ثابت']]);
        $this->assertSame([0, 0, 2, 2], [$third['counts']['new'], $third['counts']['changed'], $third['counts']['unchanged'], $third['counts']['carried_forward']]);
    }

    public function test_verification_proves_the_preserved_incoming_rows(): void
    {
        $this->importAndPublish(['900000001' => ['street' => 'شارع-قديم']]);
        $run = $this->stagedRun($this->export(...$this->rows(['900000001' => ['street' => null]])));
        $prepared = $this->prepareRun($run);

        // Simulated corruption by the administrator, bypassing the triggers: the preserved raw row removed.
        DB::unprepared('SET session_replication_role = replica');
        DB::delete('DELETE FROM identity_ref.release_incoming WHERE release_id = ?', [$prepared['release_id']]);
        DB::unprepared('SET session_replication_role = origin');

        $e = $this->refused(fn () => $this->verifyRelease($prepared['release_id']));
        $this->assertSame([IdentityImportException::VERIFICATION_FAILED, ['VERIFY_INCOMING_MISMATCH']], [$e->reason, $e->problems]);
    }

    public function test_zero_unresolved_quarantine_never_publishes_a_release_with_quarantined_rows(): void
    {
        $first = $this->importAndPublish(['900000001' => [], '900000002' => []]);
        $rows = $this->rows(['900000001' => ['city' => '16'], '900000003' => []]);
        $rows[] = SyntheticIdentityExport::row('900000003');                 // duplicate key → quarantined
        $run = $this->stagedRun($this->export(...$rows));

        $assessment = $this->releases()->assess($this->writerConnection(), $run, IdentityImportPolicy::approved(), 'famboook_test');
        $this->assertSame(['QUARANTINE_NOT_ALLOWED'], $assessment['blocked']);
        $this->assertSame(['MAINID_DUPLICATE' => 2], $assessment['conflicts']);

        $before = $this->referenceCounts();
        $e = $this->refused(fn () => $this->prepareRun($run));
        $this->assertSame([IdentityImportException::POLICY_BLOCKED, ['QUARANTINE_NOT_ALLOWED']], [$e->reason, $e->problems]);
        $this->assertSame($before, $this->referenceCounts(), 'nothing was prepared');
        $this->assertSame($first['release_id'], $this->activeReleaseId());

        // The database enforces the same zero tolerance when called directly.
        $policy = IdentityImportPolicy::approved();
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::select(
            'SELECT identity_ref.begin_preparation(?::uuid, ?, ?, ?, ?, 0, 0)', [$run, $policy->id, $policy->fingerprint(), $policy->comparison, $policy->blankField]))));
        $this->assertSame('ZERO_UNRESOLVED_QUARANTINE', $policy->describe()['quarantine']);
    }

    public function test_preserved_rows_are_immutable_and_unreachable_for_the_application_role(): void
    {
        $this->importAndPublish(['900000001' => ['street' => 'شارع-قديم']]);
        $this->importAndPublish(['900000001' => ['street' => null]]);

        foreach (['UPDATE identity_ref.release_incoming SET source_payload = source_payload', 'DELETE FROM identity_ref.release_incoming',
            'TRUNCATE identity_ref.release_incoming'] as $sql) {
            $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::WRITER, fn () => DB::unprepared($sql))), "writer: {$sql}");
            $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::OWNER, fn () => DB::unprepared($sql))), "owner: {$sql}");
        }
        $this->assertSame('42501', $this->sqlStateOf(fn () => $this->asRole(self::APP, fn () => DB::unprepared('SELECT count(*) FROM identity_ref.release_incoming'))));
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.release_incoming')->n);
    }
}
