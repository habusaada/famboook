<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RejectBeneficiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'سبب الرفض مطلوب.',
            'rejection_reason.max' => 'سبب الرفض طويل جدًا.',
        ];
    }
}
