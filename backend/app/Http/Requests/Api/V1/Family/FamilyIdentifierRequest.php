<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Support\FamilyAuth\FamilyNationalId;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The National ID of a Family activation, login or password reset
 * (docs/11 §30a). Only the FORMAT is validated
 * here — nine digits after the strict Family Portal normalizer — and a
 * format error reveals nothing about any Person. The value travels in the
 * body, is never flashed and is never logged.
 */
class FamilyIdentifierRequest extends FormRequest
{
    public const INVALID = 'رقم الهوية يجب أن يتكون من 9 أرقام.';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'national_id' => ['required', function (string $attribute, mixed $value, Closure $fail) {
                if (FamilyNationalId::normalize($value) === null) {
                    $fail(self::INVALID);
                }
            }],
            // The destination is always the Person's stored number: a number
            // in the request is refused, never used (FP-ADR-053).
            'mobile' => ['prohibited'],
            'phone' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['national_id.required' => 'رقم الهوية مطلوب.'];
    }

    /** The nine normalized digits. */
    public function nationalId(): string
    {
        return (string) FamilyNationalId::normalize($this->input('national_id'));
    }
}
