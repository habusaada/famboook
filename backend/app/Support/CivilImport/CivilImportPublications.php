<?php

namespace App\Support\CivilImport;

use App\Models\CivilImportPublicationRequest;
use App\Support\IdentityRef\IdentityImportMonitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Web-side upkeep of publication requests (FB-CIVIL-01A-UI U3, ADR §3.3,
 * D-UI-11): a pending request EXPIRES after 24 hours, and as soon as the
 * active release is no longer its baseline or its release is no longer a
 * VERIFIED candidate. Applied on read and before every decision; the worker
 * applies the time rule too and re-checks everything before publishing.
 * Never calls the publication engine.
 */
final class CivilImportPublications
{
    public function __construct(private readonly IdentityImportMonitor $monitor) {}

    /**
     * Expires the pending request when it is stale. $checkState also compares
     * it with the identity state through the metadata-only monitor (skipped,
     * not failed, when the monitor is unavailable: every decision needs the
     * monitor anyway).
     */
    public function expireStale(bool $checkState = true): void
    {
        $pending = CivilImportPublicationRequest::query()->where('status', CivilImportPublicationRequest::PENDING)->get();
        foreach ($pending as $request) {
            $cause = $request->isExpiredAt(Carbon::now()) ? 'TIMEOUT' : ($checkState ? $this->stateChange($request) : null);
            if ($cause !== null) {
                self::expire($request, $cause);
            }
        }
    }

    /**
     * BASE_CHANGED or RELEASE_CHANGED when the identity state no longer
     * matches $request; null otherwise. $strict (decisions): an unavailable
     * monitor fails closed (503) instead of skipping the check.
     */
    public function stateChange(CivilImportPublicationRequest $request, bool $strict = false): ?string
    {
        try {
            $active = $this->monitor->overview()['active_release']['id'] ?? null;
            $release = $this->monitor->release($request->release_id);
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }

            return null;
        }
        if ($active !== $request->base_release_id) {
            return CivilImportReason::BASE_CHANGED;
        }
        if ($release === null || $release['status'] !== 'BUILDING' || ($release['preparation']['status'] ?? null) !== 'VERIFIED') {
            return CivilImportReason::RELEASE_CHANGED;
        }

        return null;
    }

    /** PENDING_APPROVAL → EXPIRED with its cause (TIMEOUT, BASE_CHANGED, RELEASE_CHANGED, RELEASE_ABANDONED), audited. */
    public static function expire(CivilImportPublicationRequest $request, string $cause): void
    {
        DB::transaction(function () use ($request, $cause) {
            $locked = CivilImportPublicationRequest::query()->lockForUpdate()->find($request->id);
            if ($locked === null || $locked->status !== CivilImportPublicationRequest::PENDING) {
                return;
            }
            $locked->forceFill(['status' => CivilImportPublicationRequest::EXPIRED, 'outcome_code' => $cause, 'finished_at' => Carbon::now()])->save();
            CivilImportAudit::record('PUBLICATION_EXPIRED', null, $locked->run_id, $locked->release_id, $cause,
                publicationRequestId: $locked->id);
        });
    }
}
