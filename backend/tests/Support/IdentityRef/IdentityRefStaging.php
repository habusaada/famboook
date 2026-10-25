<?php

namespace Tests\Support\IdentityRef;

use App\Actions\StageIdentityImportAction;
use App\Exceptions\IdentityImportException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * FB-CIVIL-01A-P2 PostgreSQL helpers, on top of IdentityRefDatabase.
 *
 * The loader runs in REAL, separate LOGIN sessions of throwaway roles (random
 * password per run, dropped afterwards): a login that is only a member of
 * famboook_identity_writer, or only a member of famboook_app. No loader test
 * runs as the cluster administrator. SYNTHETIC data only.
 */
trait IdentityRefStaging
{
    /** @var list<string> */
    private array $loginRoles = [];

    /** @var list<SyntheticIdentityExport> */
    private array $syntheticExports = [];

    protected function tearDownIdentityRefStaging(): void
    {
        foreach (array_keys(config('database.connections')) as $name) {
            if (str_starts_with($name, 'identity_ref_p2_')) {
                DB::purge($name);
            }
        }
        foreach ($this->loginRoles as $role) {
            DB::unprepared("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename = '{$role}'");
            DB::unprepared("DROP ROLE IF EXISTS {$role}");
        }
        $this->loginRoles = [];
        foreach ($this->syntheticExports as $export) {
            $export->delete();
        }
        $this->syntheticExports = [];
    }

    /** A fresh Laravel connection logged in as a throwaway role that is a member of $memberOf only. */
    protected function loginConnection(string $memberOf): Connection
    {
        $role = 'famboook_p2test_'.bin2hex(random_bytes(4));
        $password = bin2hex(random_bytes(16));
        DB::unprepared("CREATE ROLE {$role} LOGIN NOINHERIT PASSWORD '{$password}' IN ROLE {$memberOf}");
        $this->loginRoles[] = $role;

        $name = 'identity_ref_p2_'.$role;
        config(["database.connections.{$name}" => array_merge(config('database.connections.pgsql'), [
            'username' => $role, 'password' => $password,
        ])]);

        return DB::connection($name);
    }

    protected function writerConnection(): Connection
    {
        return $this->loginConnection(self::WRITER);
    }

    protected function syntheticExport(SyntheticIdentityExport $export): SyntheticIdentityExport
    {
        return $this->syntheticExports[] = $export;
    }

    /** @return array<string, mixed> */
    protected function stage(SyntheticIdentityExport $export, array $options = [], ?RecordingLogger $logger = null, ?Closure $hook = null, ?Connection $db = null): array
    {
        return app(StageIdentityImportAction::class)->handle($db ?? $this->writerConnection(), $export->dir,
            $options + ['target' => 'famboook_test', 'chunk_size' => 3], $logger, $hook);
    }

    /** Runs $fn and returns the IdentityImportException it throws. */
    protected function refused(Closure $fn): IdentityImportException
    {
        try {
            $fn();
        } catch (IdentityImportException $e) {
            return $e;
        }
        $this->fail('expected an IdentityImportException');
    }

    protected function stagedCount(?string $runId = null): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n FROM identity_ref_staging.rows r JOIN identity_ref.import_files f ON f.id = r.file_id
            WHERE ?::uuid IS NULL OR f.run_id = ?::uuid', [$runId, $runId])->n;
    }

    /** @return object{status: string, rows_loaded: int, issues: int, error_code: ?string} */
    protected function importRun(string $runId): object
    {
        return DB::selectOne('SELECT status, rows_loaded, issues, error_code FROM identity_ref.import_runs WHERE id = ?', [$runId]);
    }

    /** Staged payload of one identity, decoded (read as the administrator). */
    protected function stagedPayload(string $mainid): array
    {
        return json_decode(DB::selectOne('SELECT source_payload FROM identity_ref_staging.rows WHERE mainid = ?', [$mainid])->source_payload, true);
    }
}
