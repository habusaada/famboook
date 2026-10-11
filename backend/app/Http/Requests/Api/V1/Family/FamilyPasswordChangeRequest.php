<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The authenticated Family password change (POST
 * /api/v1/family/account/password, docs/11 FU-16): the current password and
 * the new one with its confirmation. The new password follows the Family
 * policy of activation and reset (FamilyPasswordRequest::newPasswordRules —
 * minimum length, confirmation, the 72-byte bcrypt limit). The account is
 * the signed-in one: no identifier is accepted. Passwords are never flashed
 * or logged.
 */
class FamilyPasswordChangeRequest extends FormRequest
{
    /** @var list<string> */
    protected $dontFlash = ['current_password', 'password', 'password_confirmation'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Only "present": a wrong value is a refusal of its own, and the
            // stored password may predate today's policy.
            'current_password' => ['required', 'string', 'max:255'],
            'password' => FamilyPasswordRequest::newPasswordRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'كلمة المرور الحالية مطلوبة.',
            'current_password.string' => 'كلمة المرور الحالية غير صالحة.',
            'current_password.max' => 'كلمة المرور الحالية غير صالحة.',
            ...FamilyPasswordRequest::newPasswordMessages(),
        ];
    }
}
