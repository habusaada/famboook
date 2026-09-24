<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned item of an Assistance (per beneficiary). Never a delivered
 * quantity and never stock.
 */
class AssistanceItem extends Model
{
    protected $fillable = [
        'assistance_id',
        'item_name',
        'quantity_per_beneficiary',
        'unit',
        'unit_value',
        'currency',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_beneficiary' => 'decimal:2',
            'unit_value' => 'decimal:2',
            'currency' => Currency::class,
        ];
    }

    public function assistance(): BelongsTo
    {
        return $this->belongsTo(Assistance::class);
    }
}
