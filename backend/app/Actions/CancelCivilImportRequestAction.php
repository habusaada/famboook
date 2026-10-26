<?php

namespace App\Actions;

use App\Exceptions\CivilImportRequestException;
use App\Models\CivilImportRequest;
use App\Models\User;
use App\Support\CivilImport\CivilImportAudit;
use App\Support\CivilImport\CivilImportControlPlane;
use App\Support\CivilImport\CivilImportReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a civil import execution request (FB-CIVIL-01A-UI U2, ADR §3.2).
 * Executes nothing:
 * - QUEUED → CANCELLED at once (the worker never picks it up);
 * - RUNNING (staging, preparation, source validation) → the cancellation is
 *   RECORDED; the worker stops at the next committed chunk or file and closes
 *   the request CANCELLED. The FB-CIVIL-01A run stays resumable; nothing is
 *   rolled back or purged (an ABORT is a separate request).
 * Allowed for a civil-import.stage holder, or the requester while still
 * holding the permission that queued it.
 */
final class CancelCivilImportRequestAction
{
    /**
     * @return array{request: CivilImportRequest, immediate: bool}
     *
     * @throws CivilImportRequestException
     */
    public function handle(User $user, string $requestId): array
    {
        return DB::transaction(function () use ($user, $requestId) {
            $request = CivilImportRequest::query()->lockForUpdate()->find($requestId)
                ?? throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_FOUND);
            if (! $request->isActive()) {
                throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_CANCELLABLE);
            }
            if (! CivilImportControlPlane::canCancel($user, $request)) {
                throw new CivilImportRequestException(CivilImportReason::NOT_ALLOWED);
            }

            if ($request->status === CivilImportRequest::QUEUED) {
                $request->forceFill(['status' => CivilImportRequest::CANCELLED, 'cancelled_by' => $user->id,
                    'cancel_requested_at' => Carbon::now(), 'finished_at' => Carbon::now(),
                    'reason_code' => CivilImportReason::CANCELLED_BY_OPERATOR,
                    'message' => CivilImportReason::message(CivilImportReason::CANCELLED_BY_OPERATOR)])->save();
                CivilImportAudit::record('REQUEST_CANCELLED', $user, $request->run_id, $request->release_id,
                    CivilImportReason::CANCELLED_BY_OPERATOR, ['type' => $request->type], $request->id);

                return ['request' => $request, 'immediate' => true];
            }

            if (! in_array($request->type, CivilImportRequest::CANCELLABLE_WHILE_RUNNING, true)) {
                throw new CivilImportRequestException(CivilImportReason::REQUEST_NOT_CANCELLABLE);
            }
            if ($request->cancel_requested_at === null) {
                // Column update only: the worker owns the RUNNING request's state.
                CivilImportRequest::query()->whereKey($request->id)->update(['cancel_requested_at' => Carbon::now(), 'cancelled_by' => $user->id]);
                CivilImportAudit::record('REQUEST_CANCELLED', $user, $request->run_id, $request->release_id,
                    'CANCELLATION_REQUESTED', ['type' => $request->type], $request->id);
            }

            return ['request' => $request->refresh(), 'immediate' => false];
        });
    }
}
