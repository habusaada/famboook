<?php

namespace App\Actions;

use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportApplyExecutionException;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\Import\Apply\ImportApplyGate;
use App\Support\Import\Apply\ImportApplyPlanner;
use App\Support\Import\Apply\ImportApplyProgress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts an INITIAL Apply (docs/03 §96b) in ONE short transaction and
 * executes NO row: the operator's Dry Run fingerprint must equal a fresh plan
 * from the same planner; the batch then becomes APPLYING with
 * apply_started_at (the date of every Family it will register), applied_by
 * and the approved apply_plan_fingerprint. Any refusal leaves the batch
 * READY_FOR_REVIEW (the transaction never commits).
 */
class StartImportApplyAction
{
    public function __construct(private readonly ImportApplyPlanner $planner = new ImportApplyPlanner) {}

    public function handle(ImportBatch $batch, User $user, string $approvedFingerprint): ImportApplyProgress
    {
        // import.apply: SUPER_ADMIN only, and only while the Apply gate is open (config/import.php).
        if (! ImportApplyGate::allows($user)) {
            throw new AuthorizationException('لا تملك صلاحية اعتماد الاستيراد.');
        }

        DB::transaction(function () use ($batch, $user, $approvedFingerprint) {
            /** @var ImportBatch $locked */
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ImportBatchStatus::READY_FOR_REVIEW || $locked->apply_started_at !== null || $locked->apply_plan_fingerprint !== null) {
                throw new ImportApplyExecutionException('APPLY_STATE_INVALID');
            }

            // The SAME planner as the Dry Run, with every precondition (INITIAL,
            // CURRENT reconciliation, keys resolved, NEW rows, nothing applied…).
            $plan = $this->planner->plan($locked);
            if (! $plan->preconditionsMet()) {
                throw new ImportApplyExecutionException('APPLY_PRECONDITIONS_FAILED');
            }
            if ($plan->blockedRows() > 0) {
                throw new ImportApplyExecutionException('APPLY_PLAN_BLOCKED');
            }
            $fingerprint = $plan->fingerprint();
            if (! hash_equals($fingerprint, $approvedFingerprint)) {
                throw new ImportApplyExecutionException('APPLY_PLAN_CHANGED');
            }

            $locked->update([
                'status' => ImportBatchStatus::APPLYING,
                'apply_started_at' => now(),
                'applied_by' => $user->id,
                'apply_plan_fingerprint' => $fingerprint,
                'apply_error_code' => null,
                'apply_error_row_number' => null,
            ]);
        });

        return ImportApplyProgress::of($batch, 'STARTED', pendingLinks: 0);
    }
}
