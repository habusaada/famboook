<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CoordinatorRevokeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The reason for revoking a coordinator role or one scope (docs/06 §22b).
 * ROLE_REMOVED is never chosen: the role revocation sets it on the scopes.
 */
class RevokeCoordinatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(array_map(fn ($r) => $r->value, CoordinatorRevokeReason::chosen()))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'سبب الإلغاء مطلوب.',
            'reason.in' => 'سبب الإلغاء غير صالح.',
        ];
    }

    public function reason(): CoordinatorRevokeReason
    {
        return CoordinatorRevokeReason::from($this->input('reason'));
    }
}
