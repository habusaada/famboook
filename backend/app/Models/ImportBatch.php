<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One uploaded import source file (docs/02 §88a, docs/04 §83a). Staging
 * only: nothing here is canonical registry data. Rows reach the canonical
 * tables only through Domain Actions after review (import.apply). Every
 * upload is its own batch; batches are never merged or replaced, so the
 * history of what each file contained is preserved.
 */
class ImportBatch extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'clan_id',
        'import_mode',
        'source_filename',
        'source_checksum',
        'source_size_bytes',
        'source_file_path',
        'worksheet_name',
        'inspection',
        'column_mapping',
        'mapping_confirmed_at',
        'reconciled_at',
        'reconciled_by',
        'reconciliation_fingerprint',
        'status',
        'row_count',
        'failure_reason',
        'uploaded_by',
        'applied_by',
        'applied_at',
        'apply_started_at',
    ];

    // The private storage path is internal.
    protected $hidden = ['source_file_path'];

    protected function casts(): array
    {
        return [
            'import_mode' => ImportMode::class,
            'status' => ImportBatchStatus::class,
            'row_count' => 'integer',
            'source_size_bytes' => 'integer',
            'inspection' => 'array',
            'column_mapping' => 'array',
            'mapping_confirmed_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'applied_at' => 'datetime',
            'apply_started_at' => 'datetime',
        ];
    }

    /**
     * Mirrors chk_import_batch_apply_started on every driver: a started Apply
     * is APPLYING / PARTIALLY_APPLIED / APPLIED and nothing else — in particular
     * never FAILED, which would release the file from checksum protection.
     */
    protected static function booted(): void
    {
        static::saving(function (self $batch) {
            if (($batch->apply_started_at !== null) !== ($batch->status?->applyStarted() ?? false)) {
                throw new LogicException('apply_started_at is set exactly when the batch is APPLYING, PARTIALLY_APPLIED or APPLIED.');
            }
        });
    }

    /** Staged rows exist from a confirmed mapping (Wizard step 3 done). */
    public function isStaged(): bool
    {
        return $this->mapping_confirmed_at !== null && $this->status === ImportBatchStatus::READY_FOR_REVIEW;
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The Clan this batch explicitly targets (never a default). */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('row_number');
    }

    /** Apply provenance (docs/03 §96b); empty until Apply exists and runs. */
    public function applyRecords(): HasMany
    {
        return $this->hasMany(ImportApplyRecord::class);
    }

    /** Family-key decisions (one per distinct source key; none = unresolved). */
    public function keyResolutions(): HasMany
    {
        return $this->hasMany(ImportFamilyKeyResolution::class);
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
