<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Assistance;
use App\Support\ExportFieldCatalog;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue a list of explicitly selected APPROVED beneficiaries. When the
 * Assistance's export configuration contains SENSITIVE fields, issuing
 * also requires assistance.export-sensitive.
 */
class IssueBeneficiaryListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        /** @var Assistance $assistance */
        $assistance = $this->route('assistance');

        return $user !== null
            && $user->can('assistance.export')
            && (! ExportFieldCatalog::containsSensitive($assistance->export_fields ?? []) || $user->can('assistance.export-sensitive'));
    }

    public function rules(): array
    {
        return [
            'beneficiary_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'beneficiary_ids.*' => ['required', 'uuid', 'distinct'],
            'recipient_organization' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'beneficiary_ids.required' => 'لا يمكن إصدار كشف فارغ.',
            'beneficiary_ids.min' => 'لا يمكن إصدار كشف فارغ.',
            'beneficiary_ids.*.distinct' => 'تم تحديد المستفيد نفسه أكثر من مرة.',
        ];
    }
}
