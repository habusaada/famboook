<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Health record as exposed to health-record.view holders only. The Person
 * part is limited to safe identity fields: no National ID, no contact data.
 */
class HealthRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'person' => [
                'person_code' => $this->person->person_code,
                'full_name' => $this->person->full_name,
                'gender' => $this->person->gender,
            ],
            'disability_type' => $this->disabilityType ? [
                'id' => $this->disabilityType->id,
                'code' => $this->disabilityType->code,
                'name' => $this->disabilityType->name,
            ] : null,
            'condition_name' => $this->condition_name,
            'details' => $this->details,
            'started_at' => $this->started_at?->toDateString(),
            'ended_at' => $this->ended_at?->toDateString(),
            'is_active' => $this->isActive(),
        ];
    }
}
