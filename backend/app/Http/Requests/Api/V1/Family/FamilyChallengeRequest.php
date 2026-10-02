<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Support\FamilyAuth\FamilyInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Family activation and password reset, verify and resend (docs/11 §30a):
 * the opaque challenge reference and, for verify, the code. Format only — a malformed code is a
 * field error and not an attempt, for a real challenge and a decoy alike.
 */
class FamilyChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['challenge' => ['required', 'string', 'uuid']];
        if ($this->routeIs('family.*.verify')) {
            $rules['code'] = ['required', function (string $attribute, mixed $value, Closure $fail) {
                if ($this->code() === null) {
                    $fail('رمز التحقق يتكون من '.config('family_auth.otp.digits').' أرقام.');
                }
            }];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'challenge.required' => 'مرجع التحقق مطلوب.',
            'challenge.uuid' => 'مرجع التحقق غير صالح.',
            'code.required' => 'رمز التحقق مطلوب.',
        ];
    }

    public function challenge(): string
    {
        return strtolower((string) $this->input('challenge'));
    }

    /** The code as ASCII digits, or NULL when it is not a code at all. */
    public function code(): ?string
    {
        $code = FamilyInput::clean($this->input('code'));

        return $code !== null && preg_match('/\A[0-9]{'.(int) config('family_auth.otp.digits').'}\z/', $code) === 1 ? $code : null;
    }
}
