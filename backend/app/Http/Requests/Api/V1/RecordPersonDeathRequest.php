<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LifeStatusVerificationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff recording of an existing Person's death (RecordPersonDeathAction,
 * docs/11 FU-10). The death date must be sent explicitly: a Y-m-d date when
 * it is known, or null when it is not — an omitted field is refused, never
 * read as "unknown". The domain rules (not in the future, not before the
 * birth date) are applied again by the Domain Action. The verification
 * method is required. The target life status is fixed by the operation, and
 * the Person comes from the route. Authorization: the route's
 * person.record-death behind the Staff boundary.
 */
class RecordPersonDeathRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'death_date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'verification_method' => ['required', 'string', Rule::enum(LifeStatusVerificationMethod::class)],
            // Refused, not ignored: the operation decides the outcome.
            'life_status' => ['prohibited'],
            'family_id' => ['prohibited'],
            'person_id' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'death_date.present' => 'حدّد تاريخ الوفاة، أو اختر أن تاريخ الوفاة غير معروف.',
            'death_date.date_format' => 'تاريخ الوفاة غير صالح.',
            'verification_method.required' => 'طريقة التحقق مطلوبة.',
            'verification_method.enum' => 'طريقة التحقق غير صالحة.',
            'life_status.prohibited' => 'لا تُرسَل الحالة الحياتية؛ هذا الإجراء يسجّل الوفاة فقط.',
            'family_id.prohibited' => 'لا يُرسَل معرّف أسرة في هذا الإجراء.',
            'person_id.prohibited' => 'يُحدَّد الشخص من الرابط فقط.',
        ];
    }

    public function deathDate(): ?string
    {
        return $this->validated('death_date');
    }

    public function verificationMethod(): LifeStatusVerificationMethod
    {
        return LifeStatusVerificationMethod::from($this->validated('verification_method'));
    }
}
