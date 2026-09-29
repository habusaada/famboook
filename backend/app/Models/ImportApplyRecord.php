<?php

namespace App\Models;

use App\Enums\ImportApplyEffect;
use App\Enums\ImportApplyOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * Append-only provenance of ONE intended effect of one import row (docs/03
 * §96b, docs/04 §83d): which row/batch produced a registry entity, whether it
 * was CREATED or REUSED, or why it was OMITTED / BLOCKED. Only ids, stable
 * codes and a user — never a National ID, name or source value.
 *
 * Written only through record(); the row/effect pair is unique, so a retried
 * or resumed Apply can never record the same effect twice. The invariants
 * below mirror the PostgreSQL CHECKs so they also hold on SQLite.
 */
class ImportApplyRecord extends Model
{
    public const UPDATED_AT = null;

    private const REASON = '/^[A-Z][A-Z0-9_]{1,59}$/';

    protected $fillable = [
        'import_batch_id',
        'import_row_id',
        'effect_key',
        'entity_type',
        'entity_id',
        'role',
        'spouse_slot',
        'outcome',
        'reason_code',
        'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'effect_key' => ImportApplyEffect::class,
            'outcome' => ImportApplyOutcome::class,
            'entity_id' => 'integer',
            'spouse_slot' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $record) => $record->assertValid());
        static::updating(fn () => throw new LogicException('Import apply records are append-only.'));
        static::deleting(fn () => throw new LogicException('Import apply records are append-only.'));
    }

    /** The only write path: entity_type / role / slot are derived from the effect. */
    public static function record(ImportRow $row, ImportApplyEffect $effect, ImportApplyOutcome $outcome, ?Model $entity, ?string $reasonCode, int $appliedBy): self
    {
        return self::create([
            'import_batch_id' => $row->import_batch_id,
            'import_row_id' => $row->id,
            'effect_key' => $effect,
            'entity_type' => $effect->entityType(),
            'entity_id' => $entity?->getKey(),
            'role' => $effect->role(),
            'spouse_slot' => $effect->spouseSlot(),
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'applied_by' => $appliedBy,
        ]);
    }

    private function assertValid(): void
    {
        $effect = $this->effect_key;
        $outcome = $this->outcome;
        if (! $effect instanceof ImportApplyEffect || ! $outcome instanceof ImportApplyOutcome) {
            throw new LogicException('Unknown import apply effect or outcome.');
        }
        if ($this->entity_type !== $effect->entityType() || $this->role !== $effect->role() || $this->spouse_slot !== $effect->spouseSlot()) {
            throw new LogicException('Import apply record shape does not match its effect.');
        }
        if ($outcome->referencesEntity() && $this->entity_id === null) {
            throw new LogicException('CREATED / REUSED must reference an entity.');
        }
        if ($outcome === ImportApplyOutcome::OMITTED && $this->entity_id !== null) {
            throw new LogicException('OMITTED never references an entity.');
        }
        if ($this->reason_code !== null && ! preg_match(self::REASON, $this->reason_code)) {
            throw new LogicException('A reason is a stable code, never a value.');
        }
        if (! $outcome->referencesEntity() && $this->reason_code === null) {
            throw new LogicException('OMITTED / BLOCKED must carry a reason code.');
        }
        if ($this->entity_id !== null) {
            $model = ImportApplyEffect::modelFor($this->entity_type);
            $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
            if (! $query->whereKey($this->entity_id)->exists()) {
                throw new LogicException('The referenced registry entity does not exist.');
            }
        }
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'import_row_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /** The referenced registry entity, soft-deleted ones included. */
    public function entity(): ?Model
    {
        if ($this->entity_id === null) {
            return null;
        }
        $model = ImportApplyEffect::modelFor($this->entity_type);
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();

        return $query->find($this->entity_id);
    }
}
