<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ImportMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Import Wizard upload (import.upload, docs/03 §96a). The target Clan and the
 * import mode are required and explicit — there is no default Clan and the
 * mode is never inferred. Replacing a batch's workbook uses the same file
 * rules. The file's structure is validated by the workbook inspection.
 */
class CreateImportBatchRequest extends FormRequest
{
    public const MAX_KILOBYTES = 10240;

    public function authorize(): bool
    {
        return $this->user()?->can('import.upload') ?? false;
    }

    public function rules(): array
    {
        $file = ['file' => ['required', 'file', 'extensions:xlsx', 'max:'.self::MAX_KILOBYTES]];

        // Replacing the workbook of an existing batch: file only.
        if ($this->route('importBatch') !== null) {
            return $file;
        }

        return [
            'clan_code' => ['required', 'string', 'max:50', 'exists:clans,code'],
            'import_mode' => ['required', Rule::enum(ImportMode::class)],
            ...$file,
        ];
    }

    public function messages(): array
    {
        return [
            'clan_code.required' => 'العشيرة / العائلة المستهدفة مطلوبة.',
            'clan_code.exists' => 'العشيرة / العائلة غير موجودة.',
            'import_mode.required' => 'نوع العملية مطلوب.',
            'import_mode.enum' => 'نوع العملية غير صالح.',
            'file.required' => 'ملف Excel مطلوب.',
            'file.file' => 'ملف Excel مطلوب.',
            'file.extensions' => 'يجب أن يكون الملف بصيغة ‎.xlsx‎.',
            'file.max' => 'حجم الملف يتجاوز الحد المسموح (10 ميغابايت).',
        ];
    }
}
