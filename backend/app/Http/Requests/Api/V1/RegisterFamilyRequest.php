<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Gender;
use App\Enums\RegistrationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('family.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'registration_date' => ['required', 'date'],
            'registration_source' => ['required', Rule::enum(RegistrationSource::class)],
            'paper_form_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],

            'household_head' => ['required', 'array'],
            'household_head.full_name' => ['required', 'string', 'max:255'],
            'household_head.national_id' => ['nullable', 'string', 'max:50'],
            'household_head.gender' => ['required', Rule::enum(Gender::class)],
            // docs/03-BUSINESS-RULES.md §25: birth date must not be in the future.
            'household_head.birth_date' => ['required', 'date', 'before_or_equal:today'],
            'household_head.mobile' => ['nullable', 'string', 'max:50'],
            'household_head.alternate_mobile' => ['nullable', 'string', 'max:50'],

            'residence' => ['required', 'array'],
            'residence.governorate' => ['required', 'string', 'max:255'],
            'residence.city' => ['required', 'string', 'max:255'],
            'residence.area' => ['nullable', 'string', 'max:255'],
            'residence.neighborhood' => ['nullable', 'string', 'max:255'],
            'residence.address_text' => ['nullable', 'string'],
            'residence.displacement_status' => ['nullable', 'string', 'max:255'],
            'residence.residence_type' => ['nullable', 'string', 'max:255'],
            'residence.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'residence.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
