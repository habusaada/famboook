<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('person.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'gender' => ['required', Rule::enum(Gender::class)],
            // docs/03-BUSINESS-RULES.md §25: birth date must not be in the future.
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'alternate_mobile' => ['nullable', 'string', 'max:50'],
        ];
    }
}
