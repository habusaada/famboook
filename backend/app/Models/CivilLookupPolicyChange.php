<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change of a Civil Registry Lookup role policy or per-user access
 * (FB-CIVIL-02A, ADR §L-10). Append-only: refused here and, on PostgreSQL,
 * by a trigger.
 */
class CivilLookupPolicyChange extends Model
{
    public const ROLE = 'ROLE';

    public const USER = 'USER';

    public const UPDATED_AT = null;

    public const CREATED_AT = 'changed_at';

    protected $fillable = ['subject_type', 'role_name', 'user_id', 'old_enabled', 'new_enabled', 'old_daily_limit', 'new_daily_limit',
        'old_access', 'new_access', 'old_sides', 'new_sides', 'changed_by'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Civil lookup policy changes are immutable.');
        });
        static::deleting(function () {
            throw new LogicException('Civil lookup policy changes cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'old_enabled' => 'boolean',
            'new_enabled' => 'boolean',
            'old_daily_limit' => 'integer',
            'new_daily_limit' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
