<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Http\Requests\Api\V1\Concerns\ValidatesWorkflowMessages;
use App\Support\ChangeRequests\CoordinatorSubmissionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Coordinator's proposal of a NEW Family (CO-02.5; FAMILY_ADD =
 * REGISTER_FAMILY): the envelope only — the type, the client idempotency
 * key, the optional explanation, the proposed placement by public codes
 * (resolved and checked against the scope by the server; every refusal is
 * the same answer) and the type's own `data` (the head's identity and the
 * original residence), validated by the handler.
 */
class SubmitCoordinatorProposalRequest extends FormRequest
{
    use ValidatesWorkflowMessages;

    /** The types this route accepts (CO-02.5). */
    public const TYPES = [ChangeRequestType::REGISTER_FAMILY];

    public function authorize(): bool
    {
        return true;
    }

    /** The coordinator switch answers before any validation (as the PERSON_ADD route). */
    protected function prepareForValidation(): void
    {
        if (! CoordinatorSubmissionPolicy::allows($this->user())) {
            throw new ChangeRequestException(ChangeRequestException::SUBMISSION_DISABLED);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_map(fn (ChangeRequestType $type) => $type->value, self::TYPES))],
            'client_reference' => ['required', 'uuid'],
            'reason' => $this->workflowTextRules(required: false),
            'placement' => ['required', 'array'],
            'placement.clan_code' => ['required', 'string', 'max:50'],
            'placement.branch_code' => ['nullable', 'string', 'max:50'],
            'data' => ['required', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => 'نوع الطلب مطلوب.',
            'type.in' => 'نوع الطلب غير متاح لهذا الإجراء.',
            'client_reference.required' => 'مرجع الطلب مطلوب.',
            'client_reference.uuid' => 'مرجع الطلب غير صالح.',
            'placement.required' => 'مكان الأسرة المقترح مطلوب.',
            'placement.clan_code.required' => 'العائلة مطلوبة.',
            'data.required' => 'بيانات الطلب مطلوبة.',
            'data.array' => 'بيانات الطلب غير صالحة.',
        ];
    }
}
