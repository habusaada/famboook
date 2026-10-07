<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use App\Enums\WorkflowActorSide;
use App\Enums\WorkflowEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One workflow transition (docs/04 §33, PWA-5a). Append-only: written only
 * through App\Support\ChangeRequests\WorkflowEventRecorder, never updated or
 * deleted — refused here and, on PostgreSQL, by a table trigger (AE-18).
 *
 * public_message may be shown to the family; internal_note is Staff-only
 * (change-request.view-internal-notes) and is hidden from serialization.
 * Statuses are Change Request statuses: workflow_events serves only Change
 * Requests today (morph alias 'change_request').
 */
class WorkflowEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workflowable_type',
        'workflowable_id',
        'from_status',
        'to_status',
        'event_type',
        'actor_user_id',
        'actor_side',
        'reason_code',
        'public_message',
        'internal_note',
        'metadata',
    ];

    protected $hidden = [
        'id',
        'workflowable_id',
        'internal_note',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Workflow events are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Workflow events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'from_status' => ChangeRequestStatus::class,
            'to_status' => ChangeRequestStatus::class,
            'event_type' => WorkflowEventType::class,
            'actor_side' => WorkflowActorSide::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function workflowable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
