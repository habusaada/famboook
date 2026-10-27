<?php

namespace App\Http\Resources;

use App\Models\CivilImportPublicationRequest;
use App\Support\StaffRoles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One publication request (FB-CIVIL-01A-UI U3, D-UI-12a). Every key is
 * listed: ids, statuses, codes, counts, the names of the requester and of the
 * user who confirmed, and times — never an identity value, a path or free
 * text. The can_* flags are UX hints for the current user; the actions
 * re-check everything.
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
        // D-UI-12a: only the requester confirms, as an active SUPER_ADMIN, while the gate is open.
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
            'can_confirm' => $pending && $own && (bool) $user?->is_active && (bool) $user?->hasRole(StaffRoles::SUPER_ADMIN)
                && (bool) $user?->can('civil-import.publish.confirm') && config('civil_import.publish_enabled') === true,
            'can_withdraw' => $pending && $own && (bool) $user?->can('civil-import.publish.request'),
        ];
    }
}
