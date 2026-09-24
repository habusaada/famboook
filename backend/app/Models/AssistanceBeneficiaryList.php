<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A beneficiary list issued to an external organization (EXTERNAL
 * Assistance, docs/02-DATA-DICTIONARY.md §36f). Immutable: never updated or
 * deleted. Issuing a list is NOT a delivery.
 */
class AssistanceBeneficiaryList extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'assistance_id',
        'list_number',
        'recipient_organization',
        'issued_at',
        'issued_by',
        'notes',
        'configuration_snapshot',
        'contains_sensitive',
        'row_count',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Issued beneficiary lists are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Issued beneficiary lists cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'configuration_snapshot' => 'array',
            'contains_sensitive' => 'boolean',
            'row_count' => 'integer',
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

    public function entries(): HasMany
    {
        return $this->hasMany(AssistanceBeneficiaryListEntry::class)->orderBy('row_number');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
