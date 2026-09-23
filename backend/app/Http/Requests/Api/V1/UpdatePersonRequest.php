<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Basic Person field edits only. Does not accept life_status,
 * death_date, is_household_head, or any membership/family field —
 * those are separate, controlled domain operations (docs/03-BUSINESS-
 * RULES.md §14-16, §30: household head and death are controlled
 * operations, not generic field edits).
 */
class UpdatePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('person.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'gender' => ['sometimes', Rule::enum(Gender::class)],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:50'],
            'alternate_mobile' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
