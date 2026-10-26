<?php

namespace App\Actions;

use App\Exceptions\CivilImportRequestException;
use App\Models\CivilImportPublicationRequest;
use App\Models\CivilImportRequest;
use App\Models\User;
use App\Support\CivilImport\CivilImportAudit;
use App\Support\CivilImport\CivilImportPublications;
use App\Support\CivilImport\CivilImportReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Decides a pending publication request (FB-CIVIL-01A-UI U3, ADR §3.3,
 * D-UI-6/11/12). Executes nothing: an approval only queues the worker's
 * PUBLISH request, in the same transaction.
 *
 * - approve / reject: civil-import.publish.approve, by ANOTHER user than the
 *   requester (also a database CHECK); approval additionally needs the open
 *   publication gate, an unexpired request, an unchanged baseline, a still
 *   VERIFIED release, the typed release id and expected presence, and no
 *   other active execution request;
 * - withdraw: the requester, still holding civil-import.publish.request.
 *
 * Every transition locks the request row: of two concurrent approvals one
 * wins, the other sees PUBLICATION_NOT_PENDING; at most one PUBLISH request
 * can ever exist per publication request (unique index).
 */
final class DecideCivilImportPublicationAction
{
    public function __construct(private readonly CivilImportPublications $publications) {}

    /**
     * @return array{publication: CivilImportPublicationRequest, request: CivilImportRequest}
     *
     * @throws CivilImportRequestException
     */
    public function approve(User $user, string $id, int $confirmReleaseId, int $confirmExpectedPresence): array
    {
        $this->assertCanDecide($user);
        if (config('civil_import.publish_enabled') !== true) {
            throw new CivilImportRequestException(CivilImportReason::PUBLICATION_GATE_CLOSED);
        }
        $publication = $this->pending($user, $id, mustNotBeRequester: true);
        if ($confirmReleaseId !== $publication->release_id
            || $confirmExpectedPresence !== (int) ($publication->snapshot['expected_presence'] ?? -1)) {
            throw new CivilImportRequestException(CivilImportReason::CONFIRMATION_MISMATCH);
        }
        if (($cause = $this->publications->stateChange($publication, strict: true)) !== null) {
            CivilImportPublications::expire($publication, $cause);
            throw new CivilImportRequestException($cause);
        }

        try {
            return DB::transaction(function () use ($user, $id) {
                $publication = CivilImportPublicationRequest::query()->lockForUpdate()->find($id);
                if ($publication === null || $publication->status !== CivilImportPublicationRequest::PENDING) {
                    throw new CivilImportRequestException(CivilImportReason::PUBLICATION_NOT_PENDING);
                }
                if ($publication->isExpiredAt(Carbon::now())) {
                    throw new CivilImportRequestException(CivilImportReason::PUBLICATION_EXPIRED);
                }
                if (CivilImportRequest::query()->whereIn('status', CivilImportRequest::ACTIVE)->exists()) {
                    throw new CivilImportRequestException(CivilImportReason::REQUEST_ALREADY_ACTIVE);
                }
                $now = Carbon::now();
                $publication->forceFill(['status' => CivilImportPublicationRequest::APPROVED, 'decided_by' => $user->id, 'decided_at' => $now])->save();
                $request = CivilImportRequest::query()->create([
                    'type' => CivilImportRequest::PUBLISH, 'requested_by' => $user->id, 'run_id' => $publication->run_id,
                    'release_id' => $publication->release_id, 'publication_request_id' => $publication->id,
                ]);
                CivilImportAudit::record('PUBLICATION_APPROVED', $user, $publication->run_id, $publication->release_id, null,
                    ['expected_presence' => (int) ($publication->snapshot['expected_presence'] ?? 0)], publicationRequestId: $publication->id);
                CivilImportAudit::record('REQUEST_QUEUED', $user, $publication->run_id, $publication->release_id, null,
                    ['type' => CivilImportRequest::PUBLISH], $request->id, $publication->id);

                return ['publication' => $publication->refresh(), 'request' => $request->refresh()];
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {   // another execution request, or another PUBLISH, won the race
                throw new CivilImportRequestException(CivilImportReason::REQUEST_ALREADY_ACTIVE);
            }
            throw $e;
        }
    }

    /** @throws CivilImportRequestException */
    public function reject(User $user, string $id, string $reasonCode): CivilImportPublicationRequest
    {
        $this->assertCanDecide($user);
        $this->pending($user, $id, mustNotBeRequester: true);

        return $this->close($id, CivilImportPublicationRequest::REJECTED, function (CivilImportPublicationRequest $p) use ($user, $reasonCode) {
            $p->forceFill(['decided_by' => $user->id, 'decided_at' => Carbon::now(), 'decision_reason_code' => $reasonCode]);
            CivilImportAudit::record('PUBLICATION_REJECTED', $user, $p->run_id, $p->release_id, $reasonCode, publicationRequestId: $p->id);
        });
    }

    /** @throws CivilImportRequestException */
    public function withdraw(User $user, string $id): CivilImportPublicationRequest
    {
        if (! $user->can('civil-import.publish.request')) {
            throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
        }
        $publication = $this->pending($user, $id, mustNotBeRequester: false);
        if ((int) $publication->requested_by !== (int) $user->id) {
            throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
        }

        return $this->close($id, CivilImportPublicationRequest::WITHDRAWN, function (CivilImportPublicationRequest $p) use ($user) {
            CivilImportAudit::record('PUBLICATION_WITHDRAWN', $user, $p->run_id, $p->release_id, null, publicationRequestId: $p->id);
        });
    }

    private function assertCanDecide(User $user): void
    {
        if (! $user->can('civil-import.publish.approve')) {
            throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
        }
    }

    /** The pending request (after expiry upkeep). */
    private function pending(User $user, string $id, bool $mustNotBeRequester): CivilImportPublicationRequest
    {
        $this->publications->expireStale(checkState: false);
        $publication = CivilImportPublicationRequest::query()->find($id)
            ?? throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_FOUND);
        if ($mustNotBeRequester && (int) $publication->requested_by === (int) $user->id) {
            throw new CivilImportRequestException(CivilImportReason::SAME_USER_APPROVAL);
        }
        if ($publication->status === CivilImportPublicationRequest::EXPIRED) {
            throw new CivilImportRequestException(CivilImportReason::PUBLICATION_EXPIRED);
        }
        if ($publication->status !== CivilImportPublicationRequest::PENDING) {
            throw new CivilImportRequestException(CivilImportReason::PUBLICATION_NOT_PENDING);
        }

        return $publication;
    }

    /** @param callable(CivilImportPublicationRequest): void $apply */
    private function close(string $id, string $status, callable $apply): CivilImportPublicationRequest
    {
        return DB::transaction(function () use ($id, $status, $apply) {
            $publication = CivilImportPublicationRequest::query()->lockForUpdate()->find($id);
            if ($publication === null || $publication->status !== CivilImportPublicationRequest::PENDING) {
                throw new CivilImportRequestException(CivilImportReason::PUBLICATION_NOT_PENDING);
            }
            $apply($publication);
            $publication->forceFill(['status' => $status, 'finished_at' => Carbon::now()])->save();

            return $publication->refresh();
        });
    }
}
