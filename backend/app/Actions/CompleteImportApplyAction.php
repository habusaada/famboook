<?php

namespace App\Actions;

use App\Enums\ImportApplyIntent;
use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportApplyExecutionException;
use App\Models\Family;
use App\Models\ImportBatch;
use App\Support\Import\Apply\ApprovedApplyPlan;
use App\Support\Import\Apply\ImportApplyPlanner;
use App\Support\Import\Apply\ImportApplyPlanningContext;
use App\Support\Import\Apply\ImportApplyProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Database-backed completion of an Apply (docs/03 §96b). It VERIFIES — it
 * never writes a missing effect: a batch becomes APPLIED only when every
 * planned row is APPLIED with an existing Family and every planned effect has
 * exactly one matching provenance record (outcome, entity), no record is
 * unexpected, no cross-row link is still pending, and the plan reconstructed
 * as of Apply start still equals apply_plan_fingerprint.
 *
 * Also the one gate every chunk / resume passes through (approvedPlan()).
 */
class CompleteImportApplyAction
{
    public function __construct(private readonly ImportApplyPlanner $planner = new ImportApplyPlanner) {}

    /** The approved plan, reconstructed as of Apply start; a changed plan is APPLY_PLAN_CHANGED. */
    public function approvedPlan(ImportBatch $batch): ApprovedApplyPlan
    {
        $plan = $this->planner->plan($batch, ImportApplyPlanningContext::asOfApplyStart($batch));
        try {
            if (! $plan->preconditionsMet()) {
                throw new ImportApplyExecutionException('APPLY_PLAN_CHANGED');
            }

            return ApprovedApplyPlan::verify($batch, $plan);
        } catch (ImportApplyExecutionException) {
            throw new ImportApplyExecutionException('APPLY_PLAN_CHANGED');
        }
    }

    /** APPLYING → APPLIED, only when the database proves the whole plan executed. */
    public function handle(ImportBatch $batch, int $executed = 0, int $skipped = 0): ImportApplyProgress
    {
        DB::transaction(function () use ($batch) {
            /** @var ImportBatch $locked */
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ImportBatchStatus::APPLYING) {
                throw new ImportApplyExecutionException('APPLY_STATE_INVALID');
            }
            $problems = $this->problems($locked, $this->approvedPlan($locked), requireComplete: true);
            if ($problems !== []) {
                Log::warning('Import apply completion refused', ['batch_id' => $locked->id, 'problems' => $problems]);
                throw new ImportApplyExecutionException('APPLY_COMPLETION_INCOMPLETE');
            }
            $locked->update(['status' => ImportBatchStatus::APPLIED, 'applied_at' => now(), 'apply_error_code' => null, 'apply_error_row_number' => null]);
        });

