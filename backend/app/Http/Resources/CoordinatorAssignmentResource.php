<?php

namespace App\Http\Resources;

use App\Models\CoordinatorScopeAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One coordinator scope assignment for Staff administration (docs/06 §22b):
 * its public uuid, the level and target codes and names, and the history
 * (when, by whom by display name, why). Never an internal id.
 *
 * @mixin CoordinatorScopeAssignment
 */
class CoordinatorAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'scope_type' => $this->scope_type->value,
            'clan' => ['code' => $this->clan->code, 'name' => $this->clan->name],
            'branch_group' => $this->branchGroup ? ['code' => $this->branchGroup->code, 'name' => $this->branchGroup->name] : null,
            'branch' => $this->branch ? ['code' => $this->branch->code, 'name' => $this->branch->name] : null,
            'active' => $this->revoked_at === null,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'assigned_by' => $this->assigner?->name,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->revoker?->name,
            'revoke_reason' => $this->revoke_reason,
        ];
    }
}
