<?php

namespace App\Actions;

use App\Exceptions\CivilImportRequestException;
use App\Models\CivilImportPublicationRequest;
use App\Models\CivilImportRequest;
use App\Models\User;
use App\Support\CivilImport\CivilImportAudit;
use App\Support\CivilImport\CivilImportPublications;
use App\Support\CivilImport\CivilImportReason;
use App\Support\StaffRoles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Decides a pending publication request (FB-CIVIL-01A-UI U3, ADR §3.3,
 * D-UI-6/11; single operator: D-UI-12a). Executes nothing: a confirmation
 * only queues the worker's PUBLISH request, in the same transaction.
 *
 * - confirm: the requester themself — an ACTIVE SUPER_ADMIN holding
 *   civil-import.publish.confirm (seeded only while the publication gate is
 *   open) — after re-entering their password, typing the release id and its
 *   verified record count (expected presence). Also needs the open gate, an
 *   unexpired request, an unchanged baseline, a still VERIFIED release and no
 *   other active execution request;
 * - withdraw: the requester, still holding civil-import.publish.request.
 *
 * Every transition locks the request row: of two concurrent confirmations
 * one wins, the other sees PUBLICATION_NOT_PENDING; at most one PUBLISH
 * request can ever exist per publication request (unique index).
 */
final class DecideCivilImportPublicationAction
{
    /** Wrong passwords allowed per user before confirmation is locked for REAUTH_DECAY_SECONDS. */
    public const REAUTH_MAX_ATTEMPTS = 5;

    public const REAUTH_DECAY_SECONDS = 900;

    public function __construct(private readonly CivilImportPublications $publications) {}

    public static function reauthKey(User $user): string
    {
        return 'civil-import-publication-reauth:'.$user->getKey();
    }

    /**
     * @return array{publication: CivilImportPublicationRequest, request: CivilImportRequest}
     *
     * @throws CivilImportRequestException
     */
    public function confirm(User $user, string $id, #[\SensitiveParameter] string $password, int $confirmReleaseId, int $confirmExpectedPresence): array
    {
        $this->assertCanConfirm($user);
        if (config('civil_import.publish_enabled') !== true) {
            throw new CivilImportRequestException(CivilImportReason::PUBLICATION_GATE_CLOSED);
        }
        $publication = $this->pending($id);
        if ((int) $publication->requested_by !== (int) $user->id) {
            throw new CivilImportRequestException(CivilImportReason::CONFIRMATION_BY_REQUESTER_ONLY);
        }
        $this->reauthenticate($user, $password, $publication);
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
                // The user row first (as Staff administration does): a concurrent
                // deactivation or role change waits for this confirmation, or wins before it.
                $actor = User::query()->whereKey($user->id)->sharedLock()->first();
                if ($actor === null || ! $actor->is_active || ! $actor->hasRole(StaffRoles::SUPER_ADMIN)) {
                    throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
                }
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
                CivilImportAudit::record('PUBLICATION_CONFIRMED', $user, $publication->run_id, $publication->release_id, null,
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
    public function withdraw(User $user, string $id): CivilImportPublicationRequest
    {
        if (! $user->can('civil-import.publish.request')) {
            throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
        }
        $publication = $this->pending($id);
        if ((int) $publication->requested_by !== (int) $user->id) {
            throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
        }

        return DB::transaction(function () use ($id, $user) {
            $publication = CivilImportPublicationRequest::query()->lockForUpdate()->find($id);
            if ($publication === null || $publication->status !== CivilImportPublicationRequest::PENDING) {
                throw new CivilImportRequestException(CivilImportReason::PUBLICATION_NOT_PENDING);
            }
            CivilImportAudit::record('PUBLICATION_WITHDRAWN', $user, $publication->run_id, $publication->release_id, null,
                publicationRequestId: $publication->id);
            $publication->forceFill(['status' => CivilImportPublicationRequest::WITHDRAWN, 'finished_at' => Carbon::now()])->save();

            return $publication->refresh();
        });
    }

    /** The permission, an active account and the SUPER_ADMIN role: all three (D-UI-12a). */
    private function assertCanConfirm(User $user): void
    {
        if (! $user->is_active || ! $user->hasRole(StaffRoles::SUPER_ADMIN) || ! $user->can('civil-import.publish.confirm')) {
            throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
        }
    }

    /**
     * Fresh password re-authentication for this confirmation. Failures are
     * counted per user (locked after REAUTH_MAX_ATTEMPTS) and audited; the
     * password is never stored, logged or echoed.
     */
    private function reauthenticate(User $user, #[\SensitiveParameter] string $password, CivilImportPublicationRequest $publication): void
    {
        $key = self::reauthKey($user);
        if (RateLimiter::tooManyAttempts($key, self::REAUTH_MAX_ATTEMPTS)) {
            throw new CivilImportRequestException(CivilImportReason::REAUTH_LOCKED);
        }
        if ($password !== '' && is_string($user->password) && Hash::check($password, $user->password)) {
            RateLimiter::clear($key);

            return;
        }
        RateLimiter::hit($key, self::REAUTH_DECAY_SECONDS);
        CivilImportAudit::record('PUBLICATION_REAUTH_FAILED', $user, $publication->run_id, $publication->release_id, CivilImportReason::REAUTH_FAILED,
            publicationRequestId: $publication->id);

        throw new CivilImportRequestException(CivilImportReason::REAUTH_FAILED);
    }

    /** The pending request (after expiry upkeep). */
    private function pending(string $id): CivilImportPublicationRequest
    {
        $this->publications->expireStale(checkState: false);
        $publication = CivilImportPublicationRequest::query()->find($id)
            ?? throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_FOUND);
        if ($publication->status === CivilImportPublicationRequest::EXPIRED) {
            throw new CivilImportRequestException(CivilImportReason::PUBLICATION_EXPIRED);
        }
        if ($publication->status !== CivilImportPublicationRequest::PENDING) {
            throw new CivilImportRequestException(CivilImportReason::PUBLICATION_NOT_PENDING);
        }

        return $publication;
    }
}
