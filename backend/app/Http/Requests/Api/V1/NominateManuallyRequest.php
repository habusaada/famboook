<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class NominateManuallyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.nominate') ?? false;
    }

    public function rules(): array
    {
        return [
            'family_code' => ['required', 'string', 'max:50'],
            // Absent/null = the whole family.
            'person_code' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'family_code.required' => 'اختر الأسرة.',
        ];
    }
}
