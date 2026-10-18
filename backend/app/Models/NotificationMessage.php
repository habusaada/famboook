<?php

namespace App\Models;

use App\Enums\NotificationKind;
use App\Enums\NotificationLevel;
use App\Enums\NotificationSenderSide;
use App\Enums\NotificationStatus;
use App\Support\Notifications\JsonObject;
use App\Support\Notifications\NotificationCodes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One notification message (ADR FB-NOTIFY-01, FB-NOTIFY-01B.2a): a SYSTEM
 * notice derived from a workflow event, or an ANNOUNCEMENT by Staff or a
 * coordinator, with its publication lifecycle.
 *
 * - `uuid` is the public identifier; `notice_code` (NTF-000001) the human
 *   reference; `id` never leaves the server.
 * - The lifecycle columns (status, publication, failure, archive and
 *   cancellation fields, recipient_count) are not mass assignable: only the
 *   future publication Domain Actions move them, and PostgreSQL CHECKs guard
 *   every combination.
 * - Messages are never deleted.
 */
class NotificationMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'kind',
        'sender_side',
        'level',
        'title',
        'body',
        'action_path',
        'sender_user_id',
        'sender_scope',
        'audience',
        'client_reference',
        'origin_workflow_event_id',
        'expires_at',
    ];

    /** Mirrors the column defaults. */
    protected $attributes = [
        'level' => 'NORMAL',
        'status' => 'DRAFT',
        'audience' => '{}',
        'publish_attempts' => 0,
    ];

    protected $hidden = [
        'id',
        'sender_user_id',
        'sender_scope',
        'audience',
        'origin_workflow_event_id',
        'publishing_token',
        'cancelled_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (NotificationMessage $message) {
            $message->notice_code ??= NotificationCodes::next();
        });

        static::deleting(function () {
            throw new LogicException('Notification messages are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => NotificationKind::class,
            'sender_side' => NotificationSenderSide::class,
            'level' => NotificationLevel::class,
            'status' => NotificationStatus::class,
            'sender_scope' => JsonObject::class,
            'audience' => JsonObject::class,
            'recipient_count' => 'integer',
            'publish_attempts' => 'integer',
            'queued_at' => 'datetime',
            'publishing_started_at' => 'datetime',
            'publish_failed_at' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'archived_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function originEvent(): BelongsTo
    {
        return $this->belongsTo(WorkflowEvent::class, 'origin_workflow_event_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'message_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(NotificationRecipientEvent::class, 'message_id');
    }
}
