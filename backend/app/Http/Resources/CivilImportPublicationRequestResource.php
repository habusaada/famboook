<?php

namespace App\Http\Resources;

use App\Models\CivilImportPublicationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One publication request (FB-CIVIL-01A-UI U3). Every key is listed: ids,
 * statuses, codes, counts, names of the two users and times — never an
 * identity value, a path or free text. The can_* flags are UX hints for the
 * current user; the actions re-check everything.
 *
 * @property CivilImportPublicationRequest $resource
 */
class CivilImportPublicationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $user = $request->user();
        $pending = $p->status === CivilImportPublicationRequest::PENDING && ! $p->isExpiredAt(Carbon::now());
        $own = $user !== null && (int) $p->requested_by === (int) $user->id;
        $publish = $p->relationLoaded('publishRequest') ? $p->publishRequest : $p->publishRequest()->first();

        return [
            'id' => $p->id,
            'status' => $p->status,
            'release_id' => $p->release_id,
            'run_id' => $p->run_id,
            'base_release_id' => $p->base_release_id,
            'justification_code' => $p->justification_code,
            'decision_reason_code' => $p->decision_reason_code,
            'outcome_code' => $p->outcome_code,
            'snapshot' => array_map('intval', $p->snapshot ?? []),
            'requested_by' => $p->requester === null ? null : ['name' => $p->requester->name],
            'decided_by' => $p->decider === null ? null : ['name' => $p->decider->name],
            'requested_at' => $p->requested_at?->toIso8601String(),
            'expires_at' => $p->expires_at?->toIso8601String(),
            'decided_at' => $p->decided_at?->toIso8601String(),
            'finished_at' => $p->finished_at?->toIso8601String(),
            'publish_request' => $publish === null ? null : [
                'id' => $publish->id,
                'status' => $publish->status,
                'reason_code' => $publish->reason_code,
                'message' => $publish->message,
            ],
            'is_own' => $own,
            'can_approve' => $pending && ! $own && $user?->can('civil-import.publish.approve') && config('civil_import.publish_enabled') === true,
            'can_reject' => $pending && ! $own && (bool) $user?->can('civil-import.publish.approve'),
            'can_withdraw' => $pending && $own && (bool) $user?->can('civil-import.publish.request'),
        ];
    }
}
