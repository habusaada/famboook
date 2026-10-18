<?php

namespace App\Models;

use App\Enums\NotificationEventType;
use App\Enums\WorkflowActorSide;
use App\Support\Notifications\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry of the append-only notification history (FB-NOTIFY-01B.2a):
 * message-, recipient- or follow-up-level. Never updated or deleted:
 * refused here and, on PostgreSQL, by a table trigger. Viewing and
 * acknowledgement events belong to the family side alone. internal_note is
 * Staff / coordinator only and hidden from serialization; a NOTE_CORRECTION
 * suppresses a note in application views without changing it. metadata
 * holds codes and counts only.
 */
class NotificationRecipientEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'message_id',
        'recipient_id',
        'followup_id',
        'event_type',
        'actor_user_id',
        'actor_side',
        'reason_code',
        'internal_note',
        'corrects_event_id',
        'metadata',
    ];

    protected $attributes = [
        'metadata' => '{}',
    ];

    protected $hidden = [
        'id',
        'message_id',
        'recipient_id',
        'followup_id',
        'actor_user_id',
        'internal_note',
        'corrects_event_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (NotificationRecipientEvent $event) {
            // No acting for the family (ADR-05); chk_nre_family_actor holds
            // the same rule on PostgreSQL.
            if ($event->event_type->isFamilyAction() !== ($event->actor_side === WorkflowActorSide::FAMILY)) {
                throw new LogicException('Viewing and acknowledgement are recorded only by the family side.');
            }
        });

        static::updating(function () {
            throw new LogicException('Notification events are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Notification events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => NotificationEventType::class,
            'actor_side' => WorkflowActorSide::class,
            'metadata' => JsonObject::class,
            'created_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(NotificationMessage::class, 'message_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'recipient_id');
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(NotificationFollowup::class, 'followup_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function correctedEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_event_id');
    }
}
