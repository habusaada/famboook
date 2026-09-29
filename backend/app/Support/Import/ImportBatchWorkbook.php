<?php

namespace App\Support\Import;

use App\Enums\ImportBatchStatus;
use App\Exceptions\DuplicateImportBatchException;
use App\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules for an import batch's workbook (docs/03 §96a):
 *
 * - The uploaded file is kept on the PRIVATE disk (never public) because
 *   staging happens later, after the column mapping is confirmed.
 * - One live batch per file and Clan: the same SHA-256 for the same Clan is
 *   refused while an earlier batch of it has not FAILED — whatever the import
 *   mode, since the same bytes stage the same rows. A DIFFERENT file for the
 *   same Clan is always allowed, and a different checksum says nothing about
 *   whether its records are new.
 * - Replacing the workbook or changing the worksheet invalidates the mapping;
 *   changing a confirmed mapping re-stages. Only that batch's own staged rows
 *   are discarded — never another batch, never registry data.
 */
final class ImportBatchWorkbook
{
    public const DISK = 'local';

    public const DIRECTORY = 'imports';

    public static function checksum(UploadedFile $file): string
    {
        return hash_file('sha256', $file->getRealPath());
    }

    public static function assertNotStaged(int $clanId, string $checksum, ?int $exceptBatchId = null): void
    {
        $existing = ImportBatch::query()
            ->where('clan_id', $clanId)
            ->where('source_checksum', $checksum)
            ->where('status', '!=', ImportBatchStatus::FAILED)
            ->when($exceptBatchId, fn ($q) => $q->whereKeyNot($exceptBatchId))
            ->first();

        if ($existing !== null) {
            throw new DuplicateImportBatchException($existing);
        }
    }

    /** Stores the workbook privately under the batch's public id; returns the path. */
    public static function store(ImportBatch $batch, UploadedFile $file): string
    {
        return $file->storeAs(self::DIRECTORY, $batch->uuid.'.xlsx', self::DISK)
            ?: throw new \RuntimeException('The workbook could not be stored.');
    }

    /** Absolute local path of the batch's stored workbook. */
    public static function path(ImportBatch $batch): string
    {
        $disk = Storage::disk(self::DISK);
        if ($batch->source_file_path === null || ! $disk->exists($batch->source_file_path)) {
            abort(409, 'ملف هذه الدفعة غير متاح. ارفع الملف مرة أخرى.');
        }

        return $disk->path($batch->source_file_path);
    }

    /** Structural facts about a file, recorded on the batch. */
    public static function fileAttributes(UploadedFile $file, string $checksum, array $inspection): array
    {
        return [
            'source_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'source_checksum' => $checksum,
            'source_size_bytes' => $file->getSize(),
            'inspection' => $inspection,
            'worksheet_name' => $inspection['suggested_worksheet'],
        ];
    }

    /** Batches that may still be changed: not applying, applied or failed. */
    public static function assertEditable(ImportBatch $batch): void
    {
        if (! in_array($batch->status, [ImportBatchStatus::UPLOADED, ImportBatchStatus::READY_FOR_REVIEW], true)) {
            throw ValidationException::withMessages(['batch' => 'لا يمكن تعديل هذه الدفعة في حالتها الحالية.']);
        }
    }

    /**
     * Drops the confirmed mapping and this batch's staged rows (inside a
     * transaction). A new file or worksheet also drops the batch's family-key
     * decisions (its keys change); re-confirming a mapping keeps them and
     * ConfirmImportMappingAction prunes only keys that no longer exist.
     * Branches created by earlier decisions are never touched.
     */
    public static function invalidateMapping(ImportBatch $batch, bool $keepKeyResolutions = false): void
    {
        if (! $keepKeyResolutions) {
            DB::table('import_family_key_resolutions')->where('import_batch_id', $batch->id)->delete();
        }
        DB::table('import_rows')->where('import_batch_id', $batch->id)->delete();
        // Staged rows (and their reconciliation evidence, by cascade) are gone:
        // any earlier reconciliation no longer applies.
        $batch->forceFill([
            'reconciled_at' => null,
            'reconciled_by' => null,
            'reconciliation_fingerprint' => null,
            'column_mapping' => null,
            'mapping_confirmed_at' => null,
            'row_count' => 0,
            'status' => ImportBatchStatus::UPLOADED,
        ]);
    }

    /**
     * The inspected sheet entry of a batch.
     *
     * @return array{name: string, data_rows: int, too_many_rows: bool, plausible: bool, columns: list<array>}|null
     */
    public static function sheet(ImportBatch $batch, ?string $name = null): ?array
    {
        $name ??= $batch->worksheet_name;
        foreach ($batch->inspection['worksheets'] ?? [] as $sheet) {
            if ($sheet['name'] === $name) {
                return $sheet;
            }
        }

        return null;
    }
}
