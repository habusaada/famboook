<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\SelfRevealField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The household-member sensitive-value reveal (POST
 * /api/v1/family/household/members/{memberRef}/reveal, PWA-3B.4): the field
 * code only. The member is named ONLY by the opaque member reference in the
 * path, resolved inside the family.context Family; any family, person,
 * membership or user identifier, any target and any value is refused — in
 * the body and in the query string — not ignored. The allowed fields are the
 * SelfRevealField codes (the same fixed column mapping as the self reveal).
 */
class FamilyMemberRevealRequest extends FormRequest
{
    /** Never accepted: the target comes from the path, and no value is ever sent. */
    private const PROHIBITED = [
        'member_ref', 'person_id', 'person_code', 'family_id', 'family_code', 'user_id', 'membership_id',
        'national_id', 'mobile', 'alternate_mobile', 'value', 'target',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::enum(SelfRevealField::class)],
            ...array_fill_keys(self::PROHIBITED, ['prohibited']),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'field.required' => 'حدّد البيان المطلوب إظهاره.',
            'field.enum' => 'البيان المطلوب إظهاره غير صالح.',
            '*.prohibited' => 'لا يُقبل هذا الحقل في طلب الإظهار.',
        ];
    }

    public function field(): SelfRevealField
    {
        return SelfRevealField::from($this->validated('field'));
    }
}
