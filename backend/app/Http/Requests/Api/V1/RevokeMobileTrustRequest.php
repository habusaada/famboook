<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\MobileTrustRevokeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Revoke a Person's trusted mobile (docs/06 §22b) with a reason CODE — never
 * free text. Authorization: the route's person-mobile-trust.revoke,
 * re-checked in RevokePersonMobileTrustAction.
 */
class RevokeMobileTrustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(MobileTrustRevokeReason::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'سبب الإلغاء مطلوب.',
            'reason.enum' => 'سبب الإلغاء غير صالح.',
        ];
    }

    public function reason(): MobileTrustRevokeReason
    {
        return MobileTrustRevokeReason::from($this->validated('reason'));
    }
}
