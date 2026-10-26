<?php

namespace App\Http\Resources;

use App\Models\CivilImportRequest;
use App\Support\CivilImport\CivilImportControlPlane;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One civil import execution request (FB-CIVIL-01A-UI U2). Every key is
 * listed: the result is the stored allow-list of counts and codes, the
 * message the fixed text of the reason code. The source label appears only
 * for civil-import.stage holders (O-2); never a path, a file name or an
 * identity value.
 *
 * @property CivilImportRequest $resource
 */
class CivilImportRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $user = $request->user();

        return [
            'id' => $r->id,
            'type' => $r->type,
            'status' => $r->status,
            'requested_by' => $r->requester === null ? null : ['name' => $r->requester->name],
            'source' => $r->source_id === null ? null : [
                'id' => $r->source_id,
                'label' => $user?->can('civil-import.stage') ? $r->source?->label : null,
            ],
            'run_id' => $r->run_id,
            'release_id' => $r->release_id,
            'attempts' => $r->attempts,
            'reason_code' => $r->reason_code,
            'message' => $r->message,
            'result' => $r->result ?? (object) [],
            'cancel_requested' => $r->cancel_requested_at !== null,
            'can_cancel' => $user !== null && CivilImportControlPlane::canCancel($user, $r)
                && ($r->status === CivilImportRequest::QUEUED || in_array($r->type, CivilImportRequest::CANCELLABLE_WHILE_RUNNING, true))
                && $r->cancel_requested_at === null,
            'queued_at' => $r->queued_at?->toIso8601String(),
            'started_at' => $r->started_at?->toIso8601String(),
            'heartbeat_at' => $r->heartbeat_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}
