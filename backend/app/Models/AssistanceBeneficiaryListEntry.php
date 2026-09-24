<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of an issued beneficiary list: exactly the values sent, frozen
 * at issuance. snapshot_data is highly sensitive and encrypted at rest; it
 * is only read by the authorized external-list endpoints and is never
 * regenerated from live data.
 */
class AssistanceBeneficiaryListEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'assistance_beneficiary_list_id',
        'assistance_beneficiary_id',
        'row_number',
        'snapshot_data',
    ];

    protected $hidden = ['snapshot_data'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Issued list entries are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Issued list entries cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'snapshot_data' => 'encrypted:array',
            'row_number' => 'integer',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(AssistanceBeneficiaryList::class, 'assistance_beneficiary_list_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(AssistanceBeneficiary::class, 'assistance_beneficiary_id');
    }
}
