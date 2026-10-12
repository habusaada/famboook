<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\CoordinatorRevealField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Coordinator member reveal (POST
 * /api/v1/family/coordinator/families/{familyCode}/members/{memberRef}/reveal,
 * CO-01C): the field code only — NATIONAL_ID or MOBILE. The Family and the
 * member come ONLY from the path and are resolved inside the Coordinator's
 * scope; any identifier, target, scope or value is refused — in the body and
 * in the query string — not ignored.
 */
class CoordinatorMemberRevealRequest extends FormRequest
{
    /** Never accepted: the target comes from the path, the scope from the server. */
    private const PROHIBITED = [
        'member_ref', 'person_id', 'person_code', 'family_id', 'family_code', 'user_id', 'membership_id',
        'national_id', 'mobile', 'alternate_mobile', 'value', 'target', 'fields',
        'scope', 'scope_type', 'clan', 'branch_group', 'branch',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'field' => ['required', 'string', Rule::enum(CoordinatorRevealField::class)],
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

    public function field(): CoordinatorRevealField
    {
        return CoordinatorRevealField::from($this->validated('field'));
    }
}
