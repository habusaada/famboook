<?php

namespace App\Actions;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportMode;
use App\Models\Clan;
use App\Models\ImportBatch;
use App\Support\Import\ImportBatchWorkbook;
use App\Support\Import\InitialFamilyWorkbook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Import Wizard steps 1–2 (docs/03 §96a, import.upload): creates a batch for
 * an EXPLICIT active Clan and an EXPLICIT import mode, stores the workbook
 * privately and records its worksheet/header STRUCTURE. It does not stage any
 * row — staging happens only when a column mapping is confirmed
 * (ConfirmImportMappingAction). Never creates registry records.
 */
class CreateImportBatchAction
{
    public function handle(Clan $clan, ImportMode $mode, UploadedFile $file, ?int $actingUserId): ImportBatch
    {
        if (! $clan->is_active) {
            throw ValidationException::withMessages(['clan_code' => 'العشيرة / العائلة المختارة غير مفعّلة.']);
        }

        $checksum = ImportBatchWorkbook::checksum($file);
        ImportBatchWorkbook::assertNotStaged($clan->id, $checksum);

        // Inspect before writing anything: an unusable workbook creates nothing.
        $inspection = InitialFamilyWorkbook::inspect($file->getRealPath());

        $stored = null;
        try {
            return DB::transaction(function () use ($clan, $mode, $file, $checksum, $inspection, $actingUserId, &$stored) {
                $batch = ImportBatch::create([
                    'clan_id' => $clan->id,
                    'import_mode' => $mode,
                    ...ImportBatchWorkbook::fileAttributes($file, $checksum, $inspection),
                    'status' => ImportBatchStatus::UPLOADED,
                    'row_count' => 0,
                    'uploaded_by' => $actingUserId,
                ]);
                $stored = ImportBatchWorkbook::store($batch, $file);
                $batch->update(['source_file_path' => $stored]);

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent upload of the same file for this Clan won the race.
            ImportBatchWorkbook::assertNotStaged($clan->id, $checksum);
            throw new RuntimeException('Import batch could not be created.');
        } catch (Throwable $e) {
            // Never leave an orphaned private file behind a rolled-back batch.
            if ($stored !== null) {
                Storage::disk(ImportBatchWorkbook::DISK)->delete($stored);
            }
            throw $e;
        }
    }
}
