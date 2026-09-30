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
        'apply_plan_fingerprint',
        'apply_error_code',
        'apply_error_row_number',
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
            'apply_error_row_number' => 'integer',
        ];
    }

    /**
     * Mirrors the PostgreSQL CHECKs on every driver:
     * - chk_import_batch_apply_started: a started Apply is APPLYING /
     *   PARTIALLY_APPLIED / APPLIED and nothing else — in particular never
     *   FAILED, which would release the file from checksum protection;
     * - chk_import_batch_apply_plan: the approved plan fingerprint (64 hex)
     *   is present exactly while Apply has started;
     * - chk_import_batch_apply_error: a failure is a stable code (never
     *   exception text) with an optional source row number.
     */
    protected static function booted(): void
    {
        static::saving(function (self $batch) {
            $started = $batch->status?->applyStarted() ?? false;
            if (($batch->apply_started_at !== null) !== $started) {
                throw new LogicException('apply_started_at is set exactly when the batch is APPLYING, PARTIALLY_APPLIED or APPLIED.');
            }
            $fingerprint = $batch->apply_plan_fingerprint;
            if (($fingerprint !== null) !== $started || ($fingerprint !== null && ! preg_match('/^[0-9a-f]{64}$/', $fingerprint))) {
                throw new LogicException('apply_plan_fingerprint is a SHA-256 hex present exactly while Apply has started.');
            }
            $code = $batch->apply_error_code;
            $row = $batch->apply_error_row_number;
            if (($code !== null && ! preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $code)) || ($row !== null && ($code === null || $row < 1))) {
                throw new LogicException('An Apply error is a stable code with an optional source row number — never exception text.');
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
