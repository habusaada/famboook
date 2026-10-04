<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LifeStatusVerificationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff confirmation that a Person whose life status is UNKNOWN is alive
 * (ConfirmPersonAliveAction). Only the verification method is accepted:
 * the target life status is fixed by the operation, and the Person comes
 * from the route. Authorization: the route's person.record-death behind the
 * Staff boundary.
 */
class ConfirmPersonAliveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'verification_method' => ['required', 'string', Rule::enum(LifeStatusVerificationMethod::class)],
            // Refused, not ignored: the operation decides the outcome.
            'life_status' => ['prohibited'],
            'death_date' => ['prohibited'],
            'family_id' => ['prohibited'],
            'person_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'verification_method.required' => 'طريقة التحقق مطلوبة.',
            'verification_method.enum' => 'طريقة التحقق غير صالحة.',
            'life_status.prohibited' => 'لا تُرسَل الحالة الحياتية؛ هذا الإجراء يؤكد أن الشخص على قيد الحياة فقط.',
            'death_date.prohibited' => 'لا يُرسَل تاريخ وفاة في هذا الإجراء.',
            'family_id.prohibited' => 'لا يُرسَل معرّف أسرة في هذا الإجراء.',
            'person_id.prohibited' => 'يُحدَّد الشخص من الرابط فقط.',
        ];
    }

    public function verificationMethod(): LifeStatusVerificationMethod
    {
        return LifeStatusVerificationMethod::from($this->validated('verification_method'));
    }
}
