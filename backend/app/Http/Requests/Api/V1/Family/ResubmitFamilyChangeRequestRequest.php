<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Http\Requests\Api\V1\Concerns\ValidatesWorkflowMessages;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The family's answer to a clarification request (PWA-5e): a required
 * response text, validated as stored. The proposal itself never changes.
 */
class ResubmitFamilyChangeRequestRequest extends FormRequest
{
    use ValidatesWorkflowMessages;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['response' => $this->workflowTextRules(required: true)];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'response.required' => 'الرد مطلوب.',
            'response.max' => 'الرد طويل جدًا.',
        ];
    }
}
