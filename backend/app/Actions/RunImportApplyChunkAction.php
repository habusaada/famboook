<?php

namespace App\Actions;

use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportApplyExecutionException;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Support\Import\Apply\ApplyChunkBudget;
use App\Support\Import\Apply\ApplyRunnerLock;
use App\Support\Import\Apply\ImportApplyLifecycle;
use App\Support\Import\Apply\ImportApplyProgress;
use App\Support\Import\Apply\ImportRowApplyResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Apply runner (docs/03 §96b): the ONE execution path for future HTTP
 * and CLI callers. Orchestration only — the planner decides, the row
 * executor executes; nothing here writes registry data itself.
 *
 * A chunk (continue): one runner per batch (ApplyRunnerLock, never waits);
 * the approved plan is reconstructed as of Apply start and must still match
 * apply_plan_fingerprint; rows run one by one in source order, EACH in its
 * own transaction, until the budget (100 rows / ~10 s), a failure, or the
 * end; with no row left the completion is verified.
 *
 * Stopping between chunks is a normal pause (APPLYING, no error). A failure
 * with no committed row returns the batch to READY_FOR_REVIEW; after one
 * committed row it becomes PARTIALLY_APPLIED — never FAILED. Only a stable
 * code and a row number are stored; causes go to the log by class name only
 * (their messages may contain identity data).
 */
class RunImportApplyChunkAction
{
    public function __construct(
        private readonly ApplyRunnerLock $lock,
        private readonly ApplyImportRowAction $rows = new ApplyImportRowAction,
        private readonly CompleteImportApplyAction $completion = new CompleteImportApplyAction,
    ) {}

    /** Continue an APPLYING batch (or re-verify an APPLIED one). */
    public function handle(ImportBatch $batch, User $user, ?ApplyChunkBudget $budget = null): ImportApplyProgress
    {
        $this->authorize($user);

        return $this->exclusively($batch, fn () => $this->chunk($batch->fresh(), $user, $budget ?? new ApplyChunkBudget));
    }

