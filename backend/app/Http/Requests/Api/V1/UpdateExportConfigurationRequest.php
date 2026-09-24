<?php

namespace App\Http\Requests\Api\V1;

use App\Support\ExportFieldCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Requested export fields of an EXTERNAL Assistance: only catalog keys,
 * each at most once, in the submitted order, with a presentation label.
 * Configuring a SENSITIVE field additionally requires
 * assistance.export-sensitive.
 */
class UpdateExportConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('assistance.export')) {
            return false;
        }

        $keys = collect($this->input('fields', []))->pluck('field_key')->filter(fn ($k) => is_string($k) && ExportFieldCatalog::exists($k));

        return ! $keys->contains(fn ($k) => ExportFieldCatalog::classification($k) === ExportFieldCatalog::SENSITIVE)
            || $user->can('assistance.export-sensitive');
    }

    public function rules(): array
    {
        return [
            'fields' => ['present', 'array', 'max:30'],
            'fields.*' => ['array:field_key,column_label'],
            'fields.*.field_key' => ['required', 'string', 'distinct', Rule::in(array_keys(ExportFieldCatalog::FIELDS))],
            'fields.*.column_label' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'fields.*.field_key.in' => 'حقل غير مدعوم في كشوف المستفيدين.',
            'fields.*.field_key.distinct' => 'لا يمكن اختيار الحقل نفسه أكثر من مرة.',
            'fields.*.column_label.required' => 'اسم العمود مطلوب.',
            'fields.*.column_label.max' => 'اسم العمود طويل جدًا.',
        ];
    }
}
