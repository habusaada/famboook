<?php

namespace App\Http\Resources;

use App\Support\FamilyPortal\RegistryAdmission\SuggestedChild;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One suggested child on GET /api/v1/family/household/registry-children
 * (FB-FAMILY-ADD-01 ADD-WP-06B-3). PROVISIONAL until gate G-02: an explicit
 * allowlist of the least that tells children apart — the first name, the birth year
 * and the public status. Never a National ID (even masked), a full birth date, a
 * relationship, an internal reason, another household or a registry field. `ref` is
 * NULL: no reference is issued while nothing can be ELIGIBLE.
 *
 * @property SuggestedChild $resource
 */
class FamilyRegistryChildResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $name = $this->resource->fullName === null ? '' : trim($this->resource->fullName);

        return [
            'display_name' => $name === '' ? null : preg_split('/\s+/u', $name)[0],
            'birth_year' => $this->resource->birthDate === null ? null : (int) substr($this->resource->birthDate, 0, 4),
            'status' => $this->resource->status->value,
            'ref' => null,
        ];
    }
}
