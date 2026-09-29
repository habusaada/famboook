<?php

namespace App\Models;

use App\Enums\FamilyKeyDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An administrator's decision for one distinct source family key of an
 * import batch (docs/03 §96a). The staged source key is never rewritten; the
 * decision maps it to a Branch of the batch's Clan (branch_id is always the
 * FINAL Branch) or explicitly to no Branch. Absence of a record = unresolved.
 */
class ImportFamilyKeyResolution extends Model
{
    protected $fillable = [
        'import_batch_id',
        'clan_id',
        'source_family_key',
        'decision',
        'branch_id',
        'reference_source_key',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => FamilyKeyDecision::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
