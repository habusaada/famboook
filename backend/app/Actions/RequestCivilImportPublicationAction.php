<?php

namespace App\Actions;

use App\Exceptions\CivilImportRequestException;
use App\Models\CivilImportPublicationRequest;
use App\Models\User;
use App\Support\CivilImport\CivilImportAudit;
use App\Support\CivilImport\CivilImportPublications;
use App\Support\CivilImport\CivilImportReason;
use App\Support\IdentityRef\IdentityImportMonitor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opens a publication request for a VERIFIED identity release
 * (FB-CIVIL-01A-UI U3, ADR §3.3; civil-import.publish.request). It publishes
 * NOTHING: the requester confirms it within 24 hours, re-entering their
 * password (D-UI-11/12a), and the dedicated worker re-checks every condition
 * before publishing.
 *
 * Recorded: the release, its run, the active release it was compared with
 * (the baseline), a justification code and a counts snapshot from the
 * metadata-only monitor — never an identity value or free text.
 */
final class RequestCivilImportPublicationAction
{
    public function __construct(
        private readonly IdentityImportMonitor $monitor,
        private readonly CivilImportPublications $publications,
    ) {}

    /** @throws CivilImportRequestException */
    public function handle(User $user, int $releaseId, string $justificationCode): CivilImportPublicationRequest
    {
        if (! $user->can('civil-import.publish.request')) {
            throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
        }
        $this->publications->expireStale();

        $release = $this->monitor->release($releaseId) ?? throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_FOUND);
        $preparation = $release['preparation'];
        if ($release['status'] !== 'BUILDING' || ($preparation['status'] ?? null) !== 'VERIFIED') {
            throw new CivilImportRequestException(CivilImportReason::PHASE_NOT_ALLOWED);
        }
        $active = $this->monitor->overview()['active_release']['id'] ?? null;
        if ($release['base_release_id'] !== $active) {
            // The candidate was compared with another release; publication would be refused as stale.
            throw new CivilImportRequestException(CivilImportReason::BASE_CHANGED);
        }
        if (CivilImportPublicationRequest::query()->whereIn('status', CivilImportPublicationRequest::OPEN)->exists()) {
            throw new CivilImportRequestException(CivilImportReason::PUBLICATION_ALREADY_OPEN);
        }

        $now = Carbon::now();
        $snapshot = array_map('intval', array_intersect_key($preparation['counts'], array_flip(
            ['staged', 'new', 'changed', 'unchanged', 'conflict', 'carried_forward', 'blank_over_value', 'expected_presence'])));
        try {
            $request = DB::transaction(fn () => CivilImportPublicationRequest::query()->create([
                'release_id' => $releaseId,
                'run_id' => $release['run_id'],
                'base_release_id' => $active,
                'requested_by' => $user->id,
                'justification_code' => $justificationCode,
                'snapshot' => $snapshot,
                'expires_at' => $now->copy()->addHours(CivilImportPublicationRequest::LIFETIME_HOURS),
            ]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {   // a concurrent request won uq_civil_import_publication_one_open
                throw new CivilImportRequestException(CivilImportReason::PUBLICATION_ALREADY_OPEN);
            }
            throw $e;
        }
        CivilImportAudit::record('PUBLICATION_REQUESTED', $user, $request->run_id, $releaseId, null,
            ['code' => $justificationCode, 'expected_presence' => $snapshot['expected_presence'] ?? 0], publicationRequestId: $request->id);

        return $request->refresh();
    }
}
