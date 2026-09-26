<?php

namespace App\Http\Resources;

use App\Support\StaffRoles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The authenticated Staff user for frontend rendering (GET /api/v1/me,
 * docs/06 §13). Name, email, the single Staff role and the effective
 * permission names — never ids, hashes, tokens or timestamps. UX only:
 * the API still authorizes every request.
 *
 * @mixin \App\Models\User
 */
class CurrentUserResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = $this->getRoleNames()->first();

        return [
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role,
            'role_label' => StaffRoles::LABELS[$role] ?? null,
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
        ];
    }
}
