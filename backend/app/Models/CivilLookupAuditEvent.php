<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One Civil Registry Lookup event (FB-CIVIL-02A, ADR §L-10). Append-only:
 * written only through App\Support\CivilLookup\CivilLookupAudit, never updated
 * or deleted — refused here and, on PostgreSQL, by a trigger. Never holds a
 * National ID, a name, a search term or a payload.
 */
class CivilLookupAuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'occurred_at';

    protected $fillable = ['event_type', 'actor_user_id', 'actor_side', 'outcome_code', 'release_id', 'record_id',
        'query_fingerprint', 'result_count', 'quota_used_after', 'ip_hash'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Civil lookup audit events are immutable.');
        });
        static::deleting(function () {
            throw new LogicException('Civil lookup audit events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'release_id' => 'integer',
            'record_id' => 'integer',
            'result_count' => 'integer',
            'quota_used_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
