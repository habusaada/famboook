<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesWorkflowMessages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Return a Change Request for clarification (PWA-5c): the family-visible
 * public_message is required; the Staff-only internal_note is optional and
 * never shown to the family. Authorization: the route's
 * change-request.return.
 */
class ReturnChangeRequestRequest extends FormRequest
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
            'public_message' => $this->workflowTextRules(required: true),
            'internal_note' => $this->workflowTextRules(required: false),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'public_message.required' => 'رسالة الاستيضاح للأسرة مطلوبة.',
            'public_message.max' => 'رسالة الاستيضاح طويلة جدًا.',
            'internal_note.max' => 'الملاحظة الداخلية طويلة جدًا.',
        ];
    }
}
