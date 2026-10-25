<?php

namespace Tests\Feature\IdentityRef;

use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-P2 — opt-in volume rehearsal with SYNTHETIC data shaped like
 * the approved contract: 24 files, 2,399,891 rows (23 × 100,000 + 99,891).
 * Skipped unless IDENTITY_REF_PERF=1; needs ~3 GB of free disk. Prints the
 * timings to STDERR (no values).
 *
 *   IDENTITY_REF_PERF=1 php vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/IdentityRef/IdentityRefStagingPerformancePostgresTest.php
 */
class IdentityRefStagingPerformancePostgresTest extends TestCase
{
    use IdentityRefDatabase;
    use IdentityRefStaging;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('IDENTITY_REF_PERF') !== '1') {
            $this->markTestSkipped('Volume rehearsal: set IDENTITY_REF_PERF=1 to run.');
        }
        $this->setUpIdentityRef();
    }

    protected function tearDown(): void
    {
        if (getenv('IDENTITY_REF_PERF') === '1') {
            $this->tearDownIdentityRefStaging();
            $this->tearDownIdentityRef();
        }
        parent::tearDown();
    }

    public function test_full_contract_volume_stages_in_bounded_memory(): void
    {
        $t0 = microtime(true);
        $export = $this->syntheticExport(SyntheticIdentityExport::generated(24, 100_000, 100_000_001, 99_891));
        $bytes = array_sum(array_map(fn ($f) => filesize($export->path($f['file'])), $export->manifest['files']));
        $generated = microtime(true) - $t0;

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $t1 = microtime(true);
        $report = $this->stage($export, ['expected_files' => 24, 'expected_rows' => 2_399_891, 'chunk_size' => 5000]);
        $staged = microtime(true) - $t1;
        $peak = memory_get_peak_usage() - $before;

        $this->assertSame(2_399_891, $report['rows_staged']);
        $this->assertSame(2_399_891, $this->stagedCount());
        $size = DB::selectOne("SELECT pg_total_relation_size('identity_ref_staging.rows') AS r, pg_total_relation_size('identity_ref_staging.file_chunks') AS c");
        fwrite(STDERR, sprintf(
            "\n[P2 volume] generated %.0f MB of synthetic CSV in %.0f s\n[P2 volume] validate + stage 2,399,891 rows: %.0f s (%.0f rows/s), PHP peak growth %.1f MB\n[P2 volume] staging size: rows %.0f MB, checkpoints %.1f MB\n",
            $bytes / 1048576, $generated, $staged, 2_399_891 / $staged, $peak / 1048576, $size->r / 1048576, $size->c / 1048576,
        ));
        $this->assertLessThan(128 * 1024 * 1024, $peak);
    }
}
