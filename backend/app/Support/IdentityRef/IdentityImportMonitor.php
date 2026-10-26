<?php

namespace App\Support\IdentityRef;

use App\Exceptions\CivilImportMonitorUnavailableException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Metadata-only reads of the identity reference import state
 * (FB-CIVIL-01A-UI U1; docs/adr/FB-CIVIL-01A-UI-IMPORT-MANAGEMENT.md §5).
 *
 * The ONLY class that uses the `identity_ref_monitor` connection:
 * - the login and password come at run time from the protected file
 *   config('civil_import.monitor_credentials_file') — never .env, never the
 *   cached configuration (D-UI-9);
 * - the session runs SET ROLE famboook_identity_monitor and refuses a
 *   superuser login, a login that can act as the identity writer or owner,
 *   and silent reconnects (which would drop SET ROLE);
 * - it reads the identity_ref_monitor views only, which hold no identity
 *   value; file names come only from the *Locations methods, called solely
 *   for holders of civil-import.locations.view (D-UI-8).
 *
 * The application's general database role is never used here and never
 * granted anything (D-UI-9).
 */
final class IdentityImportMonitor
{
    public const CONNECTION = 'identity_ref_monitor';

    public const ROLE = 'famboook_identity_monitor';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private ?Connection $db = null;

    // ---------------------------------------------------------------- reads

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $db = $this->db();
        $active = $db->selectOne('SELECT a.release_id, a.activated_at, r.record_count, r.run_id, r.published_at
            FROM identity_ref_monitor.active_release a JOIN identity_ref_monitor.releases r ON r.id = a.release_id');
        $current = $this->runsQuery()->where('r.status', 'RUNNING')->orderByDesc('r.started_at')->first();

        return [
            'active_release' => $active === null ? null : [
                'id' => (int) $active->release_id,
                'record_count' => $active->record_count === null ? null : (int) $active->record_count,
                'published_at' => self::iso($active->published_at),
                'run_id' => $active->run_id,
            ],
            'current_run' => $current === null ? null : [
                'id' => $current->id,
                'phase' => self::phase($current),
                'started_at' => self::iso($current->started_at),
            ],
            'worker' => ['state' => 'NOT_INSTALLED', 'last_seen_at' => null],   // U2
            'publication_enabled' => config('civil_import.publish_enabled') === true,
            'pending_publication_requests' => 0,                                  // U3
        ];
    }

    public function runs(?string $status, int $page, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->runsQuery()->orderByDesc('r.started_at')->orderByDesc('r.id');
        if ($status !== null) {
            $query->where('r.status', $status);
        }

        return $query->paginate($perPage, ['*'], 'page', $page)->through(fn (object $r) => self::runSummary($r));
    }

    /** @return array<string, mixed>|null */
    public function run(string $runId): ?array
    {
        if (preg_match(self::UUID, $runId) !== 1) {
            return null;
        }
        $row = $this->runsQuery()->where('r.id', $runId)->first();
        if ($row === null) {
            return null;
        }
        $files = $this->db()->table('identity_ref_monitor.files')->where('run_id', $runId)->orderBy('file_no')->get()
            ->map(fn (object $f) => [
                'file_no' => (int) $f->file_no,
                'status' => $f->status,
                'expected_rows' => (int) $f->expected_rows,
                'loaded_rows' => (int) $f->loaded_rows,
                'checksum_verified' => (bool) $f->checksum_verified,
                'chunks' => (int) $f->chunks,
                'last_chunk_at' => self::iso($f->last_chunk_at),
            ])->all();

        return self::runSummary($row) + [
            'source_table' => $row->source_table,
            'counters' => [
                'new_records' => (int) $row->new_records,
                'new_versions' => (int) $row->new_versions,
                'unchanged' => (int) $row->unchanged,
                'carried_forward' => (int) $row->carried_forward,
            ],
            'files' => $files,
            'release' => $row->release_id === null ? null : [
                'id' => (int) $row->release_id,
                'status' => $row->release_status,
                'base_release_id' => $row->base_release_id === null ? null : (int) $row->base_release_id,
                'preparation_status' => $row->preparation_status,
            ],
            'allowed_actions' => [],     // U1 is read-only
            'active_request' => null,    // U2
        ];
    }

    /** @return list<array{code: string, column: ?string, rows: int}>|null */
    public function issueCounts(string $runId): ?array
    {
        if (! $this->runExists($runId)) {
            return null;
        }

        return $this->db()->table('identity_ref_monitor.issue_counts')->where('run_id', $runId)->orderBy('code')->orderBy('column_name')->get()
            ->map(fn (object $i) => ['code' => $i->code, 'column' => $i->column_name, 'rows' => (int) $i->n])->values()->all();
    }

