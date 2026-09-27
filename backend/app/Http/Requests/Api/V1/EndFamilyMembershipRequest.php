<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ending a current, non-head membership (family-membership.end). A short
 * reason is required; it is kept on the membership (end_reason) and never
 * copied into the activity log.
 */
class EndFamilyMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('family-membership.end') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'سبب إنهاء العضوية مطلوب.',
            'reason.string' => 'سبب إنهاء العضوية مطلوب.',
            'reason.min' => 'يرجى كتابة سبب واضح (3 أحرف على الأقل).',
            'reason.max' => 'السبب طويل جدًا (255 حرفًا كحد أقصى).',
        ];
    }
}
