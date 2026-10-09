<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ChangeRequestAttestation;
use App\Support\ChangeRequests\ChangeRequestApprovalEvidence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Approve a Change Request (PWA-5c; evidence since FP-ADR-076). The body is
 * empty for types without attestations. A type that requires them sends the
 * attestation codes and, for identity, the National ID typed from the
 * person's document — compared once with the proposal by the handler, never
 * stored, logged or returned (it is excluded from the validation flash and
 * never echoed in an error). Authorization: the route's
 * change-request.approve; whether evidence is required is decided by
 * ApproveChangeRequestAction, not here.
 */
class ApproveChangeRequestRequest extends FormRequest
{
    /** @var list<string> */
    protected $dontFlash = ['verified_national_id'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attestations' => ['sometimes', 'array', 'max:'.count(ChangeRequestAttestation::cases())],
            'attestations.*' => ['distinct', Rule::enum(ChangeRequestAttestation::class)],
            'verified_national_id' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'attestations.array' => 'الإقرارات غير صالحة.',
            'attestations.*.enum' => 'إقرار غير معروف.',
            'attestations.*.distinct' => 'إقرار مكرر.',
            'verified_national_id.max' => 'رقم الهوية المدخل طويل جدًا.',
        ];
    }

    public function evidence(): ChangeRequestApprovalEvidence
    {
        return new ChangeRequestApprovalEvidence(
            array_map(fn (string $code) => ChangeRequestAttestation::from($code), $this->validated('attestations') ?? []),
            $this->validated('verified_national_id'),
        );
    }
}
