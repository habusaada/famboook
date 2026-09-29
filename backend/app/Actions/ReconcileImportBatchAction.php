<?php

namespace App\Actions;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportReconciliationStatus;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\Import\ImportReconciler;
use App\Support\Import\InitialFamilyImportSummary;
use App\Support\ImportRawPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import Wizard step 5 — record reconciliation (docs/03 §96a, import.validate).
 *
 * Runs ImportReconciler and persists ONLY staging metadata: each row's
 * import_rows.reconciliation_status, its evidence record and the batch's
 * reconciled_at / reconciled_by / fingerprint. Re-running replaces the
 * previous result (never duplicates it). It never writes Persons, Families,
 * Memberships, Residences, declarations or Branches, never changes staged
 * source values and never proposes deletions. It is not Apply.
 *
 * Requires a staged batch whose family keys are all explicitly resolved.
 */
class ReconcileImportBatchAction
{
    public function handle(ImportBatch $batch, User $user): ImportBatch
    {
        return DB::transaction(function () use ($batch, $user) {
            /** @var ImportBatch $batch */
            $batch = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== ImportBatchStatus::READY_FOR_REVIEW || $batch->mapping_confirmed_at === null) {
                throw ValidationException::withMessages(['batch' => 'يجب تجهيز صفوف الدفعة قبل المطابقة.']);
            }
            if (! InitialFamilyImportSummary::keyResolution($batch)['complete']) {
                throw ValidationException::withMessages(['batch' => 'يجب حسم جميع مفاتيح الأسر قبل المطابقة.']);
            }

            $results = ImportReconciler::reconcile($batch);
            $now = now();

            DB::table('import_row_reconciliations')->where('import_batch_id', $batch->id)->delete();
            $records = [];
            $byStatus = [];
            foreach ($results as $r) {
                // Evidence holds codes, masked IDs and compared values — never forbidden columns.
                ImportRawPayload::assertClean($r['issues'] ?? []);
                ImportRawPayload::assertClean($r['differences'] ?? []);
                $byStatus[$r['status']->value][] = $r['import_row_id'];
                $records[] = [
                    'import_row_id' => $r['import_row_id'],
                    'import_batch_id' => $batch->id,
                    'head_match' => $r['head_match'],
                    'head_person_id' => $r['head_person_id'],
                    'family_match' => $r['family_match'],
                    'family_id' => $r['family_id'],
                    'spouse_matches' => $r['spouse_matches'] !== null ? json_encode($r['spouse_matches']) : null,
                    'differences' => $r['differences'] !== null ? json_encode($r['differences'], JSON_UNESCAPED_UNICODE) : null,
                    'issues' => $r['issues'] !== null ? json_encode($r['issues'], JSON_UNESCAPED_UNICODE) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('import_row_reconciliations')->insert($chunk);
            }

            // Authoritative per-row state (metadata column; source values untouched).
            DB::table('import_rows')->where('import_batch_id', $batch->id)->update(['reconciliation_status' => null]);
            foreach ($byStatus as $status => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    DB::table('import_rows')->whereIn('id', $chunk)->update(['reconciliation_status' => ImportReconciliationStatus::from($status)->value]);
                }
            }

            $batch->forceFill([
                'reconciled_at' => $now,
                'reconciled_by' => $user->id,
                'reconciliation_fingerprint' => ImportReconciler::fingerprint($batch),
            ])->save();

            return $batch;
        });
    }
}
