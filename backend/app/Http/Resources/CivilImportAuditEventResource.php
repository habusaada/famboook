<?php

namespace App\Http\Resources;

use App\Models\CivilImportAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One civil import audit event (FB-CIVIL-01A-UI, ADR §8): who (name only),
 * what (a fixed type and outcome code), on which run/release, with
 * allow-listed details. No internal user id, e-mail or identity value.
 *
 * @mixin CivilImportAuditEvent
 */
class CivilImportAuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'event_type' => $this->event_type,
            'actor' => [
                'side' => $this->actor_side,
                'name' => $this->actor_side === 'STAFF' ? $this->actor?->name : null,
            ],
            'run_id' => $this->run_id,
            'release_id' => $this->release_id,
            'outcome_code' => $this->outcome_code,
            'details' => (object) ($this->details ?? []),
        ];
    }
}
