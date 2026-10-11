<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Start a mobile CONTACT_UPDATE (docs/11 FP-ADR-079): the member (opaque
 * member_ref), the proposed mobile, the head's current password (required
 * by the action for the head's OWN number only) and the idempotency key.
 * The handler is the authority on the member and the number; the password
 * and the number are never flashed or logged. Authorization: the route's
 * family boundary, change-request.submit and the contact-update gate.
 */
class StartContactUpdateRequest extends FormRequest
{
    /** @var list<string> */
    protected $dontFlash = ['mobile', 'current_password'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'member_ref' => ['required', 'string', 'max:64'],
            'mobile' => ['required', 'string', 'max:32'],
            'current_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_reference' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'member_ref.required' => 'اختر الفرد.',
            'mobile.required' => 'أدخل رقم الجوال الجديد.',
            'mobile.max' => 'رقم الجوال غير صالح.',
            'current_password.max' => 'كلمة المرور الحالية غير صالحة.',
            'client_reference.uuid' => 'مرجع الطلب غير صالح.',
        ];
    }

    /** @return array{member_ref: string, mobile: string} the handler input, nothing else */
    public function contactInput(): array
    {
        return ['member_ref' => (string) $this->validated('member_ref'), 'mobile' => (string) $this->validated('mobile')];
    }
}
