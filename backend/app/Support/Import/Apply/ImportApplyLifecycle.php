<?php

namespace App\Support\Import\Apply;

use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportApplyExecutionException;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;

/**
 * The canonical write of an Apply error onto a batch that stays
 * PARTIALLY_APPLIED (docs/03 §96b): only apply_error_code and
 * apply_error_row_number change, through the ImportBatch model, so every
 * model invariant and PostgreSQL CHECK applies (a stable code, never text;
 * a row number only with a code). The batch is locked; any other status is
 * APPLY_STATE_INVALID — the status itself is never changed here.
 */
final class ImportApplyLifecycle
{
    public static function recordPartialError(ImportBatch $batch, string $code, ?int $rowNumber = null): void
    {
        DB::transaction(function () use ($batch, $code, $rowNumber) {
            /** @var ImportBatch $locked */
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ImportBatchStatus::PARTIALLY_APPLIED) {
                throw new ImportApplyExecutionException('APPLY_STATE_INVALID');
            }
            $locked->update(['apply_error_code' => $code, 'apply_error_row_number' => $rowNumber]);
        });
    }
}
