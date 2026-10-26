<?php

namespace Tests\Support\CivilImport;

use App\Actions\PublishIdentityReleaseAction;
use App\Actions\StageIdentityImportAction;
use App\Support\CivilImport\CivilImportSourceCatalogue;
use App\Support\CivilImport\IdentityImportWorker;
use App\Support\IdentityRef\IdentitySourceVerifier;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\IdentityRef\SyntheticIdentityExport;

/**
 * FB-CIVIL-01A-UI U2 PostgreSQL harness, on top of IdentityRefDatabase and
 * IdentityRefStaging. Applies 07-control-plane.dba.sql after the migrations
 * and runs the worker exactly as in production — with REAL, separate,
 * throwaway LOGIN sessions:
 * - the control plane: a login that is a member of famboook_civil_control only,
 *   set as the default connection while the worker runs;
 * - the import: a login that is a member of famboook_identity_writer only;
 * - the API's monitor: a login that is a member of famboook_identity_monitor only.
 * Sources live in a temporary source root. SYNTHETIC data only.
 */
trait CivilImportHarness
{
    protected string $sourceRoot;

    protected string $controlConnection;

    protected string $importConnection;

    protected function setUpCivilImport(): void
    {
        $this->setUpIdentityRef(withMonitor: true);
        DB::unprepared($this->sqlFile('07-control-plane.dba.sql'));

        $monitor = $this->loginConnection('famboook_identity_monitor')->getConfig();
        config(['database.connections.identity_ref_monitor.username' => $monitor['username'],
            'database.connections.identity_ref_monitor.password' => $monitor['password']]);
        DB::purge('identity_ref_monitor');
        $this->controlConnection = $this->loginConnection('famboook_civil_control')->getName();
        $this->importConnection = $this->writerConnection()->getName();

        $this->sourceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'famboook-u2-root-'.bin2hex(random_bytes(5));
        mkdir($this->sourceRoot, 0700);
        config([
            'civil_import.worker.source_root' => $this->sourceRoot,
            'civil_import.worker.target' => 'famboook_test',
            'civil_import.worker.chunk_size' => 3,
            'civil_import.worker.heartbeat_seconds' => 0,
        ]);
        putenv('IDENTITY_REF_DB_USERNAME');
    }

    protected function tearDownCivilImport(): void
    {
        DB::setDefaultConnection('pgsql');
        DB::purge('identity_ref_monitor');
        self::truncateCivilImportTables();
        if (isset($this->sourceRoot) && is_dir($this->sourceRoot)) {
            self::removeTree($this->sourceRoot);
        }
    }

    /**
     * DatabaseTruncation empties tables BEFORE each test; the committed
     * control-plane rows of these tests must not leak into later
     * RefreshDatabase tests of the same run.
     */
    public static function truncateCivilImportTables(): void
    {
        DB::statement('TRUNCATE civil_import_requests, civil_import_worker_heartbeats, civil_import_sources, civil_import_audit_events');
    }

    /** A worker whose import login is $importConnection (default: the writer login). */
    protected function worker(?string $importConnection = null, string $instance = 'worker-test'): IdentityImportWorker
    {
        return new IdentityImportWorker(app(StageIdentityImportAction::class), app(PublishIdentityReleaseAction::class),
            app(IdentitySourceVerifier::class), CivilImportSourceCatalogue::fromConfig(), $instance, $importConnection ?? $this->importConnection);
    }

    /**
     * Runs $fn with the default connection switched to $connection (the
     * control login by default), as in the worker process.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    protected function asWorker(Closure $fn, ?string $connection = null): mixed
    {
        DB::setDefaultConnection($connection ?? $this->controlConnection);
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection('pgsql');
        }
    }

    /** Boots a worker and runs one iteration as the control login. */
    protected function workOnce(?IdentityImportWorker $worker = null): mixed
    {
        $worker ??= $this->worker();

        return $this->asWorker(function () use ($worker) {
            $worker->boot();

            return $worker->runOnce();
        });
    }

    /** Copies a synthetic export into the source root as directory $label. */
    protected function placeSource(SyntheticIdentityExport $export, string $label): string
    {
        $dir = $this->sourceRoot.DIRECTORY_SEPARATOR.$label;
        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException('cannot create the source directory');
        }
        foreach (scandir($export->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                copy($export->path($entry), $dir.DIRECTORY_SEPARATOR.$entry);
            }
        }

        return $dir;
    }

    protected static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                @unlink($path) || @rmdir($path);
            } elseif (is_dir($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
