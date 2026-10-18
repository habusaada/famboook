<?php

namespace App\Models;

use App\Enums\FamilyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A family recipient of a published message (ADR FB-NOTIFY-01 ADR-03/05/07).
 * Written only by the publication transaction; the family, head, account
 * and family-status columns are publication-time snapshots, never rewritten.
 * viewed_* and acknowledged_* are recorded only by the family side; staff
 * and coordinators never write them. Recipients are never deleted.
 */
class NotificationRecipient extends Model
{
    protected $fillable = [];

    protected $hidden = [
        'id',
        'message_id',
        'head_person_id',
        'viewed_by_user_id',
        'acknowledged_by_user_id',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Notification recipients are never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'family_status_at_publication' => FamilyStatus::class,
            'had_family_account' => 'boolean',
            'acknowledged_late' => 'boolean',
            'viewed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(NotificationMessage::class, 'message_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function headPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'head_person_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(NotificationFollowup::class, 'recipient_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(NotificationRecipientEvent::class, 'recipient_id');
    }
}
