<?php

namespace App\Support\CivilImport;

use App\Actions\PublishIdentityReleaseAction;
use App\Actions\StageIdentityImportAction;
use App\Exceptions\IdentityImportException;
use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Models\CivilImportWorkerHeartbeat;
use App\Support\IdentityRef\IdentityImportPolicy;
use App\Support\IdentityRef\IdentityRefErrorRedactor;
use App\Support\IdentityRef\IdentityRefProductionGate;
use App\Support\IdentityRef\IdentitySourceVerifier;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The civil identity import worker (FB-CIVIL-01A-UI U2, ADR §4, D-UI-5).
 * Runs ONLY in the dedicated worker process (identity-import-worker), never
 * from HTTP (S-1). It executes the requests the web application recorded,
 * one at a time, through the FB-CIVIL-01A actions — which keep every engine
 * guarantee: SET ROLE famboook_identity_writer, the named target, the
 * FBCIV01S advisory lock, resumable staging and preparation, no publication.
 *
 * Two separate logins (decision O-1):
 * - the CONTROL connection (the default connection in the worker process):
 *   famboook_civil_control_login → SET ROLE famboook_civil_control; it can
 *   only read and update requests, keep the catalogue and heartbeats and
 *   append audit events (07-control-plane.dba.sql);
 * - the IMPORT connection (identity_ref_import): the FB-CIVIL-01A import
 *   login, from the process environment at run time.
 * Each is refused when it is a superuser or shares the other's roles.
 *
 * Concurrency: a request is claimed with FOR UPDATE SKIP LOCKED, so two
 * workers never run the same request; the engine's advisory lock serializes
 * identity operations with any operator shell. A RUNNING request whose
 * heartbeat stopped is closed WORKER_LOST at start-up; its FB-CIVIL-01A
 * operation stays resumable by a new request. Cancellation of a RUNNING
 * request takes effect at the next committed chunk (or file).
 *
 * PUBLICATION IS NOT IMPLEMENTED: no code path here calls publish().
 */
final class IdentityImportWorker
{
    public const CONTROL_ROLE = 'famboook_civil_control';

    private CivilImportRequest $current;

    private float $lastBeat = 0.0;

    private bool $cancelling = false;

    /** @var ?Closure(string, array<string, mixed>): void test seam, called at every engine hook */
    private ?Closure $testHook = null;

    public function __construct(
        private readonly StageIdentityImportAction $stager,
        private readonly PublishIdentityReleaseAction $releases,
        private readonly IdentitySourceVerifier $verifier,
        private readonly CivilImportSourceCatalogue $catalogue,
        private readonly string $instance,
        private readonly string $importConnection = 'identity_ref_import',
    ) {}

    /** @param ?Closure(string, array<string, mixed>): void $hook */
    public function withTestHook(?Closure $hook): self
    {
        $this->testHook = $hook;

        return $this;
    }

    // ---------------------------------------------------------------- lifecycle

