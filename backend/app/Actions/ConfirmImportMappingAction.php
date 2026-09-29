<?php

namespace App\Actions;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\Import\ImportBatchWorkbook;
use App\Support\Import\InitialFamilyRow;
use App\Support\Import\InitialFamilyWorkbook;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import Wizard step 3 (import.validate): confirms the column mapping and
 * STAGES the rows (docs/03 §96a).
 *
 * The mapping names, for each canonical field, the source column LETTER of
 * the selected worksheet (null = not mapped); every other kept column must be
 * listed as ignored, so nothing is mapped implicitly. Rules: required fields
 * mapped; a column serves one field at most; excluded columns (هويتك,
 * الديانة) can never be mapped; unknown columns/fields are refused.
 *
 * Staging (after the workbook is read successfully) replaces THIS batch's
 * staged rows in one transaction: rows are normalized and classified
 * structurally, never matched to or written into the registry. Staging
 * success is not a domain import.
 */
class ConfirmImportMappingAction
{
    /**
     * @param  array<string, ?string>  $fields  canonical field => column letter|null
     * @param  list<string>  $ignored  column letters explicitly ignored
     */
    public function handle(ImportBatch $batch, array $fields, array $ignored): ImportBatch
    {
        ImportBatchWorkbook::assertEditable($batch);
        $sheet = ImportBatchWorkbook::sheet($batch);
        if ($sheet === null) {
            throw ValidationException::withMessages(['worksheet' => 'اختر ورقة العمل أولًا.']);
        }

        $mapping = $this->validated($sheet['columns'], $fields, $ignored);

        // Read everything before writing: an unreadable file stages nothing.
        $rows = InitialFamilyWorkbook::rows(ImportBatchWorkbook::path($batch), $batch->worksheet_name, $mapping['fields']);

        return DB::transaction(function () use ($batch, $mapping, $rows) {
            /** @var ImportBatch $batch */
            $batch = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            ImportBatchWorkbook::invalidateMapping($batch, keepKeyResolutions: true);
            $batch->forceFill(['status' => ImportBatchStatus::VALIDATING])->save();

            foreach ($rows as $parsed) {
                ImportRow::create(['import_batch_id' => $batch->id, ...InitialFamilyRow::stage($parsed)]);
            }

            // Decisions stay valid per exact key; drop only keys no longer staged.
            DB::table('import_family_key_resolutions')
                ->where('import_batch_id', $batch->id)
                ->whereNotIn('source_family_key', DB::table('import_rows')
                    ->where('import_batch_id', $batch->id)
                    ->whereNotNull('source_family_key')
                    ->select('source_family_key'))
                ->delete();

            $batch->forceFill([
                'column_mapping' => $mapping,
                'mapping_confirmed_at' => now(),
                'row_count' => count($rows),
                // Staged and reviewable; nothing is applied.
                'status' => ImportBatchStatus::READY_FOR_REVIEW,
            ])->save();

            return $batch;
        });
    }

    /**
     * @param  list<array{letter: string, header: string, excluded: bool}>  $columns
     * @return array{fields: array<string, string>, ignored: list<string>}
     */
    private function validated(array $columns, array $fields, array $ignored): array
    {
        $errors = [];
        $known = [];
        foreach ($columns as $column) {
            $known[$column['letter']] = $column;
        }
        $canonical = InitialFamilyWorkbook::fields();

        $mapped = [];
        $used = [];
        foreach ($fields as $field => $letter) {
            if (! in_array($field, $canonical, true)) {
                $errors["mapping.{$field}"] = 'حقل غير معروف.';

                continue;
            }
            if ($letter === null || $letter === '') {
                continue;
            }
            if (! isset($known[$letter])) {
                $errors["mapping.{$field}"] = 'العمود المحدد غير موجود في ورقة العمل.';
            } elseif ($known[$letter]['excluded']) {
                $errors["mapping.{$field}"] = 'هذا العمود مستبعد ولا يمكن استخدامه.';
            } elseif (isset($used[$letter])) {
                $errors["mapping.{$field}"] = 'لا يمكن ربط العمود نفسه بأكثر من حقل.';
            } else {
                $used[$letter] = true;
                $mapped[$field] = $letter;
            }
        }

        foreach (InitialFamilyWorkbook::REQUIRED_FIELDS as $field) {
            if (! isset($mapped[$field]) && ! isset($errors["mapping.{$field}"])) {
                $errors["mapping.{$field}"] = 'هذا الحقل مطلوب ويجب ربطه بعمود.';
            }
        }

        $ignoredSet = [];
        foreach ($ignored as $letter) {
            if (! is_string($letter) || ! isset($known[$letter]) || $known[$letter]['excluded']) {
                $errors['ignored'] = 'قائمة الأعمدة المتجاهلة تحتوي على عمود غير صالح.';
            } elseif (isset($used[$letter])) {
                $errors['ignored'] = 'لا يمكن تجاهل عمود مربوط بحقل.';
            } else {
                $ignoredSet[$letter] = true;
            }
        }

        // Every kept column is decided explicitly: mapped or ignored.
        foreach ($known as $letter => $column) {
            if (! $column['excluded'] && ! isset($used[$letter]) && ! isset($ignoredSet[$letter])) {
                $errors['ignored'] ??= 'يجب ربط كل عمود بحقل أو تحديده كعمود متجاهل.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        ksort($ignoredSet);

        return ['fields' => $mapped, 'ignored' => array_keys($ignoredSet)];
    }
}
