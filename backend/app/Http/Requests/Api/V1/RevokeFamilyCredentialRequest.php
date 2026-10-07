<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CredentialRevokeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Revoke a Family's Digital Family Card (docs/11 FP-ADR-070) with a Staff
 * reason CODE — ADMINISTRATIVE or COMPROMISED; REISSUED is never accepted
 * here. Authorization: the route's family-card.revoke.
 */
class RevokeFamilyCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::enum(CredentialRevokeReason::class)->only(CredentialRevokeReason::staffReasons())],
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

    public function reason(): CredentialRevokeReason
    {
        return CredentialRevokeReason::from($this->validated('reason'));
    }
}
