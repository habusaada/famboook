<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Http\Requests\Api\V1\Concerns\ValidatesWorkflowMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A family submission (PWA-5e): the envelope only — the type code, the
 * client idempotency key, the optional explanation and the type's own
 * `data`. The type's handler is the ONE validation authority for `data`
 * (SubmitChangeRequestAction); nothing here duplicates it. Nothing the
 * client sends chooses the Family, the requester, a status or a target id.
 */
class SubmitFamilyChangeRequestRequest extends FormRequest
{
    use ValidatesWorkflowMessages;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The submission switch answers before any validation, so a closed
     * channel always gives the same response (SubmitChangeRequestAction
     * checks it again — it is the authority).
     */
    protected function prepareForValidation(): void
    {
        if (config('change_requests.family_submission_enabled') !== true) {
            throw new ChangeRequestException(ChangeRequestException::SUBMISSION_DISABLED);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(ChangeRequestType::class)],
            'client_reference' => ['required', 'uuid'],
            'reason' => $this->workflowTextRules(required: false),
            'data' => ['required', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => 'نوع الطلب مطلوب.',
            'type.enum' => 'نوع الطلب غير صالح.',
            'client_reference.required' => 'مرجع الطلب مطلوب.',
            'client_reference.uuid' => 'مرجع الطلب غير صالح.',
            'data.required' => 'بيانات الطلب مطلوبة.',
            'data.array' => 'بيانات الطلب غير صالحة.',
        ];
    }
}
