<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TargetingCriteria;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The explicitly selected families plus the criteria they were previewed
 * with. Every family is re-checked against the criteria server-side.
 */
class NominateFromTargetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.nominate') ?? false;
    }

    public function rules(): array
    {
        return [
            'family_codes' => ['required', 'array', 'min:1', 'max:200'],
            'family_codes.*' => ['required', 'string', 'distinct'],
            ...TargetingCriteria::rules('criteria', required: true),
        ];
    }

    public function messages(): array
    {
        return [
            'family_codes.required' => 'اختر أسرة واحدة على الأقل.',
            'family_codes.min' => 'اختر أسرة واحدة على الأقل.',
            'family_codes.max' => 'عدد الأسر المحددة كبير جدًا.',
            'family_codes.*.distinct' => 'تم تحديد الأسرة نفسها أكثر من مرة.',
            ...TargetingCriteria::messages('criteria'),
        ];
    }
}
