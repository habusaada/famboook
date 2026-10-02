<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\MobileVerificationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grant a Person's current mobile TRUSTED status (docs/06 §22b). Only the
 * verification method is accepted: the mobile number is never an input —
 * the action reads the Person's stored mobile. Authorization: the route's
 * person-mobile-trust.grant, re-checked in GrantPersonMobileTrustAction.
 */
class GrantMobileTrustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'verification_method' => ['required', 'string', Rule::enum(MobileVerificationMethod::class)],
            // A number in the request is refused, not ignored.
            'mobile' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'verification_method.required' => 'طريقة التحقق مطلوبة.',
            'verification_method.enum' => 'طريقة التحقق غير صالحة.',
            'mobile.prohibited' => 'يُوثَّق رقم الجوال المسجّل للشخص فقط؛ لا يُرسل رقم في الطلب.',
        ];
    }

    public function verificationMethod(): MobileVerificationMethod
    {
        return MobileVerificationMethod::from($this->validated('verification_method'));
    }
}
