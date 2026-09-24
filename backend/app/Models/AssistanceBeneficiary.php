<?php

namespace App\Models;

use App\Enums\BeneficiaryStatus;
use App\Enums\NominationSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A nominee of an Assistance (docs/02-DATA-DICTIONARY.md §36c): a family
 * (person_id NULL) or one of its members selected as a POTENTIAL
 * beneficiary. Not proof of receipt. Never deleted: removal sets status
 * REMOVED so the history survives for V1-B approval/delivery.
 */
class AssistanceBeneficiary extends Model
{
    use HasUuids;

    protected $fillable = [
        'assistance_id',
        'family_id',
        'person_id',
        'source_need_id',
        'nomination_source',
        'targeting_criteria',
        'status',
        'nominated_at',
        'nominated_by',
        'removed_at',
        'removed_by',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'not_delivered_at',
        'not_delivered_by',
        'not_delivered_reason',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Nominations are never deleted; they are marked REMOVED.');
        });
    }

    protected function casts(): array
    {
        return [
            'nomination_source' => NominationSource::class,
            'status' => BeneficiaryStatus::class,
            'targeting_criteria' => 'array',
            'nominated_at' => 'datetime',
            'removed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'not_delivered_at' => 'datetime',
        ];
    }

    /** The auto-generated UUID is a public key only; `id` stays the PK. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function assistance(): BelongsTo
    {
        return $this->belongsTo(Assistance::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function sourceNeed(): BelongsTo
    {
        return $this->belongsTo(FamilyNeed::class, 'source_need_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AssistanceDelivery::class)->latest('delivered_at')->latest('id');
    }

    /** The current (non-reversed) delivery, if any. */
    public function activeDelivery(): HasOne
    {
        return $this->hasOne(AssistanceDelivery::class)->whereNull('reversed_at');
    }

    public function listEntries(): HasMany
    {
        return $this->hasMany(AssistanceBeneficiaryListEntry::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function notDeliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'not_delivered_by');
    }

    public function nominator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominated_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
