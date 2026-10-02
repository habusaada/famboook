<?php

namespace App\Http\Requests\Api\V1\Family;

/**
 * Family login (docs/11 §30a): the National ID — format only, as for
 * activation — and a password. No password rule beyond "present": a wrong
 * one is answered by the generic credential failure, not by validation.
 * Neither value is flashed or logged.
 */
class FamilyLoginRequest extends FamilyIdentifierRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'password' => ['required', 'string', 'max:255']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.string' => 'كلمة المرور غير صالحة.',
            'password.max' => 'كلمة المرور غير صالحة.',
        ];
    }
}
