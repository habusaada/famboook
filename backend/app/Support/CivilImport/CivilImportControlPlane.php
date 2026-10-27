<?php

namespace App\Support\CivilImport;

use App\Models\CivilImportPublicationRequest;
use App\Models\CivilImportRequest;
use App\Models\CivilImportSource;
use App\Models\CivilImportWorkerHeartbeat;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Web-side reads of the civil import control plane (FB-CIVIL-01A-UI U2):
 * the worker's state, the active request, a run's source and the actions a
 * user may queue. Application tables only — never the identity schemas, never
 * the source root.
 */
final class CivilImportControlPlane
{
    /** UI phase → actions it allows (ADR §3.1). Approve / reject / withdraw live on the publication request. */
    private const PHASE_ACTIONS = [
        'STAGING_INTERRUPTED' => ['STAGE', 'ABORT'],
        'STAGED' => ['ASSESS', 'PREPARE', 'ABORT'],
        'PREPARING' => ['PREPARE', 'ASSESS', 'ABORT'],
        'PREPARED' => ['VERIFY', 'ASSESS', 'ABORT'],
        'VERIFIED' => ['REQUEST_PUBLICATION', 'ASSESS', 'ABORT'],
        'AWAITING_APPROVAL' => ['ABORT'],
    ];

    private const ACTION_PERMISSION = [
        'STAGE' => 'civil-import.stage',
        'ABORT' => 'civil-import.stage',
        'ASSESS' => 'civil-import.validate',
        'PREPARE' => 'civil-import.validate',
        'VERIFY' => 'civil-import.validate',
        'REQUEST_PUBLICATION' => 'civil-import.publish.request',
    ];

    /** @return array{state: string, last_seen_at: ?string} */
    public function worker(): array
    {
        $beat = CivilImportWorkerHeartbeat::query()->orderByDesc('last_seen_at')->first();
        if ($beat === null) {
            return ['state' => 'NOT_INSTALLED', 'last_seen_at' => null];
        }
        $offline = $beat->last_seen_at->lt(Carbon::now()->subSeconds((int) config('civil_import.worker.offline_after_seconds', 120)));

        return [
            'state' => match (true) {
                $beat->state === 'STOPPED' || $offline => 'STALE',
                $beat->state === 'BUSY' => 'BUSY',
                default => 'IDLE',
            },
            'last_seen_at' => $beat->last_seen_at->toIso8601String(),
        ];
    }

    public function activeRequest(): ?CivilImportRequest
    {
        return CivilImportRequest::query()->whereIn('status', CivilImportRequest::ACTIVE)->orderBy('queued_at')->first();
    }

    /** The source a run was staged from through the UI, if any (CLI runs have none). */
    public function sourceOfRun(string $runId): ?CivilImportSource
    {
        $sourceId = CivilImportRequest::query()->where('type', CivilImportRequest::STAGE)->where('run_id', $runId)
            ->whereNotNull('source_id')->orderByDesc('queued_at')->value('source_id');

        return $sourceId === null ? null : CivilImportSource::query()->find($sourceId);
    }

    /** The requester of the run's first STAGE request (runs started from the UI). */
    public function requesterOfRun(string $runId): ?User
    {
        return CivilImportRequest::query()->with('requester')->where('type', CivilImportRequest::STAGE)->where('run_id', $runId)
            ->orderBy('queued_at')->first()?->requester;
    }

    /** The open (pending or approved) publication request of a run, if any. */
    public function openPublication(string $runId): ?CivilImportPublicationRequest
    {
        return CivilImportPublicationRequest::query()->where('run_id', $runId)->whereIn('status', CivilImportPublicationRequest::OPEN)->first();
    }

    public function pendingPublications(): int
    {
        return CivilImportPublicationRequest::query()->where('status', CivilImportPublicationRequest::PENDING)->count();
    }

    /**
     * The phase refined with the control plane: an incomplete staging with no
     * active STAGE request is STAGING_INTERRUPTED (resumable); a VERIFIED run
     * with an open publication request is AWAITING_APPROVAL or APPROVED (U3).
     */
    public function phase(string $phase, string $runId, ?CivilImportRequest $active): string
    {
        if ($phase === 'STAGING' && ! ($active !== null && $active->type === CivilImportRequest::STAGE
            && ($active->run_id === null || $active->run_id === $runId))) {
            return 'STAGING_INTERRUPTED';
        }
        if ($phase === 'VERIFIED' && ($open = $this->openPublication($runId)) !== null) {
            return $open->status === CivilImportPublicationRequest::PENDING ? 'AWAITING_APPROVAL' : 'APPROVED';
        }

        return $phase;
    }

    /**
     * The actions $user may queue now: the phase's actions ∩ the user's
     * permissions; none while another request is active. A hint for the UI:
     * QueueCivilImportRequestAction and the worker re-check everything.
     *
     * @return list<string>
     */
    public function allowedActions(string $phase, bool $hasSource, ?CivilImportRequest $active, User $user): array
    {
        if ($active !== null) {
            return [];
        }
        $actions = array_filter(self::PHASE_ACTIONS[$phase] ?? [],
            fn (string $a) => $user->can(self::ACTION_PERMISSION[$a]) && ($a !== 'STAGE' || $hasSource));

        return array_values($actions);
    }

    /** The permission that queues a request type (PUBLISH: created by the requester's confirmation only). */
    public static function permissionFor(string $type): string
    {
        return match ($type) {
            CivilImportRequest::ASSESS, CivilImportRequest::PREPARE, CivilImportRequest::VERIFY => 'civil-import.validate',
            CivilImportRequest::PUBLISH => 'civil-import.publish.confirm',
            default => 'civil-import.stage',
        };
    }

    /**
     * Whether $user may cancel $request: a civil-import.stage holder, or its
     * requester while still holding the permission that queued it.
     */
    public static function canCancel(User $user, CivilImportRequest $request): bool
    {
        return $request->isActive() && ($user->can('civil-import.stage')
            || ($request->requested_by === $user->id && $user->can(self::permissionFor($request->type))));
    }
}
