<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Civil Registry Lookup policy of one role (FB-CIVIL-02A, ADR §L-6):
 * enabled, and the daily limit (NULL = unlimited, SUPER_ADMIN only). Changed
 * only through UpdateCivilLookupRolePolicyAction, which records the change.
 */
class CivilLookupRolePolicy extends Model
{
    protected $fillable = ['role_name', 'enabled', 'daily_limit', 'unlimited_allowed', 'updated_by'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_limit' => 'integer',
            'unlimited_allowed' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
