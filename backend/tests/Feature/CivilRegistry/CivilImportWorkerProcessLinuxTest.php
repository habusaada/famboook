<?php

namespace Tests\Feature\CivilRegistry;

use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Models\CivilImportWorkerHeartbeat;
use App\Models\User;
use App\Support\IdentityRef\IdentityImportSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\Process\Process;
use Tests\Support\CivilImport\CivilImportHarness;
use Tests\Support\IdentityRef\IdentityRefDatabase;
use Tests\Support\IdentityRef\IdentityRefRelease;
use Tests\Support\IdentityRef\IdentityRefStaging;
use Tests\Support\IdentityRef\SyntheticIdentityExport;
use Tests\TestCase;

/**
 * FB-CIVIL-01A-UI U4 — LINUX-ONLY checks of the real worker process (the
 * production target: systemd on Linux). Skipped elsewhere (Windows cannot
 * create symlinks without Developer Mode and has no POSIX signals).
 *
 * The worker runs as a SEPARATE OS process through backend/identity-import-worker,
 * with only its two throwaway logins in a minimal environment (as systemd's
 * EnvironmentFile would provide):
 * - SIGKILL in the middle of a staging: the run stays RUNNING and resumable,
 *   the import advisory lock dies with the session, the request becomes
 *   WORKER_LOST, and a new STAGE request resumes without duplicates;
 * - SIGTERM: the worker finishes its current request, then exits cleanly (STOPPED);
 * - a source replaced by a symlink after cataloguing is refused at execution.
 *
 * PostgreSQL (phpunit.pgsql.xml) and SYNTHETIC data only.
 */
#[RequiresOperatingSystemFamily('Linux')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
class CivilImportWorkerProcessLinuxTest extends TestCase
{
    use CivilImportHarness;
    use DatabaseTruncation;
    use IdentityRefDatabase;
    use IdentityRefRelease;
    use IdentityRefStaging;

    protected $exceptTables = ['clans', 'relationship_types'];

    private User $admin;

    /** @var list<Process> */
    private array $processes = [];

