<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DisplacementStatus;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\RegistrationSource;
use App\Support\FamilyLineage;
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
            // Clan required, Branch optional (docs/03 §7a).
            ...FamilyLineage::rules(partial: false),
            'registration_source' => ['required', Rule::enum(RegistrationSource::class)],
            'paper_form_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],

            'household_head' => ['required', 'array'],
            'household_head.full_name' => ['required', 'string', 'max:255'],
            'household_head.national_id' => ['nullable', 'string', 'max:50'],
            'household_head.gender' => ['required', Rule::enum(Gender::class)],
            // Optional; defaults to UNKNOWN (never inferred).
            'household_head.marital_status' => ['sometimes', Rule::enum(MaritalStatus::class)],
            // docs/03-BUSINESS-RULES.md §25: birth date must not be in the future.
            // Optional: NULL = unknown, never a placeholder (docs/03 §26).
            'household_head.birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'household_head.mobile' => ['nullable', 'string', 'max:50'],
            'household_head.alternate_mobile' => ['nullable', 'string', 'max:50'],
            // Descriptive only ("أحمد محمد – أخ"); meaningless without the
            // alternate number it describes.
            'household_head.alternate_mobile_owner_relation' => [
                'nullable', 'string', 'max:255',
                'prohibited_if:household_head.alternate_mobile,null',
            ],

            'residence' => ['required', 'array'],
            'residence.governorate' => ['required', 'string', 'max:255'],
            'residence.city' => ['required', 'string', 'max:255'],
            'residence.area' => ['nullable', 'string', 'max:255'],
            'residence.neighborhood' => ['nullable', 'string', 'max:255'],
            'residence.address_text' => ['nullable', 'string'],
            // Residence BEFORE displacement (not birthplace), short free text.
            'residence.original_residence_text' => ['nullable', 'string', 'max:255'],
            // Nullable: NULL = not collected, distinct from NOT_DISPLACED.
            'residence.displacement_status' => ['nullable', Rule::enum(DisplacementStatus::class)],
            // Optional even when displaced (the paper form doesn't require
            // it); rejected otherwise rather than silently stored.
            'residence.displacement_location_text' => [
                'nullable', 'string', 'max:255',
                'prohibited_unless:residence.displacement_status,'.DisplacementStatus::DISPLACED->value,
            ],
            'residence.residence_type' => ['nullable', 'string', 'max:255'],
            'residence.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'residence.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return FamilyLineage::messages();
    }
}
