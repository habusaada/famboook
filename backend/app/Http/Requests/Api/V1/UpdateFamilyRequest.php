<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial correction of a Family's basic registration metadata
 * (docs/03-BUSINESS-RULES.md §56 "Data Correction"). family_code,
 * status, registration_source and the household head are not editable
 * here: identifiers are permanent, and status/head changes are
 * controlled operations.
 */
class UpdateFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('family.update') ?? false;
    }

    public function rules(): array
    {
        return [
            // Same rule as registration (RegisterFamilyRequest).
            'registration_date' => ['sometimes', 'required', 'date'],
            'paper_form_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
