<?php

namespace App\Actions;

use App\Models\ImportBatch;
use App\Support\Import\ImportBatchWorkbook;
use App\Support\Import\InitialFamilyWorkbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Import Wizard step 2 (import.upload): replaces the workbook of a batch that
 * has not been applied. The Clan and import mode stay; the new file is
 * inspected, the worksheet re-suggested and the mapping (and any staged rows
 * of THIS batch) invalidated. The same-file-same-Clan rule still applies.
 */
class ReplaceImportWorkbookAction
{
    public function handle(ImportBatch $batch, UploadedFile $file): ImportBatch
    {
        ImportBatchWorkbook::assertEditable($batch);

        $checksum = ImportBatchWorkbook::checksum($file);
        ImportBatchWorkbook::assertNotStaged($batch->clan_id, $checksum, $batch->id);
        $inspection = InitialFamilyWorkbook::inspect($file->getRealPath());

        return DB::transaction(function () use ($batch, $file, $checksum, $inspection) {
            /** @var ImportBatch $batch */
            $batch = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            ImportBatchWorkbook::invalidateMapping($batch);
            $batch->fill(ImportBatchWorkbook::fileAttributes($file, $checksum, $inspection));
            $batch->source_file_path = ImportBatchWorkbook::store($batch, $file);
            $batch->save();

            return $batch;
        });
    }
}
