<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\SelfRevealField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The self sensitive-value reveal (POST /api/v1/family/self/reveal, PWA-3B.2):
 * the field code only. The Person is never named by the client — it is the
 * Family context's Person — so any target identifier or value is refused,
 * not ignored. Authorization: the route's family-side boundary and
 * family.context.
 */
class FamilySelfRevealRequest extends FormRequest
{
    /** Never accepted: the target is SELF, and no value is ever sent. */
    private const PROHIBITED = [
        'person_id', 'person_code', 'family_id', 'family_code', 'user_id', 'membership_id',
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
            '*.prohibited' => 'لا يُقبل هذا الحقل: يُظهر هذا الإجراء بياناتك أنت فقط.',
        ];
    }

    public function field(): SelfRevealField
    {
        return SelfRevealField::from($this->validated('field'));
    }
}
