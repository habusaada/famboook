<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reconciliation evidence for one staged row (docs/03 §96a): the HEAD Person
 * match and the Family match (separate concepts), spouse candidates,
 * differences and issues. The row's authoritative state is
 * import_rows.reconciliation_status. Never a registry write.
 */
class ImportRowReconciliation extends Model
{
    protected $fillable = [
        'import_row_id',
        'import_batch_id',
        'head_match',
        'head_person_id',
        'family_match',
        'family_id',
        'spouse_matches',
        'differences',
        'issues',
    ];

    protected function casts(): array
    {
        return [
            'spouse_matches' => 'array',
            'differences' => 'array',
            'issues' => 'array',
        ];
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'import_row_id');
    }

    public function headPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'head_person_id')->withTrashed();
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
