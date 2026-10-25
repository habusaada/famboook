<?php

namespace Tests\Feature\IdentityRef;

use App\Support\IdentityRef\IdentityImportPolicy;
use App\Support\IdentityRef\IdentityManifest;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P3 — opt-in two-release rehearsal with SYNTHETIC data.
 *
 *   Release 1: 24 files × IDENTITY_REF_PERF_ROWS rows (default 100,000; the
 *              last file 99,891 at full scale) — all NEW.
 *   Release 2: the same identities with 1 % CHANGED (a value → another value),
 *              0.5 % missing (carried forward) and 0.5 % NEW.
 * Measures validation, preparation, verification and activation time, PHP
 * memory growth, WAL volume, temporary files and storage. Refuses to start a
 * release without ~4.5 KB of free disk per row (~11 GB at full scale). Skipped unless IDENTITY_REF_PERF=1.
 *
 *   IDENTITY_REF_PERF=1 IDENTITY_REF_PERF_ROWS=10000 php -d memory_limit=1G vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/IdentityRef/IdentityRefReleasePerformancePostgresTest.php
 */
class IdentityRefReleasePerformancePostgresTest extends TestCase
{
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

    /** Free disk required before a release: staging + reference data + temporary files + WAL (measured ~4.5 KB per row). */
    private const BYTES_PER_ROW = 4500;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('IDENTITY_REF_PERF') !== '1') {
            $this->markTestSkipped('Volume rehearsal: set IDENTITY_REF_PERF=1 to run.');
        }
        $this->setUpIdentityRef(withPublication: true);
    }

    protected function tearDown(): void
    {
        if (getenv('IDENTITY_REF_PERF') === '1') {
            $this->tearDownIdentityRefStaging();
            $this->tearDownIdentityRef();
        }
        parent::tearDown();
    }

    public function test_two_release_rehearsal(): void
    {
        $perFile = (int) (getenv('IDENTITY_REF_PERF_ROWS') ?: 100_000);
        $last = $perFile === 100_000 ? 99_891 : $perFile;
        $total = 23 * $perFile + $last;
        $firstId = 100_000_001;

        $m1 = $this->rehearse('release 1', SyntheticIdentityExport::generated(24, $perFile, $firstId, $last), $total);
        $this->assertSame($total, $m1['counts']['new']);

        $lastId = $firstId + $total - 1;
        $missingFrom = $lastId - intdiv($total, 200) + 1;                     // last 0.5 % missing
        $export2 = SyntheticIdentityExport::generated(24, $perFile, $firstId, $last, function (int $id, array $row) use ($missingFrom) {
            if ($id >= $missingFrom) {
                return null;
            }

            return $id % 100 === 0 ? array_merge($row, ['city' => '99']) : $row;   // 1 % CHANGED
        });
        $this->appendNewFile($export2, $lastId + 1, intdiv($total, 200));       // 0.5 % NEW
        $m2 = $this->rehearse('release 2', $export2, null);

        $this->assertSame(intdiv($total, 200), $m2['counts']['new']);
        $this->assertSame(intdiv($total, 200), $m2['counts']['carried_forward']);
        $this->assertGreaterThan(0, $m2['counts']['changed']);
        $this->assertSame($total + intdiv($total, 200), $m2['counts']['expected_presence']);
    }

    /** @return array<string, mixed> */
    private function rehearse(string $label, SyntheticIdentityExport $export, ?int $expectedRows): array
    {
        $this->syntheticExport($export);
        $free = disk_free_space(sys_get_temp_dir());
        if ($free !== false && $free < max(2 * 1024 ** 3, $export->manifest['total_rows'] * self::BYTES_PER_ROW)) {
            $this->markTestSkipped(sprintf('Stopped before %s: only %.1f GB free.', $label, $free / 1024 ** 3));
        }
        $lsn = fn () => DB::selectOne('SELECT pg_current_wal_lsn() AS l')->l;
        $tempBytes = fn () => (int) DB::selectOne('SELECT temp_bytes AS t FROM pg_stat_database WHERE datname = current_database()')->t;
        $temp0 = $tempBytes();
        $walSince = fn (string $from) => (int) DB::selectOne('SELECT pg_wal_lsn_diff(pg_current_wal_lsn(), ?::pg_lsn) AS d', [$from])->d;
        $size = fn () => (int) DB::selectOne("SELECT coalesce(sum(pg_total_relation_size(c.oid)), 0) AS s FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname IN ('identity_ref', 'identity_ref_staging') AND c.relkind = 'r'")->s;
        gc_collect_cycles();
        memory_reset_peak_usage();
        $mem = memory_get_usage();
        $t = [];

        $w0 = $lsn();
        $s = microtime(true);
        $run = $this->stage($export, ['chunk_size' => 5000] + ($expectedRows === null ? [] : ['expected_rows' => $expectedRows]))['run_id'];
        $t['stage'] = microtime(true) - $s;
        $sizeStaged = $size();

        $s = microtime(true);
        $assessment = $this->releases()->assess($this->writerConnection(), $run, IdentityImportPolicy::approved(), 'famboook_test');
        $t['validate+compare'] = microtime(true) - $s;
        $this->assertTrue($assessment['publishable'], json_encode($assessment['fatal'] + $assessment['blocked']));

        $w1 = $lsn();
        $s = microtime(true);
        $prepared = $this->prepareRun($run, null, null, 5000);
        $t['prepare'] = microtime(true) - $s;
        foreach ($prepared['timings'] as $k => $v) {
            $t['  prepare.'.$k] = $v;
        }
        $walPrepare = $walSince($w1);

        $s = microtime(true);
        $this->verifyRelease($prepared['release_id']);
        $t['verify'] = microtime(true) - $s;

        $w2 = $lsn();
        $published = $this->publishRelease($prepared['release_id']);
        $walPublish = $walSince($w2);
        $t['activation'] = $published['activation_seconds'];
        $peak = memory_get_peak_usage() - $mem;

        fwrite(STDERR, sprintf("\n[P3 %s] staged %d rows; counts %s\n[P3 %s] seconds: %s\n[P3 %s] PHP peak growth %.1f MB; WAL prepare %.0f MB, activation+purge %.0f MB, total %.0f MB; temp files %.0f MB; size staged %.0f MB, after publish %.0f MB; disk free %.1f GB\n",
            $label, $prepared['counts']['staged'], json_encode($prepared['counts']), $label,
            json_encode(array_map(fn ($v) => round($v, 1), $t)), $label, $peak / 1048576,
            $walPrepare / 1048576, $walPublish / 1048576, $walSince($w0) / 1048576, ($tempBytes() - $temp0) / 1048576,
            $sizeStaged / 1048576, $size() / 1048576, disk_free_space(sys_get_temp_dir()) / 1024 ** 3));
        $this->assertLessThan(256 * 1024 * 1024, $peak);

        return $prepared;
    }

    private function appendNewFile(SyntheticIdentityExport $export, int $firstId, int $rows): void
    {
        $name = 't03maindata_part_00025.csv';
        $h = fopen($export->path($name), 'wb');
        fwrite($h, "\xEF\xBB\xBF".implode(',', IdentityManifest::COLUMNS)."\r\n");
        for ($i = 0; $i < $rows; $i++) {
            fwrite($h, SyntheticIdentityExport::line(SyntheticIdentityExport::row((string) ($firstId + $i)))."\r\n");
        }
        fclose($h);
        $export->manifest['files'][] = ['file' => $name, 'rows' => $rows, 'sha256' => hash_file('sha256', $export->path($name))];
        $export->manifest['total_rows'] += $rows;
        $export->writeManifest();
    }
}
