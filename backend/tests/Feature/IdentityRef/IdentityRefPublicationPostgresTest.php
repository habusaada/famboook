<?php

namespace Tests\Feature\IdentityRef;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P1 — release publication, concurrency and recovery
 * (P1 test list items 12–18). Concurrency uses real, separate PostgreSQL
 * sessions (own transactions and snapshots), not simulated interleavings.
 */
class IdentityRefPublicationPostgresTest extends TestCase
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

    /** @return list<string> the active mainids as seen by $pdo's current snapshot */
    private function activeVia(PDO $pdo): array
    {
        return $pdo->query('SELECT mainid FROM identity_ref.active_records ORDER BY mainid')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function activeReleaseId(): ?int
    {
        $row = DB::selectOne('SELECT release_id FROM identity_ref.active_release WHERE id = 1');

        return $row === null ? null : (int) $row->release_id;
    }

    /** A BUILDING release (rows committed) on top of the active one, not yet published. */
    private function building(array $identities, ?int $base): array
    {
        $run = $this->startRun();
        $release = $this->newRelease($run, $base);
        foreach ($identities as $mainid => $overrides) {
            $this->present($run, $release, $this->payload((string) $mainid, $overrides));
        }

        return [$run, $release];
    }

    /** 12 — publication is atomic: readers see the old complete set until the pointer switch commits. */
    public function test_publication_is_atomic_and_building_rows_are_never_visible(): void
    {
        $r1 = $this->publishedRelease(['900000100' => [], '900000101' => []]);
        $reader = $this->connection(self::OWNER);

        [, $r2] = $this->building(['900000100' => ['name1' => 'معدل'], '900000102' => [], '900000103' => []], $r1);
        $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.carry_forward_missing(?)', [$r2]));
        $this->assertSame(['900000100', '900000101'], $this->activeVia($reader), 'committed BUILDING rows are invisible');

        $writer = $this->connection(self::WRITER);
        $writer->beginTransaction();
        $this->assertSame(4, (int) $writer->query("SELECT identity_ref.publish_release({$r2})")->fetchColumn());
        $this->assertSame(['900000100', '900000101'], $this->activeVia($reader), 'uncommitted publication is invisible');
        $writer->commit();

        $this->assertSame(['900000100', '900000101', '900000102', '900000103'], $this->activeVia($reader));
        $this->assertSame('معدل', json_decode($reader->query(
            "SELECT source_payload FROM identity_ref.active_records WHERE mainid = '900000100'")->fetchColumn(), true)['name1']);
        $this->assertSame($r2, $this->activeReleaseId());
    }

    /** 13 — every failed publication leaves the previous release active and intact. */
    public function test_failed_publication_keeps_the_previous_release(): void
    {
        $r1 = $this->publishedRelease(['900000110' => [], '900000111' => []]);

        // Empty release.
        $run = $this->startRun();
        $empty = $this->newRelease($run, $r1);
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->publish($empty)));
        $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.abandon_release(?)', [$empty]));
        $this->finishRun($run, 'FAILED');

        // Rolled back after a successful publish call (e.g. the process fails before COMMIT).
        [$run, $r2] = $this->building(['900000110' => [], '900000111' => [], '900000112' => []], $r1);
        $writer = $this->connection(self::WRITER);
        $writer->beginTransaction();
        $writer->query("SELECT identity_ref.publish_release({$r2})");
        $writer->rollBack();
        $this->assertSame($r1, $this->activeReleaseId());
        $this->assertSame('BUILDING', DB::selectOne('SELECT status FROM identity_ref.dataset_releases WHERE id = ?', [$r2])->status);

        // An abandoned release can never be published nor made active.
        $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.abandon_release(?)', [$r2]));
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->publish($r2)));
        $this->assertSame('23514', $this->sqlStateOf(fn () => DB::update('UPDATE identity_ref.active_release SET release_id = ?', [$r2])),
            'not even the administrator can point at a non-published release');
        $this->finishRun($run, 'FAILED');

        $this->assertSame($r1, $this->activeReleaseId());
        $this->assertSame(['900000110' => 'PRESENT', '900000111' => 'PRESENT'], $this->activeIdentities());
    }

    /** 14 — a reader's snapshot always holds exactly one complete release. */
    public function test_concurrent_readers_see_one_complete_release(): void
    {
        $r1 = $this->publishedRelease(['900000120' => [], '900000121' => [], '900000122' => []]);
        [, $r2] = $this->building(['900000120' => ['name1' => 'جديد'], '900000123' => []], $r1);
        $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.carry_forward_missing(?)', [$r2]));

        $reader = $this->connection(self::OWNER);
        $reader->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        $before = $this->activeVia($reader);

        $this->publish($r2);

        $this->assertSame($before, $this->activeVia($reader), 'same snapshot, same release, before and after the switch');
        $this->assertSame(1, (int) $reader->query('SELECT count(DISTINCT release_id) FROM identity_ref.active_records')->fetchColumn());
        $this->assertSame((string) $r1, (string) $reader->query('SELECT release_id FROM identity_ref.active_release')->fetchColumn());
        $reader->exec('COMMIT');

        $this->assertSame(['900000120', '900000121', '900000122', '900000123'], $this->activeVia($reader));
        $counts = $reader->query('SELECT (SELECT count(*) FROM identity_ref.active_records) AS seen, d.record_count FROM identity_ref.active_release a
            JOIN identity_ref.dataset_releases d ON d.id = a.release_id')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame((int) $counts['record_count'], (int) $counts['seen']);
    }

    /** 15 — publications are serialized (fail fast) and a stale base cannot overwrite a newer release. */
    public function test_concurrent_publication_is_locked_and_stale_bases_are_rejected(): void
    {
        $r1 = $this->publishedRelease(['900000130' => []]);
        [$run, $r2] = $this->building(['900000130' => [], '900000131' => []], $r1);

        $first = $this->connection(self::WRITER);
        $second = $this->connection(self::WRITER);
        $first->beginTransaction();
        $first->query("SELECT identity_ref.publish_release({$r2})");

        $started = microtime(true);
        $this->assertSame('55P03', $this->sqlStateOf(fn () => $second->query("SELECT identity_ref.publish_release({$r2})")));
        $this->assertLessThan(5.0, microtime(true) - $started, 'fails fast, never waits');

        $first->commit();
        $this->assertSame('23514', $this->sqlStateOf(fn () => $second->query("SELECT identity_ref.publish_release({$r2})")), 'already published');
        $this->finishRun($run);

        // A release built on an older base (here: none) is refused once a newer release is active.
        [$run, $stale] = $this->building(['900000130' => []], null);
        $this->assertSame('40001', $this->sqlStateOf(fn () => $this->publish($stale)));
        $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.abandon_release(?)', [$stale]));
        $this->finishRun($run, 'FAILED');
        $this->assertSame($r2, $this->activeReleaseId());
    }

    /** 16 — unchanged rows create no new versions; a change creates version 2 and keeps version 1. */
    public function test_unchanged_rows_create_no_new_versions(): void
    {
        $r1 = $this->publishedRelease(['900000140' => [], '900000141' => []]);
        $countVersions = fn () => (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.record_versions')->n;
        $this->assertSame(2, $countVersions());

        $r2 = $this->publishedRelease(['900000140' => [], '900000141' => []], $r1);
        $this->assertSame(2, $countVersions(), 'identical payloads reuse their version');

        $this->publishedRelease(['900000140' => ['city' => 'مدينة-أخرى'], '900000141' => []], $r2);
        $this->assertSame(3, $countVersions());
        $versions = DB::select("SELECT v.version_no, v.source_payload->>'city' AS city FROM identity_ref.record_versions v
            JOIN identity_ref.records r ON r.id = v.record_id WHERE r.mainid = '900000140' ORDER BY v.version_no");
        $this->assertSame([[1, ''], [2, 'مدينة-أخرى']], array_map(fn ($v) => [(int) $v->version_no, $v->city], $versions));

        // History: release 1 still resolves to version 1.
        $this->assertSame(1, (int) DB::selectOne("SELECT v.version_no FROM identity_ref.release_presence p
            JOIN identity_ref.record_versions v ON v.id = p.record_version_id JOIN identity_ref.records r ON r.id = p.record_id
            WHERE p.release_id = ? AND r.mainid = '900000140'", [$r1])->version_no);
    }

    /** 17 — identities missing from a new source are carried forward, never deleted. */
    public function test_missing_identities_are_carried_forward_not_deleted(): void
    {
        $r1 = $this->publishedRelease(['900000150' => [], '900000151' => [], '900000152' => []]);

        [$run, $r2] = $this->building(['900000150' => []], $r1);
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->publish($r2)), 'a release that drops identities is refused');
        $this->assertSame(2, (int) $this->asRole(self::WRITER, fn () => DB::selectOne('SELECT identity_ref.carry_forward_missing(?) AS n', [$r2])->n));
        $this->assertSame(3, $this->publish($r2));
        $this->finishRun($run);

        $this->assertSame(['900000150' => 'PRESENT', '900000151' => 'CARRIED_FORWARD', '900000152' => 'CARRIED_FORWARD'], $this->activeIdentities());
        $this->assertSame(3, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.records')->n);
    }

    /** 18 — an interrupted import leaves nothing half-applied and can be recovered. */
    public function test_interrupted_import_is_recoverable(): void
    {
        $r1 = $this->publishedRelease(['900000160' => []]);

        // (a) Session killed mid-transaction: everything it wrote disappears.
        $victim = $this->connection(self::WRITER);
        $victim->beginTransaction();
        $run = $victim->query("INSERT INTO identity_ref.import_runs (manifest_sha256, source_table, expected_rows)
            VALUES ('".str_repeat('d', 64)."', 't03maindata', 1) RETURNING id")->fetchColumn();
        $victim->exec("INSERT INTO identity_ref.records (mainid, first_seen_run_id) VALUES ('900000161', '{$run}')");
        $pid = (int) $victim->query('SELECT pg_backend_pid()')->fetchColumn();
        DB::select('SELECT pg_terminate_backend(?)', [$pid]);
        $victim = null;

        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs WHERE id = ?', [$run])->n);
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) AS n FROM identity_ref.records WHERE mainid = '900000161'")->n);
        $this->assertSame($r1, $this->activeReleaseId());

        // (b) Process died after committing a RUNNING run and a BUILDING release.
        [$run, $orphan] = $this->building(['900000160' => [], '900000162' => []], $r1);
        $this->assertSame('23505', $this->sqlStateOf(fn () => $this->startRun()), 'no second run while one is RUNNING');
        $this->asRole(self::WRITER, fn () => DB::select('SELECT identity_ref.abandon_release(?)', [$orphan]));
        $this->asRole(self::WRITER, fn () => DB::update("UPDATE identity_ref.import_runs SET status = 'FAILED', finished_at = now(),
            error_code = 'INTERRUPTED' WHERE id = ?", [$run]));
        $this->assertSame('23514', $this->sqlStateOf(fn () => $this->present($run, $orphan, $this->payload('900000163'))));

        $this->publishedRelease(['900000160' => [], '900000164' => []], $r1);
        $this->assertSame(['900000160' => 'PRESENT', '900000164' => 'PRESENT'], $this->activeIdentities());
        $this->assertSame('ABANDONED', DB::selectOne('SELECT status FROM identity_ref.dataset_releases WHERE id = ?', [$orphan])->status);
    }
}
