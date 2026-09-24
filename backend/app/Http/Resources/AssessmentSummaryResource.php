<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a family's assessment list. Ratings only: general notes and
 * domain notes appear in the assessment detail, never here. The assessed
 * domain count is derived from the loaded results, not stored.
 */
class AssessmentSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $results = $this->results->sortBy('domain.sort_order')->values();

        return [
            'id' => $this->uuid,
            'assessment_date' => $this->assessment_date->toDateString(),
            'status' => $this->status,
            'assessed_domain_count' => $results->count(),
            'ratings' => $results->map(fn ($result) => [
                'domain' => new AssessmentDomainResource($result->domain),
                'rating' => $result->rating,
            ]),
            'created_by' => $this->creator ? ['name' => $this->creator->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
