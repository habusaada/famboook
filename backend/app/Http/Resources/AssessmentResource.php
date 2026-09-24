<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Assessment detail for assessment.view holders only. The family part is
 * its public code; users are display names only. No National ID, contact
 * data or Person health records are ever included.
 */
class AssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'family' => ['family_code' => $this->family->family_code],
            'assessment_date' => $this->assessment_date->toDateString(),
            'status' => $this->status,
            'general_notes' => $this->general_notes,
            'created_by' => $this->creator ? ['name' => $this->creator->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->completer ? ['name' => $this->completer->name] : null,
            // Stored results only. A domain that is absent was not assessed.
            'results' => $this->results->sortBy('domain.sort_order')->values()->map(fn ($result) => [
                'domain' => new AssessmentDomainResource($result->domain),
                'rating' => $result->rating,
                'notes' => $result->notes,
            ]),
        ];
    }
}