    /** civil-import.locations.view only. @return list<array{file_no: int, file_name: string}>|null */
    public function fileLocations(string $runId): ?array
    {
        if (! $this->runExists($runId)) {
            return null;
        }

        return $this->db()->table('identity_ref_monitor.file_locations')->where('run_id', $runId)->orderBy('file_no')->get()
            ->map(fn (object $f) => ['file_no' => (int) $f->file_no, 'file_name' => $f->file_name])->values()->all();
    }

    /** civil-import.locations.view only. */
    public function issueLocations(string $runId, ?string $code, int $page, int $perPage = 50): ?LengthAwarePaginator
    {
        if (! $this->runExists($runId)) {
            return null;
        }
        $query = $this->db()->table('identity_ref_monitor.issue_locations')->where('run_id', $runId)->orderBy('issue_no');
        if ($code !== null) {
            $query->where('code', $code);
        }

        return $query->paginate($perPage, ['file_name', 'line_number', 'code', 'column_name'], 'page', $page)
            ->through(fn (object $i) => [
                'file_name' => $i->file_name,
                'line_number' => $i->line_number === null ? null : (int) $i->line_number,
                'code' => $i->code,
                'column' => $i->column_name,
            ]);
    }

    public function releases(int $page, int $perPage = 25): LengthAwarePaginator
    {
        return $this->releasesQuery()->orderByDesc('d.id')->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (object $r) => self::releaseArray($r));
    }

    /** @return array<string, mixed>|null */
    public function release(int $releaseId): ?array
    {
        $row = $this->releasesQuery()->where('d.id', $releaseId)->first();

        return $row === null ? null : self::releaseArray($row);
    }

    // ---------------------------------------------------------------- phase (ADR §3.1)

    /**
     * The UI phase derived from the run and its release. U1 has no execution
     * requests yet, so an incomplete staging is STAGING (running or
     * interrupted alike); approval phases arrive with U3.
     */
    public static function phase(object $r): string
    {
        return match (true) {
            $r->status === 'REJECTED' => 'REJECTED',
            $r->status === 'FAILED' && $r->error_code === 'ABORTED' => 'ABORTED',
            $r->status === 'FAILED' => 'FAILED',
            $r->status === 'SUCCEEDED' => 'PUBLISHED',
            $r->status === 'NOTHING_TO_DO' => 'NOTHING_TO_DO',
            (int) $r->files_total === 0 || (int) $r->files_staged < (int) $r->files_total => 'STAGING',
            $r->release_id === null => 'STAGED',
            $r->preparation_status === 'VERIFIED' => 'VERIFIED',
            $r->preparation_status === 'PREPARED' => 'PREPARED',
            default => 'PREPARING',
        };
    }

    // ---------------------------------------------------------------- internals

    private function runsQuery(): Builder
    {
        return $this->db()->table('identity_ref_monitor.runs as r')
            ->leftJoin('identity_ref_monitor.releases as d', 'd.run_id', '=', 'r.id')
            ->leftJoin('identity_ref_monitor.preparations as p', 'p.release_id', '=', 'd.id')
            ->select('r.*', 'd.id as release_id', 'd.status as release_status', 'd.base_release_id', 'p.status as preparation_status')
            ->selectRaw('(SELECT count(*) FROM identity_ref_monitor.files f WHERE f.run_id = r.id) AS files_total')
            ->selectRaw("(SELECT count(*) FROM identity_ref_monitor.files f WHERE f.run_id = r.id AND f.status = 'STAGED') AS files_staged");
    }

    private function releasesQuery(): Builder
    {
        return $this->db()->table('identity_ref_monitor.releases as d')
            ->leftJoin('identity_ref_monitor.preparations as p', 'p.release_id', '=', 'd.id')
            ->leftJoin('identity_ref_monitor.active_release as a', 'a.release_id', '=', 'd.id')
            ->select('d.*', 'a.release_id as active_id', 'p.status as preparation_status', 'p.policy_id', 'p.comparison_policy',
                'p.blank_field_policy', 'p.quarantine_max_rows', 'p.staged_rows', 'p.new_count', 'p.changed_count', 'p.unchanged_count',
                'p.conflict_count', 'p.carried_forward_count', 'p.blank_over_value_count', 'p.expected_presence', 'p.warnings',
                'p.prepared_at', 'p.verified_at');
    }

    private function runExists(string $runId): bool
    {
        return preg_match(self::UUID, $runId) === 1
            && $this->db()->table('identity_ref_monitor.runs')->where('id', $runId)->exists();
    }

    /** @return array<string, mixed> */
    private static function runSummary(object $r): array
    {
        return [
            'id' => $r->id,
            'phase' => self::phase($r),
            'run_status' => $r->status,
            'error_code' => $r->error_code,
            'expected_rows' => (int) $r->expected_rows,
            'rows_loaded' => (int) $r->rows_loaded,
            'issues' => (int) $r->issues,
            'file_count' => (int) $r->files_total,
            'release_id' => $r->release_id === null ? null : (int) $r->release_id,
            'requested_by' => null,     // U2: runs started from the UI
            'started_at' => self::iso($r->started_at),
            'finished_at' => self::iso($r->finished_at),
        ];
    }

    /** @return array<string, mixed> */
    private static function releaseArray(object $r): array
    {
        $warnings = $r->warnings === null ? [] : (array) json_decode($r->warnings, true);
        ksort($warnings);

        return [
            'id' => (int) $r->id,
            'status' => $r->status,
            'run_id' => $r->run_id,
            'base_release_id' => $r->base_release_id === null ? null : (int) $r->base_release_id,
            'record_count' => $r->record_count === null ? null : (int) $r->record_count,
            'is_active' => $r->active_id !== null,
            'preparation' => $r->preparation_status === null ? null : [
                'status' => $r->preparation_status,
                'policy_id' => $r->policy_id,
                'comparison_policy' => $r->comparison_policy,
                'blank_field_policy' => $r->blank_field_policy,
                'quarantine' => match (true) {
                    $r->quarantine_max_rows === null => 'UNRESOLVED',
                    (int) $r->quarantine_max_rows === 0 => 'ZERO_UNRESOLVED_QUARANTINE',
                    default => 'THRESHOLD',
                },
                'counts' => [
                    'staged' => (int) $r->staged_rows,
                    'new' => (int) $r->new_count,
                    'changed' => (int) $r->changed_count,
                    'unchanged' => (int) $r->unchanged_count,
                    'conflict' => (int) $r->conflict_count,
                    'carried_forward' => (int) $r->carried_forward_count,
                    'blank_over_value' => (int) $r->blank_over_value_count,
                    'expected_presence' => (int) $r->expected_presence,
                ],
                'warnings' => array_map('intval', $warnings),
                'prepared_at' => self::iso($r->prepared_at),
                'verified_at' => self::iso($r->verified_at),
            ],
            'created_at' => self::iso($r->created_at),
            'published_at' => self::iso($r->published_at),
            'abandoned_at' => self::iso($r->abandoned_at),
        ];
    }

    private static function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : Carbon::parse($timestamp)->toIso8601String();
    }

    /** The monitoring session, opened once per request. */
    private function db(): Connection
    {
        if ($this->db !== null) {
            return $this->db;
        }
        if (config('database.connections.'.self::CONNECTION.'.username') === null) {
            $this->loadCredentials();
        }
        try {
            $db = DB::connection(self::CONNECTION);
            $db->setReconnector(static function (): void {
                throw new RuntimeException('IDENTITY_REF_MONITOR_CONNECTION_LOST');
            });
            $db->unprepared('SET ROLE '.self::ROLE);
            $me = $db->selectOne("SELECT current_user AS role, s.rolsuper AS su,
                    pg_has_role(session_user, 'famboook_identity_writer', 'MEMBER') AS writer,
                    pg_has_role(session_user, 'famboook_identity_owner', 'MEMBER') AS owner
                FROM pg_roles s WHERE s.rolname = session_user");
        } catch (Throwable $e) {
            Log::warning('identity_ref.monitor.unavailable', ['error' => IdentityRefErrorRedactor::redact($e)]);
            throw new CivilImportMonitorUnavailableException(CivilImportMonitorUnavailableException::UNAVAILABLE);
        }
        if ($me === null || $me->role !== self::ROLE || $me->su || $me->writer || $me->owner) {
            DB::purge(self::CONNECTION);
            Log::warning('identity_ref.monitor.refused_login', ['reason' => 'LOGIN_NOT_METADATA_ONLY']);
            throw new CivilImportMonitorUnavailableException(CivilImportMonitorUnavailableException::UNAVAILABLE);
        }

        return $this->db = $db;
    }

    /** Credentials from the protected file (D-UI-9); set at run time only. */
    private function loadCredentials(): void
    {
        $path = (string) config('civil_import.monitor_credentials_file');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new CivilImportMonitorUnavailableException(CivilImportMonitorUnavailableException::NOT_CONFIGURED);
        }
        $credentials = (static fn (string $file) => require $file)($path);
        if (! is_array($credentials) || ! is_string($credentials['username'] ?? null) || ! is_string($credentials['password'] ?? null)
            || preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $credentials['username']) !== 1) {
            throw new CivilImportMonitorUnavailableException(CivilImportMonitorUnavailableException::NOT_CONFIGURED);
        }
        config([
            'database.connections.'.self::CONNECTION.'.username' => $credentials['username'],
            'database.connections.'.self::CONNECTION.'.password' => $credentials['password'],
        ]);
        DB::purge(self::CONNECTION);
    }
}
