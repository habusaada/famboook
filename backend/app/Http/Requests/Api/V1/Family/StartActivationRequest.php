<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Support\FamilyAuth\FamilyNationalId;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Family activation, step 1 (docs/11 §30a). Only the FORMAT is validated
 * here — nine digits after the strict Family Portal normalizer — and a
 * format error reveals nothing about any Person. The value travels in the
 * body, is never flashed and is never logged.
 */
class StartActivationRequest extends FormRequest
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
