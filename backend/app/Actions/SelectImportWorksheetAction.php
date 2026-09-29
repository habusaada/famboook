<?php

namespace App\Actions;

use App\Models\ImportBatch;
use App\Support\Import\ImportBatchWorkbook;
use App\Support\Import\InitialFamilyWorkbook;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import Wizard step 2 (import.upload): explicitly selects/confirms the data
 * worksheet. Changing it invalidates the mapping and this batch's staged rows.
 */
class SelectImportWorksheetAction
{
    public function handle(ImportBatch $batch, string $worksheet): ImportBatch
    {
        ImportBatchWorkbook::assertEditable($batch);

        $sheet = ImportBatchWorkbook::sheet($batch, $worksheet);
        if ($sheet === null || ! $sheet['plausible']) {
            throw ValidationException::withMessages(['worksheet' => 'ورقة العمل غير موجودة أو لا تحتوي على عناوين وبيانات.']);
        }
        if ($sheet['too_many_rows']) {
            throw ValidationException::withMessages(['worksheet' => 'عدد الصفوف يتجاوز الحد المسموح ('.InitialFamilyWorkbook::MAX_ROWS.').']);
        }

        return DB::transaction(function () use ($batch, $worksheet) {
            /** @var ImportBatch $batch */
            $batch = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->worksheet_name !== $worksheet) {
                ImportBatchWorkbook::invalidateMapping($batch);
                $batch->worksheet_name = $worksheet;
            }
            $batch->save();

            return $batch;
        });
    }
}
