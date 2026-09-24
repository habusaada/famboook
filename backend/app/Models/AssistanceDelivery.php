<?php

namespace App\Models;

use App\Enums\ReceiptMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An INTERNAL, identity-verified receipt of the FULL planned package by
 * one beneficiary (docs/02-DATA-DICTIONARY.md §36e). No National ID is
 * stored. Immutable except for a single reversal; never deleted.
 */
class AssistanceDelivery extends Model
{
    use HasUuids;

    private const REVERSAL_FIELDS = ['reversed_at', 'reversed_by', 'reversal_reason', 'updated_at'];

    protected $fillable = [
        'assistance_beneficiary_id',
        'receipt_mode',
        'original_beneficiary_person_id',
        'recipient_person_id',
        'delivered_at',
        'delivered_by',
        'notes',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    protected static function booted(): void
    {
        static::updating(function (AssistanceDelivery $delivery) {
            $onlyReversal = array_diff(array_keys($delivery->getDirty()), self::REVERSAL_FIELDS) === [];
            if ($delivery->getRawOriginal('reversed_at') !== null || ! $onlyReversal) {
                throw new LogicException('Deliveries are immutable; only a single reversal may be recorded.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Deliveries are never deleted; reverse them instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'receipt_mode' => ReceiptMode::class,
            'delivered_at' => 'datetime',
            'reversed_at' => 'datetime',
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

    public function isActive(): bool
    {
        return $this->reversed_at === null;
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(AssistanceBeneficiary::class, 'assistance_beneficiary_id');
    }

    public function originalBeneficiary(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'original_beneficiary_person_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'recipient_person_id');
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
