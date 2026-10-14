<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\ChangeRequestType;
use App\Exceptions\ChangeRequestException;
use App\Http\Requests\Api\V1\Concerns\ValidatesWorkflowMessages;
use App\Support\ChangeRequests\CoordinatorSubmissionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Coordinator's submission about a Family in their scope (CO-02.4; docs/06
 * AUTH-ADR-093): the envelope only — the type, the client idempotency key,
 * the optional explanation and the type's own `data`. The type's handler is
 * the ONE validation authority for `data` (SubmitChangeRequestAction).
 * Nothing the client sends chooses the Family (the path's family_code,
 * resolved inside the scope), the requester, a status or a target id.
 *
 * V1 types: ADD_FAMILY_MEMBER (PERSON_ADD) only.
 */
class SubmitCoordinatorChangeRequestRequest extends FormRequest
{
    use ValidatesWorkflowMessages;

    /** The types this route accepts (CO-02.4). */
    public const TYPES = [ChangeRequestType::ADD_FAMILY_MEMBER];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The coordinator switch (CoordinatorSubmissionPolicy) answers before any
     * validation, so a closed channel always gives the same 503;
     * SubmitChangeRequestAction::handleCoordinator checks it again (it is the
     * authority). Runs after coordinator.space and the permission, so 401 /
     * 403 keep their precedence.
     */
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
            'data' => ['required', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => 'نوع الطلب مطلوب.',
            'type.in' => 'نوع الطلب غير متاح للمنسّق.',
            'client_reference.required' => 'مرجع الطلب مطلوب.',
            'client_reference.uuid' => 'مرجع الطلب غير صالح.',
            'data.required' => 'بيانات الطلب مطلوبة.',
            'data.array' => 'بيانات الطلب غير صالحة.',
        ];
    }
}
