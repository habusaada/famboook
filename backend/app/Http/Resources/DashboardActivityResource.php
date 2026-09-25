<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A Family Activity Log entry on the Operational Dashboard: the same safe
 * presentation as the Family timeline plus the Family's public code, so
 * the entry can link to its Family.
 */
class DashboardActivityResource extends FamilyActivityResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'family' => ['family_code' => $this->family?->family_code],
        ];
    }
}
