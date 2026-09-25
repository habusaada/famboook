<?php

namespace App\Http\Resources;

use App\Support\FamilyLineage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape required by the existing /families/[id] profile screen
 * (Overview + Family Members tabs).
 */
class FamilyDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $members = $this->memberships;

        return [
            'family_code' => $this->family_code,
            ...FamilyLineage::present($this->resource),
            'status' => $this->status,
            'registration_date' => $this->registration_date?->toDateString(),
            'registration_source' => $this->registration_source,
            'paper_form_no' => $this->paper_form_no,
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'residence' => $this->when($this->currentResidence, fn () => [
                'governorate' => $this->currentResidence->governorate,
                'city' => $this->currentResidence->city,
                'area' => $this->currentResidence->area,
                'neighborhood' => $this->currentResidence->neighborhood,
                'address_text' => $this->currentResidence->address_text,
                'residence_type' => $this->currentResidence->residence_type,
                'started_at' => $this->currentResidence->started_at?->toDateString(),
                'original_residence_text' => $this->currentResidence->original_residence_text,
                // null = not collected (legacy), distinct from NOT_DISPLACED.
                'displacement_status' => $this->currentResidence->displacement_status,
                'displacement_location_text' => $this->currentResidence->displacement_location_text,
            ]),
            'member_count' => $members->count(),
            'male_count' => $members->filter(
                fn ($m) => $m->person->gender?->value === 'MALE'
            )->count(),
            'female_count' => $members->filter(
                fn ($m) => $m->person->gender?->value === 'FEMALE'
            )->count(),
            'members' => FamilyMemberResource::collection($members),
        ];
    }
}
