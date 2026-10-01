<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Import\Apply\ImportApplyGate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Apply start (import.apply, docs/03 §96b): the ONLY input is the Dry Run
 * plan fingerprint the operator reviewed. No row selection, chunk size,
 * dates or other execution controls are accepted.
 */
class StartImportApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ImportApplyGate::allows($this->user());
    }

    public function rules(): array
    {
        return [
            'plan_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_fingerprint.required' => 'بصمة خطة المعاينة مطلوبة.',
            'plan_fingerprint.*' => 'بصمة خطة المعاينة غير صالحة.',
        ];
    }
}
