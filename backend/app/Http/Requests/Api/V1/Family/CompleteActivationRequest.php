<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Family activation, step 3 (docs/11 §30a): the verified challenge reference
 * and the first password. The approved Family password policy (docs/03
 * §89b): a minimum length, confirmation, NO composition rule — passphrases
 * are welcome. Stored only through Laravel hashing; never flashed or logged.
 */
class CompleteActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'uuid'],
            'password' => ['required', 'string', 'min:'.(int) config('family_auth.password_min_length'), 'max:255', 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'challenge.required' => 'مرجع التحقق مطلوب.',
            'challenge.uuid' => 'مرجع التحقق غير صالح.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن :min أحرف.',
            'password.max' => 'كلمة المرور طويلة جدًا.',
            'password.confirmed' => 'كلمتا المرور غير متطابقتين.',
        ];
    }

    public function challenge(): string
    {
        return strtolower((string) $this->input('challenge'));
    }
}
