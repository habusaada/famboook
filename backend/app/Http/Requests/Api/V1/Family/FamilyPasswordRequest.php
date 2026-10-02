<?php

namespace App\Http\Requests\Api\V1\Family;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The last step of Family activation and of a password reset (docs/11
 * §30a): the verified challenge reference and the new password. The approved
 * Family password policy (docs/03 §89b): a minimum length, confirmation, NO
 * composition rule — passphrases are welcome. Stored only through Laravel
 * hashing; never flashed or logged.
 *
 * bcrypt reads at most 72 BYTES of its input and ignores the rest. A longer
 * password is therefore REFUSED, never truncated: otherwise the characters
 * past that point would silently not be part of the password. The limit is
 * bytes of UTF-8, so an Arabic passphrase reaches it at about 36 letters.
 */
class FamilyPasswordRequest extends FormRequest
{
    public const TOO_LONG = 'كلمة المرور طويلة جدًا. يرجى استخدام كلمة مرور أقصر.';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'uuid'],
            'password' => [
                'required', 'string', 'min:'.(int) config('family_auth.password_min_length'), 'confirmed',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && strlen($value) > (int) config('family_auth.password_max_bytes')) {
                        $fail(self::TOO_LONG);
                    }
                },
            ],
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
            'password.confirmed' => 'كلمتا المرور غير متطابقتين.',
        ];
    }

    public function challenge(): string
    {
        return strtolower((string) $this->input('challenge'));
    }
}
