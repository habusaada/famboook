<?php

namespace App\Http\Requests\Api\V1;

/**
 * Organizational scope of the Operational Dashboard (see ScopedRequest).
 */
class DashboardRequest extends ScopedRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('dashboard.view-operational') ?? false;
    }
}
