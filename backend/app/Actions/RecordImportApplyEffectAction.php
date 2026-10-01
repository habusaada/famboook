<?php

namespace App\Actions;

use App\Enums\ImportApplyEffect;
use App\Enums\ImportApplyOutcome;
use App\Exceptions\ImportApplyExecutionException;
use App\Models\ImportApplyRecord;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Persists ONE Apply provenance record (docs/03 §96b) — no planning logic.
 * The shape (entity type, role, slot) comes from ImportApplyRecord::record().
 *
 * Idempotent only for an IDENTICAL record: if (row, effect) already exists
 * with the same outcome, entity and reason, that record is returned;
 * anything else is PROVENANCE_CONFLICT — never silently overwritten or
 * ignored. The insert runs in a savepoint so a unique violation raised by a
 * concurrent writer can be re-checked without aborting the row transaction.
 */
class RecordImportApplyEffectAction
{
    public function handle(ImportRow $row, ImportApplyEffect $effect, ImportApplyOutcome $outcome, ?Model $entity, ?string $reasonCode, int $appliedBy): ImportApplyRecord
    {
        $existing = $this->existing($row, $effect);
        if ($existing !== null) {
            return $this->equivalentOrFail($existing, $row, $outcome, $entity, $reasonCode);
        }

        try {
            return DB::transaction(fn () => ImportApplyRecord::record($row, $effect, $outcome, $entity, $reasonCode, $appliedBy));
        } catch (QueryException $e) {
            $existing = $this->existing($row, $effect);
            if ($existing === null) {
                throw $e;
            }

            return $this->equivalentOrFail($existing, $row, $outcome, $entity, $reasonCode);
        }
    }

    /** Protected so a test can simulate a concurrent writer (the savepoint path). */
    protected function existing(ImportRow $row, ImportApplyEffect $effect): ?ImportApplyRecord
    {
        return ImportApplyRecord::query()->where('import_row_id', $row->id)->where('effect_key', $effect->value)->first();
    }

    private function equivalentOrFail(ImportApplyRecord $existing, ImportRow $row, ImportApplyOutcome $outcome, ?Model $entity, ?string $reasonCode): ImportApplyRecord
    {
        if ($existing->outcome !== $outcome || $existing->entity_id !== $entity?->getKey() || $existing->reason_code !== $reasonCode) {
            throw new ImportApplyExecutionException('PROVENANCE_CONFLICT', $row->row_number);
        }

        return $existing;
    }
}
