<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One civil identity import management event (FB-CIVIL-01A-UI, ADR §8).
 * Append-only: written only through App\Support\CivilImport\CivilImportAudit,
 * never updated or deleted — refused here and, on PostgreSQL, by a trigger.
 * Never holds an identity value, a file name, a path or SQL.
 */
class CivilImportAuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'occurred_at';

    protected $fillable = [
        'event_type',
        'actor_user_id',
        'actor_side',
        'run_id',
        'release_id',
        'request_id',
        'publication_request_id',
        'outcome_code',
        'details',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Civil import audit events are immutable.');
        });
        static::deleting(function () {
            throw new LogicException('Civil import audit events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'release_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