    /** Resume a PARTIALLY_APPLIED batch through the same chunk path. */
    public function resume(ImportBatch $batch, User $user, ?ApplyChunkBudget $budget = null): ImportApplyProgress
    {
        $this->authorize($user);

        return $this->exclusively($batch, function () use ($batch, $user, $budget) {
            try {
                DB::transaction(function () use ($batch) {
                    /** @var ImportBatch $locked */
                    $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status !== ImportBatchStatus::PARTIALLY_APPLIED) {
                        throw new ImportApplyExecutionException('APPLY_STATE_INVALID');
                    }
                    // Same stored plan, same apply_started_at — never re-reconciled.
                    $plan = $this->completion->approvedPlan($locked);
                    $problems = $this->completion->problems($locked, $plan, requireComplete: false);
                    if ($problems !== []) {
                        Log::warning('Import apply resume refused', ['batch_id' => $locked->id, 'problems' => $problems]);
                        throw new ImportApplyExecutionException('APPLY_INTEGRITY_INVALID');
                    }
                    $locked->update(['status' => ImportBatchStatus::APPLYING, 'apply_error_code' => null, 'apply_error_row_number' => null]);
                });
            } catch (ImportApplyExecutionException $e) {
                if ($e->errorCode !== 'APPLY_STATE_INVALID') {
                    // Still PARTIALLY_APPLIED; record why resume was refused
                    // through the model (invariants and CHECKs apply).
                    ImportApplyLifecycle::recordPartialError($batch, $e->errorCode);
                }
                throw $e;
            }

            return $this->chunk($batch->fresh(), $user, $budget ?? new ApplyChunkBudget);
        });
    }

    private function authorize(User $user): void
    {
        // import.apply is SUPER_ADMIN-only once assigned (not assigned yet).
        if (! $user->can('import.apply')) {
            throw new AuthorizationException('لا تملك صلاحية اعتماد الاستيراد.');
        }
    }

    private function exclusively(ImportBatch $batch, callable $run): ImportApplyProgress
    {
        if (! $this->lock->acquire($batch->id)) {
            throw new ImportApplyExecutionException('APPLY_IN_PROGRESS');
        }
        try {
            return $run();
        } finally {
            $this->lock->release($batch->id);
        }
    }

    private function chunk(ImportBatch $batch, User $user, ApplyChunkBudget $budget): ImportApplyProgress
    {
        if ($batch->status === ImportBatchStatus::APPLIED) {
            return $this->completion->verifyApplied($batch);
        }
        if ($batch->status !== ImportBatchStatus::APPLYING) {
            throw new ImportApplyExecutionException('APPLY_STATE_INVALID');
        }

        try {
            $plan = $this->completion->approvedPlan($batch);
        } catch (ImportApplyExecutionException $e) {
            return $this->fail($batch, $e->errorCode, null);
        }

        $budget->start();
        $executed = 0;
        $skipped = 0;
        $rows = ImportRow::query()->where('import_batch_id', $batch->id)->where('status', '!=', 'APPLIED')
            ->orderBy('row_number')->orderBy('id')->limit($budget->maxRows)->get();
        foreach ($rows as $row) {
            if ($budget->exhausted($executed + $skipped)) {
                break;
            }
            try {
                // Its own transaction; a failure never touches earlier rows.
                $result = $this->rows->handle($plan, $row, $user);
            } catch (ImportApplyExecutionException $e) {
                return $this->fail($batch, $e->errorCode, $e->rowNumber ?? $row->row_number, $executed, $skipped, $e);
            }
            $result->outcome === ImportRowApplyResult::ALREADY_APPLIED ? $skipped++ : $executed++;
        }

        if (! ImportRow::query()->where('import_batch_id', $batch->id)->where('status', '!=', 'APPLIED')->exists()) {
            try {
                return $this->completion->handle($batch, $executed, $skipped);
            } catch (ImportApplyExecutionException $e) {
                return $this->fail($batch, $e->errorCode, null, $executed, $skipped, $e);
            } catch (Throwable $e) {
                return $this->fail($batch, 'UNEXPECTED_ERROR', null, $executed, $skipped, $e);
            }
        }

        // A normal pause: still APPLYING, no error.
        return ImportApplyProgress::of($batch, 'PAUSED', $executed, $skipped, $this->completion->pendingLinks($batch, $plan));
    }

    /**
     * Records a stop by DATABASE truth: nothing committed → READY_FOR_REVIEW
     * (approval cleared); otherwise PARTIALLY_APPLIED (plan and start kept).
     */
    private function fail(ImportBatch $batch, string $code, ?int $rowNumber, int $executed = 0, int $skipped = 0, ?Throwable $e = null): ImportApplyProgress
    {
        $cause = $e?->getPrevious() ?? $e;
        Log::warning('Import apply stopped', ['batch_id' => $batch->id, 'code' => $code, 'row_number' => $rowNumber, 'cause' => $cause ? $cause::class : null]);

        DB::transaction(function () use ($batch, $code, $rowNumber) {
            /** @var ImportBatch $locked */
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $rows = DB::table('import_rows')->where('import_batch_id', $locked->id);
            $committed = (clone $rows)->where('status', 'APPLIED')->exists()
                || (clone $rows)->whereNotNull('family_id')->exists()
                || DB::table('import_apply_records')->where('import_batch_id', $locked->id)->exists();
            $locked->update($committed
                ? ['status' => ImportBatchStatus::PARTIALLY_APPLIED, 'apply_error_code' => $code, 'apply_error_row_number' => $rowNumber]
                : ['status' => ImportBatchStatus::READY_FOR_REVIEW, 'apply_started_at' => null, 'apply_plan_fingerprint' => null,
                    'applied_by' => null, 'applied_at' => null, 'apply_error_code' => $code, 'apply_error_row_number' => $rowNumber]);
        });

        return ImportApplyProgress::of($batch, 'FAILED', $executed, $skipped);
    }
}
