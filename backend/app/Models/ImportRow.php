<?php

namespace App\Models;

use App\Enums\ImportReconciliationStatus;
use App\Enums\ImportRowStatus;
use App\Support\ImportRawPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staged source row (docs/02 §88a). raw_payload and normalized_payload
 * may contain National IDs (RESTRICTED): they are hidden from serialization
 * and must only ever be exposed through an authorized API Resource.
 */
class ImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'row_number',
        'raw_payload',
        'normalized_payload',
        'source_family_key',
        'status',
        'reconciliation_status',
        'issues',
        'family_id',
    ];

    // Both payloads may hold National IDs (RESTRICTED).
    protected $hidden = ['raw_payload', 'normalized_payload'];

    protected static function booted(): void
    {
        // Defense in depth for any write path: the excluded source fields
        // (هويتك, الديانة) are never persisted, whatever the parser does.
        static::saving(function (ImportRow $row) {
            ImportRawPayload::assertClean($row->raw_payload ?? []);
            ImportRawPayload::assertClean($row->normalized_payload ?? []);
            ImportRawPayload::assertClean($row->issues ?? []);
        });
    }

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'status' => ImportRowStatus::class,
            // Reserved for future reconciliation; NULL = not reconciled.
            'reconciliation_status' => ImportReconciliationStatus::class,
            'issues' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /** The Family this row created, once APPLIED. */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function scopeWithStatus(Builder $query, ImportRowStatus $status): void
    {
        $query->where('status', $status);
    }
}
