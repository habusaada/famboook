<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ChangeRequestRejectionReason;
use App\Http\Requests\Api\V1\Concerns\ValidatesWorkflowMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reject a Change Request (PWA-5c): a controlled rejection_reason_code; a
 * family-visible public_message (required for OTHER, which says nothing on
 * its own); an optional Staff-only internal_note. Whether the request's
 * current state accepts the reason (APPROVED → NO_LONGER_APPLICABLE only,
 * after a refused apply) is decided by RejectChangeRequestAction, not here.
 * Authorization: the route's change-request.reject.
 */
class RejectChangeRequestRequest extends FormRequest
{
    use ValidatesWorkflowMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rejection_reason_code' => ['required', 'string', Rule::enum(ChangeRequestRejectionReason::class)],
            'public_message' => $this->workflowTextRules(
                required: $this->input('rejection_reason_code') === ChangeRequestRejectionReason::OTHER->value,
            ),
            'internal_note' => $this->workflowTextRules(required: false),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rejection_reason_code.required' => 'سبب الرفض مطلوب.',
            'rejection_reason_code.enum' => 'سبب الرفض غير صالح.',
            'public_message.required' => 'رسالة الرفض للأسرة مطلوبة لهذا السبب.',
            'public_message.max' => 'رسالة الرفض طويلة جدًا.',
            'internal_note.max' => 'الملاحظة الداخلية طويلة جدًا.',
        ];
    }

    public function reason(): ChangeRequestRejectionReason
    {
        return ChangeRequestRejectionReason::from($this->validated('rejection_reason_code'));
    }
}
