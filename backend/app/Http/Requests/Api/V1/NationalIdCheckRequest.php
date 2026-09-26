<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Exact National ID duplicate pre-check (AUTH-ADR-058): only for users who
 * may create Persons (registering a household head or adding a member) —
 * the same check the creation actions enforce. It never grants National ID
 * viewing or browsing.
 */
class NationalIdCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('person.create') || $user->can('family.create'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'national_id' => ['required', 'string', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['national_id.required' => 'رقم الهوية مطلوب للتحقق.'];
    }
}