        return ImportApplyProgress::of($batch, 'COMPLETED', $executed, $skipped, 0);
    }

    /** An APPLIED batch is re-verified, never re-executed or repaired. */
    public function verifyApplied(ImportBatch $batch): ImportApplyProgress
    {
        try {
            $problems = $this->problems($batch, $this->approvedPlan($batch), requireComplete: true);
        } catch (ImportApplyExecutionException) {
            $problems = ['APPLY_PLAN_CHANGED'];
        }
        if ($problems !== []) {
            Log::warning('Applied import batch is inconsistent', ['batch_id' => $batch->id, 'problems' => $problems]);
            throw new ImportApplyExecutionException('APPLY_INTEGRITY_INVALID');
        }

        return ImportApplyProgress::of($batch, 'ALREADY_APPLIED', pendingLinks: 0);
    }

    /**
     * Stable problem codes (empty = consistent). With $requireComplete=false
     * (resume) unapplied rows are allowed, and a cross-row REUSE may be
     * pending only while its owner has not created the Person.
     *
     * @return list<string>
     */
    public function problems(ImportBatch $batch, ApprovedApplyPlan $plan, bool $requireComplete): array
    {
        $problems = [];
        $rows = DB::table('import_rows')->where('import_batch_id', $batch->id)->get(['id', 'status', 'family_id'])->keyBy('id');
        $records = [];
        foreach (DB::table('import_apply_records')->where('import_batch_id', $batch->id)->get(['import_row_id', 'effect_key', 'outcome', 'entity_id']) as $r) {
            if (isset($records[$r->import_row_id][$r->effect_key])) {
                $problems[] = 'PROVENANCE_DUPLICATE';
            }
            $records[$r->import_row_id][$r->effect_key] = $r;
        }
        $planned = [];
        foreach ($plan->plan->rows as $rowPlan) {
            $planned[$rowPlan->importRowId] = $rowPlan;
        }
        if (array_diff_key($rows->all(), $planned) !== [] || array_diff_key($records, $planned) !== []) {
            $problems[] = 'UNEXPECTED_ROW_OR_PROVENANCE';
        }

        $families = [];
        foreach ($planned as $rowId => $rowPlan) {
            $row = $rows[$rowId] ?? null;
            $rowRecords = $records[$rowId] ?? [];
            if ($row === null) {
                $problems[] = 'ROW_MISSING';

                continue;
            }
            if (! $rowPlan->executable()) {
                $problems[] = 'PLAN_ROW_BLOCKED';
            }
            if ($row->status !== 'APPLIED') {
                if ($requireComplete) {
                    $problems[] = 'ROW_NOT_APPLIED';
                }
                if ($rowRecords !== []) {
                    $problems[] = 'PROVENANCE_WITHOUT_APPLIED_ROW';
                }

                continue;
            }
            if ($row->family_id === null || ! Family::query()->whereKey($row->family_id)->exists()) {
                $problems[] = 'FAMILY_MISSING';
            }
            $families[] = (int) $row->family_id;
            if (array_diff_key($rowRecords, $rowPlan->effects) !== []) {
                $problems[] = 'UNEXPECTED_PROVENANCE';
            }
            foreach ($rowPlan->effects as $key => $effect) {
                $record = $rowRecords[$key] ?? null;
                if ($record === null) {
                    $crossRow = $effect->intent === ImportApplyIntent::REUSE && $effect->ownerRow !== null;
                    $ownerCreated = $crossRow && isset($records[$plan->row($effect->ownerRow)->importRowId][$effect->ownerEffect->value]);
                    if (! $crossRow) {
                        $problems[] = 'PROVENANCE_MISSING';
                    } elseif ($requireComplete || $ownerCreated) {
                        $problems[] = 'CROSS_ROW_LINK_PENDING';
                    }

                    continue;
                }
                if ($record->outcome !== $effect->intent->outcome()?->value) {
                    $problems[] = 'PROVENANCE_OUTCOME_MISMATCH';
                }
                $referencesEntity = in_array($record->outcome, ['CREATED', 'REUSED'], true);
                if ($referencesEntity !== ($record->entity_id !== null)
                    || ($effect->existingId !== null && (int) $record->entity_id !== $effect->existingId)
                    || ($key === 'FAMILY' && (int) $record->entity_id !== (int) $row->family_id)) {
                    $problems[] = 'PROVENANCE_ENTITY_MISMATCH';
                }
            }
        }
        if (count($families) !== count(array_unique($families))) {
            $problems[] = 'FAMILY_SHARED_BY_ROWS';
        }

        return array_values(array_unique($problems));
    }

    /** Cross-row REUSE effects of APPLIED rows still waiting for their owner. */
    public function pendingLinks(ImportBatch $batch, ApprovedApplyPlan $plan): int
    {
        $applied = DB::table('import_rows')->where('import_batch_id', $batch->id)->where('status', 'APPLIED')->pluck('id')->flip();
        $recorded = DB::table('import_apply_records')->where('import_batch_id', $batch->id)->get(['import_row_id', 'effect_key'])
            ->map(fn ($r) => $r->import_row_id.'|'.$r->effect_key)->flip();
        $pending = 0;
        foreach ($plan->plan->rows as $rowPlan) {
            if (! isset($applied[$rowPlan->importRowId])) {
                continue;
            }
            foreach ($rowPlan->effects as $key => $effect) {
                if ($effect->intent === ImportApplyIntent::REUSE && $effect->ownerRow !== null && ! isset($recorded[$rowPlan->importRowId.'|'.$key])) {
                    $pending++;
                }
            }
        }

        return $pending;
    }
}
