<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Models\RelationshipType;
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
            // Optional; defaults to UNKNOWN (never inferred).
            'marital_status' => ['sometimes', Rule::enum(MaritalStatus::class)],
            // docs/03-BUSINESS-RULES.md §25: birth date must not be in the future.
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'alternate_mobile' => ['nullable', 'string', 'max:50'],
            // Required: this endpoint never creates a household head, so
            // the HEAD relationship type is explicitly rejected below —
            // assigning it here would contradict is_household_head=false.
            'relationship_type_id' => [
                'required',
                'integer',
                Rule::exists('relationship_types', 'id')->where('is_active', true),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (RelationshipType::where('id', $value)->where('code', 'HEAD')->exists()) {
                        $fail('لا يمكن اختيار "رب الأسرة" عند إضافة فرد جديد.');
                    }
                },
            ],
        ];
    }
}
