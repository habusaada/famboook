<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Yes, send the code" of first self-activation (docs/11 §30a, FP-ADR-053):
 * the opaque confirmation reference only. The code always goes to the
 * Person's stored number — a number in the request is refused, never used.
 */
class FamilyConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'uuid'],
            'mobile' => ['prohibited'],
            'phone' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirmation.required' => 'مرجع التأكيد مطلوب.',
            'confirmation.uuid' => 'مرجع التأكيد غير صالح.',
        ];
    }

    public function confirmation(): string
    {
        return strtolower((string) $this->input('confirmation'));
    }
}