    protected function setUp(): void
    {
        if (! self::pgsqlTestDatabaseIsReachable()) {
            $this->markTestSkipped('PostgreSQL only — run with phpunit.pgsql.xml against a reachable *_test database.');
        }
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpCivilImport();
        config(['civil_import.worker.chunk_size' => 10]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('SUPER_ADMIN');
    }

    protected function tearDown(): void
    {
        if ($this->app === null) {
            parent::tearDown();

            return;
        }
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->signal(SIGKILL);
            }
        }
        $this->tearDownCivilImport();
        $this->tearDownIdentityRefStaging();
        $this->tearDownIdentityRef();
        parent::tearDown();
    }

    /** The real worker process, with a minimal environment holding only its own settings and logins. */
    private function spawnWorker(string $instance): Process
    {
        $control = config("database.connections.{$this->controlConnection}");
        $import = config("database.connections.{$this->importConnection}");
        $env = array_map(fn () => false, getenv()) + [];
        $env = array_merge($env, [
            'PATH' => (string) getenv('PATH'), 'APP_ENV' => 'local', 'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $control['host'], 'DB_PORT' => (string) $control['port'], 'DB_DATABASE' => $control['database'],
            'DB_USERNAME' => $control['username'], 'DB_PASSWORD' => $control['password'],
            'IDENTITY_REF_DB_USERNAME' => $import['username'], 'IDENTITY_REF_DB_PASSWORD' => $import['password'],
            'CIVIL_IMPORT_TARGET_DATABASE' => 'famboook_test', 'CIVIL_IMPORT_SOURCE_ROOT' => $this->sourceRoot, 'CIVIL_IMPORT_CHUNK_SIZE' => '10',
            'CIVIL_IMPORT_PUBLISH_ENABLED' => 'false',
        ]);
        $process = new Process([PHP_BINARY, 'identity-import-worker', 'famboook:identity-ref-worker', '--instance='.$instance], base_path(), $env);
        $process->setTimeout(300);
        $process->start();

        return $this->processes[] = $process;
    }

    /** Polls $condition (every 100 ms) for up to $seconds. */
    private function waitFor(callable $condition, int $seconds, string $what): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(100_000);
        }
        $this->fail("timed out waiting for {$what}");
    }

    private function catalogued(SyntheticIdentityExport $export, string $label): CivilImportSource
    {
        $this->placeSource($this->syntheticExport($export), $label);
        $this->workOnce();

        return CivilImportSource::query()->where('label', $label)->firstOrFail();
    }

    private function queueStage(CivilImportSource $source): string
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/civil-registry/imports', ['source_id' => $source->id])->assertStatus(202)->json('data.id');
    }

    public function test_a_killed_worker_leaves_a_resumable_run_and_releases_the_import_lock(): void
    {
        $source = $this->catalogued(SyntheticIdentityExport::generated(4, 300, 900_400_001), 'export-kill');
        $id = $this->queueStage($source);
        $worker = $this->spawnWorker('worker-kill');
        $this->waitFor(fn () => (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.file_chunks')->n >= 3, 120, 'committed chunks');

        $worker->signal(SIGKILL);
        $worker->wait();
        $request = CivilImportRequest::query()->findOrFail($id);
        $this->assertSame(CivilImportRequest::RUNNING, $request->status);
        $runId = DB::selectOne("SELECT id FROM identity_ref.import_runs WHERE status = 'RUNNING'")->id;
        $this->assertLessThan(1200, $this->stagedCount($runId));
        // The FBCIV01S session lock dies with the killed session.
        $this->waitFor(fn () => (int) DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory'
            AND ((classid::bigint << 32) | objid::bigint) = ?", [IdentityImportSession::LOCK_KEY])->n === 0, 30, 'the advisory lock to be released');

        // The next worker closes it WORKER_LOST; a new STAGE request resumes from the checkpoints.
        DB::table('civil_import_requests')->where('id', $id)->update(['heartbeat_at' => now()->subMinutes(30)]);
        $this->workOnce($this->worker(instance: 'worker-after-kill'));
        $this->assertSame('WORKER_LOST', CivilImportRequest::query()->find($id)->reason_code);
        $resume = $this->queueStage($source);
        $this->workOnce();
        $done = CivilImportRequest::query()->findOrFail($resume);
        $this->assertSame([CivilImportRequest::SUCCEEDED, true, $runId], [$done->status, $done->result['resumed'], $done->run_id]);
        $this->assertSame(1200, $this->stagedCount($runId));
        $this->assertSame(1200, (int) DB::selectOne('SELECT count(DISTINCT mainid) AS n FROM identity_ref_staging.rows')->n);
    }

    public function test_sigterm_lets_the_current_request_finish_then_stops_cleanly(): void
    {
        $source = $this->catalogued(SyntheticIdentityExport::generated(2, 200, 900_500_001), 'export-term');
        $id = $this->queueStage($source);
        $worker = $this->spawnWorker('worker-term');
        $this->waitFor(fn () => (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.file_chunks')->n >= 1, 120, 'staging to start');

        $worker->signal(SIGTERM);
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        $this->assertSame(CivilImportRequest::SUCCEEDED, CivilImportRequest::query()->find($id)->status);
        $this->assertSame('STOPPED', CivilImportWorkerHeartbeat::query()->find('worker-term')->state);
    }

    public function test_a_source_replaced_by_a_symlink_after_cataloguing_is_refused(): void
    {
        $source = $this->catalogued(SyntheticIdentityExport::generated(2, 4, 900_600_001), 'export-swap');
        $real = $this->sourceRoot.'/export-swap';
        $elsewhere = sys_get_temp_dir().'/famboook-u4-elsewhere-'.bin2hex(random_bytes(4));
        rename($real, $elsewhere);
        symlink($elsewhere, $real);
        try {
            $id = $this->queueStage($source);   // still catalogued as ready
            $this->workOnce();
            // The worker re-catalogues first (the symlink is not a source any more), then refuses the request.
            $this->assertSame([CivilImportRequest::FAILED, 'SOURCE_UNAVAILABLE'],
                [CivilImportRequest::query()->find($id)->status, CivilImportRequest::query()->find($id)->reason_code]);
            $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref.import_runs')->n);
        } finally {
            unlink($real);
            self::removeTree($elsewhere);
        }
    }

    private static function pgsqlTestDatabaseIsReachable(): bool
    {
        $env = static fn (string $key): ?string => ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) ?: null;
        $database = (string) $env('DB_DATABASE');
        if ($env('DB_CONNECTION') !== 'pgsql' || ! str_ends_with($database, '_test')) {
            return false;
        }
        try {
            new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=3', $env('DB_HOST') ?? '127.0.0.1', $env('DB_PORT') ?? '5432', $database),
                $env('DB_USERNAME'), $env('DB_PASSWORD'));

            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
