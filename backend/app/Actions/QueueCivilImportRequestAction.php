<?php

namespace App\Actions;

use App\Exceptions\CivilImportRequestException;
use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Models\User;
use App\Support\CivilImport\CivilImportAudit;
use App\Support\CivilImport\CivilImportControlPlane;
use App\Support\CivilImport\CivilImportReason;
use App\Support\IdentityRef\IdentityImportMonitor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Records a civil identity import execution request (FB-CIVIL-01A-UI U2,
 * ADR §3.2). It EXECUTES NOTHING (S-1/S-2): it checks the permission, the
 * phase (from the metadata-only monitor) and the single-active-request rule,
 * inserts a QUEUED row and audits it. The dedicated worker runs the request
 * and re-checks every precondition through the FB-CIVIL-01A engine.
 *
 * Idempotency: a repeated call with the same requester and key returns the
 * original request when type and target match, and is refused otherwise.
 * Targets are an opaque source id, a run uuid or a release id — never a path.
 */
final class QueueCivilImportRequestAction
{
    public function __construct(
        private readonly IdentityImportMonitor $monitor,
        private readonly CivilImportControlPlane $control,
    ) {}

    /**
     * @param  array{source_id?: string, run_id?: string, release_id?: int}  $target
     * @return array{request: CivilImportRequest, created: bool}
     *
     * @throws CivilImportRequestException
     */
    public function handle(User $user, string $type, array $target, ?string $idempotencyKey = null): array
    {
        if (! in_array($type, CivilImportRequest::TYPES, true)) {
            throw new CivilImportRequestException(CivilImportReason::UNSUPPORTED_REQUEST);
        }
        if (! $user->can(CivilImportControlPlane::permissionFor($type))) {
            throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
        }
        $attributes = ['type' => $type, 'requested_by' => $user->id, 'idempotency_key' => $idempotencyKey,
            'source_id' => $target['source_id'] ?? null, 'run_id' => $target['run_id'] ?? null, 'release_id' => $target['release_id'] ?? null];

        if ($idempotencyKey !== null) {
            $previous = CivilImportRequest::query()->where('requested_by', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($previous !== null) {
                return ['request' => $this->replay($previous, $attributes), 'created' => false];
            }
        }

        $this->assertPhase($type, $attributes);
        if ($this->control->activeRequest() !== null) {
            throw new CivilImportRequestException(CivilImportReason::REQUEST_ALREADY_ACTIVE);
        }

        try {
            $request = DB::transaction(fn () => CivilImportRequest::query()->create($attributes));
        } catch (QueryException $e) {
            // A concurrent request won the single-active index, or the same key was used concurrently.
            $previous = $idempotencyKey === null ? null
                : CivilImportRequest::query()->where('requested_by', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($previous !== null) {
                return ['request' => $this->replay($previous, $attributes), 'created' => false];
            }
            if (str_contains($e->getMessage(), 'uq_civil_import_requests_one_active') || ($e->errorInfo[0] ?? null) === '23505') {
                throw new CivilImportRequestException(CivilImportReason::REQUEST_ALREADY_ACTIVE);
            }
            throw $e;
        }
        CivilImportAudit::record('REQUEST_QUEUED', $user, $request->run_id, $request->release_id,
            details: ['type' => $type], requestId: $request->id);

        return ['request' => $request->refresh(), 'created' => true];
    }

    /** @param array<string, mixed> $attributes */
    private function replay(CivilImportRequest $previous, array $attributes): CivilImportRequest
    {
        foreach (['type', 'source_id', 'run_id', 'release_id'] as $key) {
            $original = $key === 'run_id' && $previous->type === CivilImportRequest::STAGE ? $attributes[$key] : $previous->{$key};
            if ((string) $original !== (string) $attributes[$key]) {
                throw new CivilImportRequestException(CivilImportReason::IDEMPOTENCY_KEY_REUSED);
            }
        }

        return $previous;
    }

    /** @param array<string, mixed> $attributes */
    private function assertPhase(string $type, array $attributes): void
    {
        if (in_array($type, [CivilImportRequest::VALIDATE_SOURCE, CivilImportRequest::STAGE], true)) {
            $source = CivilImportSource::query()->find($attributes['source_id']);
            if ($source === null || ! $source->isReady()) {
                throw new CivilImportRequestException(CivilImportReason::SOURCE_NOT_READY);
            }
            if ($type === CivilImportRequest::STAGE) {
                $running = $this->monitor->overview()['current_run'];
                // Another run is open: only resuming the staging of the same source is allowed.
                if ($running !== null && ($running['phase'] !== 'STAGING'
                    || $this->control->sourceOfRun($running['id'])?->id !== $source->id)) {
                    throw new CivilImportRequestException(CivilImportReason::PHASE_NOT_ALLOWED);
                }
            }

            return;
        }

        if ($type === CivilImportRequest::VERIFY) {
            $release = $this->monitor->release((int) $attributes['release_id']);
            if ($release === null) {
                throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_FOUND);
            }
            if ($release['status'] !== 'BUILDING' || ($release['preparation']['status'] ?? null) !== 'PREPARED') {
                throw new CivilImportRequestException(CivilImportReason::PHASE_NOT_ALLOWED);
            }

            return;
        }

        $run = $this->monitor->run((string) $attributes['run_id']);
        if ($run === null) {
            throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_FOUND);
        }
        $allowed = match ($type) {
            CivilImportRequest::ABORT => $run['run_status'] === 'RUNNING',
            CivilImportRequest::ASSESS => $run['run_status'] === 'RUNNING' && in_array($run['phase'], ['STAGED', 'PREPARING', 'PREPARED', 'VERIFIED'], true),
            CivilImportRequest::PREPARE => $run['run_status'] === 'RUNNING' && in_array($run['phase'], ['STAGED', 'PREPARING'], true),
            default => false,
        };
        if (! $allowed) {
            throw new CivilImportRequestException(CivilImportReason::PHASE_NOT_ALLOWED);
        }
    }
}
