<?php

namespace App\Models;

use App\Enums\NotificationFollowupOutcome;
use App\Enums\NotificationFollowupReason;
use App\Enums\NotificationFollowupStage;
use App\Enums\NotificationFollowupStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One follow-up stage of a recipient (ADR FB-NOTIFY-01 ADR-05/06/07): at
 * most one per recipient and stage. A closed follow-up is never reopened or
 * changed; a manual closure never counts as a family view or
 * acknowledgement. Follow-ups are never deleted.
 */
class NotificationFollowup extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected $hidden = [
        'id',
        'recipient_id',
        'closed_by_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (NotificationFollowup $followup) {
            if ($followup->getRawOriginal('status') === NotificationFollowupStatus::CLOSED->value) {
                throw new LogicException('A closed follow-up is never reopened or changed.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Notification follow-ups are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'stage' => NotificationFollowupStage::class,
            'trigger_reason' => NotificationFollowupReason::class,
            'status' => NotificationFollowupStatus::class,
            'outcome' => NotificationFollowupOutcome::class,
            'due_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** The auto-generated UUID is the public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(NotificationRecipientEvent::class, 'followup_id');
    }
}
