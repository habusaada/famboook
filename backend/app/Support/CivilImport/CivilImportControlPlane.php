<?php

namespace App\Support\CivilImport;

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
    /** UI phase → actions it allows (ADR §3.1). Publication actions arrive with U3. */
    private const PHASE_ACTIONS = [
        'STAGING_INTERRUPTED' => ['STAGE', 'ABORT'],
        'STAGED' => ['ASSESS', 'PREPARE', 'ABORT'],
        'PREPARING' => ['PREPARE', 'ASSESS', 'ABORT'],
        'PREPARED' => ['VERIFY', 'ASSESS', 'ABORT'],
        'VERIFIED' => ['ASSESS', 'ABORT'],
    ];

    private const ACTION_PERMISSION = [
        'STAGE' => 'civil-import.stage',
        'ABORT' => 'civil-import.stage',
        'ASSESS' => 'civil-import.validate',
        'PREPARE' => 'civil-import.validate',
        'VERIFY' => 'civil-import.validate',
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

    /**
     * The phase refined with the control plane: an incomplete staging with no
     * active STAGE request is STAGING_INTERRUPTED (resumable).
     */
    public function phase(string $phase, string $runId, ?CivilImportRequest $active): string
    {
        if ($phase === 'STAGING' && ! ($active !== null && $active->type === CivilImportRequest::STAGE
            && ($active->run_id === null || $active->run_id === $runId))) {
            return 'STAGING_INTERRUPTED';
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

    /** The permission that queues a request type. */
    public static function permissionFor(string $type): string
    {
        return in_array($type, [CivilImportRequest::ASSESS, CivilImportRequest::PREPARE, CivilImportRequest::VERIFY], true)
            ? 'civil-import.validate'
            : 'civil-import.stage';
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
