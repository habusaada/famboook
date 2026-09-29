<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded import source file (docs/02 §88a, docs/04 §83a). Staging
 * only: nothing here is canonical registry data. Rows reach the canonical
 * tables only through Domain Actions after review (import.apply).
 */
class ImportBatch extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'source_filename',
        'source_checksum',
        'status',
        'row_count',
        'failure_reason',
        'uploaded_by',
        'applied_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'row_count' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('row_number');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