    /**
     * Verifies the control session (the default connection) and closes the
     * requests a previous worker left RUNNING.
     *
     * @throws CivilImportWorkerException
     */
    public function boot(): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'pgsql') {
            throw new CivilImportWorkerException('CONTROL_NOT_POSTGRESQL');
        }
        $db->setReconnector(static function (): void {
            throw new RuntimeException('CIVIL_IMPORT_CONTROL_CONNECTION_LOST');
        });
        try {
            $db->unprepared('SET ROLE '.self::CONTROL_ROLE);
        } catch (Throwable) {
            throw new CivilImportWorkerException('CONTROL_LOGIN_REFUSED');
        }
        $session = self::membership($db);
        if ($session['current'] !== self::CONTROL_ROLE || $session['superuser']
            || array_filter($session['roles'], fn (string $r) => str_starts_with($r, 'famboook_identity_')) !== []) {
            throw new CivilImportWorkerException('CONTROL_LOGIN_NOT_ISOLATED');
        }
        $this->heartbeat('IDLE', null);
        $this->recoverStale();
    }

    /**
     * One iteration: heartbeat, catalogue, then claim and execute at most one
     * request. Returns the request executed, if any.
     */
    public function runOnce(): ?CivilImportRequest
    {
        $this->heartbeat('IDLE', null);
        try {
            $this->catalogue->scan();
        } catch (Throwable $e) {
            Log::warning('civil_import.worker.catalogue_failed', ['error' => self::redact($e)]);
        }
        $request = $this->claim();
        if ($request === null) {
            return null;
        }
        $this->execute($request);
        $this->heartbeat('IDLE', null);

        return $request->refresh();
    }

    public function stop(): void
    {
        $this->heartbeat('STOPPED', null);
    }

    /** Closes RUNNING requests without a heartbeat for the configured time as WORKER_LOST. */
    public function recoverStale(): int
    {
        $limit = Carbon::now()->subMinutes((int) config('civil_import.worker.stale_after_minutes', 10));
        $stale = CivilImportRequest::query()->where('status', CivilImportRequest::RUNNING)
            ->where(fn ($q) => $q->where('heartbeat_at', '<', $limit)->orWhere(fn ($q) => $q->whereNull('heartbeat_at')->where('started_at', '<', $limit)))
            ->get();
        foreach ($stale as $request) {
            $this->finish($request, CivilImportRequest::FAILED, CivilImportReason::WORKER_LOST, []);
        }

        return $stale->count();
    }

    /** Claims the oldest QUEUED request (FOR UPDATE SKIP LOCKED): never the same request in two workers. */
    public function claim(): ?CivilImportRequest
    {
        $request = DB::transaction(function () {
            $row = DB::selectOne("SELECT id FROM civil_import_requests WHERE status = 'QUEUED' ORDER BY queued_at, id LIMIT 1 FOR UPDATE SKIP LOCKED");
            if ($row === null) {
                return null;
            }
            $request = CivilImportRequest::query()->findOrFail($row->id);
            $now = Carbon::now();
            $request->forceFill(['status' => CivilImportRequest::RUNNING, 'started_at' => $now, 'heartbeat_at' => $now,
                'attempts' => $request->attempts + 1, 'worker_instance' => $this->instance])->save();

            return $request;
        });
        if ($request !== null) {
            CivilImportAudit::record('REQUEST_STARTED', null, $request->run_id, $request->release_id,
                details: ['type' => $request->type, 'count' => $request->attempts], requestId: $request->id);
        }

        return $request;
    }

    // ---------------------------------------------------------------- execution

    private function execute(CivilImportRequest $request): void
    {
        $this->current = $request;
        $this->cancelling = false;
        $this->lastBeat = microtime(true);
        $this->heartbeat('BUSY', $request->id);
        $result = [];
        try {
            if (! IdentityRefProductionGate::allows()) {
                throw new CivilImportSourceException(CivilImportReason::NOT_APPROVED);
            }
            $target = (string) config('civil_import.worker.target');
            if ($target === '') {
                throw new CivilImportSourceException(CivilImportReason::TARGET_NOT_CONFIGURED);
            }
            $result = match ($request->type) {
                CivilImportRequest::VALIDATE_SOURCE => $this->validateSource($request),
                CivilImportRequest::STAGE => $this->stage($request, $target),
                CivilImportRequest::ABORT => $this->abort($request, $target),
                CivilImportRequest::ASSESS => $this->releases->assess($this->importDb(), (string) $request->run_id, self::policy(), $target),
                CivilImportRequest::PREPARE => $this->releases->prepare($this->importDb(), (string) $request->run_id, self::policy(), $target,
                    max(1, (int) config('civil_import.worker.chunk_size', 5000)), $this->logger(), $this->hook()),
                CivilImportRequest::VERIFY => $this->releases->verify($this->importDb(), (int) $request->release_id, $target, $this->logger()),
                default => throw new CivilImportSourceException(CivilImportReason::UNSUPPORTED_REQUEST),
            };
            $this->finish($request, CivilImportRequest::SUCCEEDED, null, $result);
        } catch (CivilImportCancelled) {
            $this->finish($request, CivilImportRequest::CANCELLED, CivilImportReason::CANCELLED_BY_OPERATOR, $result);
        } catch (IdentityImportException $e) {
            $outcome = ['run_id' => $e->runId, 'release_id' => $e->releaseId, 'problems' => CivilImportResult::problemCodes($e->problems)];
            $this->cancelling
                ? $this->finish($request, CivilImportRequest::CANCELLED, CivilImportReason::CANCELLED_BY_OPERATOR, $outcome)
                : $this->finish($request, CivilImportRequest::FAILED, $e->reason, $outcome);
        } catch (CivilImportSourceException $e) {
            $this->finish($request, CivilImportRequest::FAILED, $e->reason, $result + ['problems' => $e->problems]);
        } catch (Throwable $e) {
            Log::error('civil_import.worker.request_failed', ['request_id' => $request->id, 'error' => self::redact($e)]);
            $this->finish($request, CivilImportRequest::FAILED, CivilImportReason::INTERNAL_ERROR, []);
        } finally {
            // Drop the import session (and its SET ROLE) — never the control session, even if misconfigured as the same.
            if ($this->importConnection !== DB::getDefaultConnection()) {
                DB::purge($this->importConnection);
            }
        }
    }

    /**
     * Full source validation without any database write: the manifest (as
     * catalogued, unchanged) and every file — BOM, header, structure, row
     * count and SHA-256 — file by file, cancellable between files.
     *
     * @return array<string, mixed>
     */
    private function validateSource(CivilImportRequest $request): array
    {
        $manifest = $this->catalogue->manifest($this->source($request));
        $problems = [];
        foreach ($manifest->files as $file) {
            $this->beat();
            $problem = $this->verifier->verifyFile($manifest, $file);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        $result = ['manifest' => 'VALID', 'files' => count($manifest->files), 'total_rows' => $manifest->totalRows,
            'problems' => CivilImportResult::problemCodes($problems)];
        if ($problems !== []) {
            throw new CivilImportSourceException(IdentityImportException::SOURCE_INVALID, $result['problems']);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function stage(CivilImportRequest $request, string $target): array
    {
        $source = $this->source($request);
        $manifest = $this->catalogue->manifest($source);

        return $this->stager->handle($this->importDb(), $manifest->directory, [
            'target' => $target,
            'expected_files' => $source->file_count,
            'expected_rows' => $source->total_rows,
            'chunk_size' => max(1, (int) config('civil_import.worker.chunk_size', 5000)),
        ], $this->logger(), $this->hook());
    }

    /** Aborts the request's run only if it is still the RUNNING run. @return array<string, mixed> */
    private function abort(CivilImportRequest $request, string $target): array
    {
        $db = $this->importDb();
        $running = collect($this->releases->status($db, $target)['runs'])->firstWhere('status', 'RUNNING');
        if ($running === null || $running['id'] !== $request->run_id) {
            throw new CivilImportSourceException(CivilImportReason::PHASE_NOT_ALLOWED);
        }

        return $this->stager->abort($db, $target, $this->logger());
    }

    private function source(CivilImportRequest $request): CivilImportSource
    {
        $source = CivilImportSource::query()->find($request->source_id);
        if ($source === null || ! $source->isReady()) {
            throw new CivilImportSourceException(CivilImportReason::SOURCE_UNAVAILABLE);
        }

        return $source;
    }

    /**
     * The FB-CIVIL-01A import connection, with its login from the process
     * environment (never .env or the cached configuration), refused when it
     * shares a role with the control plane.
     */
    private function importDb(): Connection
    {
        $name = $this->importConnection;
        $username = (string) getenv('IDENTITY_REF_DB_USERNAME');
        if ($username !== '' && config("database.connections.{$name}") !== null) {
            config(["database.connections.{$name}.username" => $username,
                "database.connections.{$name}.password" => (string) getenv('IDENTITY_REF_DB_PASSWORD')]);
            DB::purge($name);
        }
        if (config("database.connections.{$name}.username") === null) {
            throw new IdentityImportException(IdentityImportException::NOT_CONFIGURED, 'The import login is not configured.');
        }
        $db = DB::connection($name);
        $session = self::membership($db);
        $control = self::membership(DB::connection());
        if ($session['login'] === $control['login'] || in_array(self::CONTROL_ROLE, $session['roles'], true)) {
            throw new IdentityImportException(IdentityImportException::WRONG_ROLE, 'The import login must be separate from the control plane.');
        }

        return $db;
    }

    // ---------------------------------------------------------------- heartbeat, cancellation, finish

    /** The engine hook: heartbeat and cancellation at each committed chunk. */
    private function hook(): Closure
    {
        return function (string $event, array $context): void {
            if (in_array($event, ['chunk.committed', 'prepare.chunk_committed'], true)) {
                $this->beat();
            }
            if ($this->testHook !== null) {
                ($this->testHook)($event, $context);
            }
        };
    }

    /**
     * Records liveness (at most every heartbeat_seconds) and stops the
     * operation when the operator asked to cancel it.
     *
     * @throws CivilImportCancelled
     */
    private function beat(): void
    {
        $now = microtime(true);
        if ($now - $this->lastBeat >= (int) config('civil_import.worker.heartbeat_seconds', 30)) {
            $this->lastBeat = $now;
            CivilImportRequest::query()->whereKey($this->current->id)->where('status', CivilImportRequest::RUNNING)
                ->update(['heartbeat_at' => Carbon::now()]);
            $this->heartbeat('BUSY', $this->current->id);
        }
        $cancel = CivilImportRequest::query()->whereKey($this->current->id)->value('cancel_requested_at');
        if ($cancel !== null && in_array($this->current->type, CivilImportRequest::CANCELLABLE_WHILE_RUNNING, true)) {
            $this->cancelling = true;
            throw new CivilImportCancelled;
        }
    }

    private function heartbeat(string $state, ?string $requestId): void
    {
        $now = Carbon::now();
        $row = CivilImportWorkerHeartbeat::query()->find($this->instance);
        if ($row === null) {
            CivilImportWorkerHeartbeat::query()->create(['instance' => $this->instance, 'state' => $state,
                'current_request_id' => $requestId, 'started_at' => $now, 'last_seen_at' => $now]);

            return;
        }
        $row->forceFill(['state' => $state, 'current_request_id' => $requestId, 'last_seen_at' => $now])->save();
    }

    /** @param array<string, mixed> $result */
    private function finish(CivilImportRequest $request, string $status, ?string $reason, array $result): void
    {
        $request->refresh();
        $clean = CivilImportResult::sanitize($result);
        $runId = $request->run_id ?? ($clean['run_id'] ?? null);
        $releaseId = $request->release_id ?? ($clean['release_id'] ?? null);
        $now = Carbon::now();
        $request->forceFill([
            'status' => $status, 'reason_code' => $reason, 'message' => CivilImportReason::message($reason),
            'result' => $clean === [] ? null : $clean, 'run_id' => $runId, 'release_id' => $releaseId,
            'finished_at' => $now, 'heartbeat_at' => $now,
        ])->save();
        $duration = $request->started_at === null ? 0 : max(0, (int) $request->started_at->diffInSeconds($now, true));
        CivilImportAudit::record(match ($status) {
            CivilImportRequest::SUCCEEDED => 'REQUEST_SUCCEEDED',
            CivilImportRequest::CANCELLED => 'REQUEST_CANCELLED',
            default => 'REQUEST_FAILED',
        }, null, $runId, $releaseId, $reason, ['type' => $request->type, 'duration_seconds' => $duration], $request->id);
    }

    // ---------------------------------------------------------------- helpers

    private static function policy(): IdentityImportPolicy
    {
        // The registry's approved default only; a synthetic policy is never used here.
        return IdentityImportPolicy::approved(IdentityImportPolicy::DEFAULT_ID);
    }

    private function logger(): LoggerInterface
    {
        return Log::channel(config('logging.default'));
    }

    /** @return array{login: string, current: string, superuser: bool, roles: list<string>} */
    private static function membership(Connection $db): array
    {
        $row = $db->selectOne("WITH RECURSIVE m(oid) AS (
                SELECT oid FROM pg_roles WHERE rolname = session_user
                UNION SELECT a.roleid FROM pg_auth_members a JOIN m ON a.member = m.oid)
            SELECT session_user AS login, current_user AS current,
                   (SELECT rolsuper FROM pg_roles WHERE rolname = session_user) AS superuser,
                   (SELECT string_agg(r.rolname, ',') FROM m JOIN pg_roles r ON r.oid = m.oid) AS roles");

        return ['login' => (string) $row->login, 'current' => (string) $row->current, 'superuser' => (bool) $row->superuser,
            'roles' => array_values(array_filter(explode(',', (string) $row->roles)))];
    }

    private static function redact(Throwable $e): string
    {
        return $e instanceof IdentityImportException || $e instanceof CivilImportWorkerException
            ? $e->getMessage()
            : IdentityRefErrorRedactor::redact($e);
    }
}
