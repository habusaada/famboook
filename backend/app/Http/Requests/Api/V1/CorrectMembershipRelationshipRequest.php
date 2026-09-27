<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Relationship correction of a current member (family-membership.update).
 * Only the relationship type is accepted; the HEAD / is_household_head
 * consistency is enforced by CorrectMembershipRelationshipAction.
 */
class CorrectMembershipRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('family-membership.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'relationship_type_id' => [
                'required',
                'integer',
                Rule::exists('relationship_types', 'id')->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'relationship_type_id.required' => 'يرجى اختيار صلة القرابة.',
            'relationship_type_id.integer' => 'صلة القرابة المختارة غير متاحة.',
            'relationship_type_id.exists' => 'صلة القرابة المختارة غير متاحة.',
        ];
    }
}
