<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Administrative National ID correction (person.national-id.update,
 * AUTH-ADR-059). The replacement is required — a blank value never clears
 * an existing National ID — and must be typed twice, because the stored
 * value is never shown back to compare against. Stored as entered (request
 * whitespace trimmed only; PDD-001 normalization stays open).
 */
class CorrectNationalIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('person.national-id.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'national_id' => ['required', 'string', 'max:50', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'national_id.required' => 'رقم الهوية البديل مطلوب.',
            'national_id.string' => 'رقم الهوية البديل مطلوب.',
            'national_id.max' => 'رقم الهوية طويل جدًا.',
            'national_id.confirmed' => 'تأكيد رقم الهوية غير مطابق.',
        ];
    }
}
