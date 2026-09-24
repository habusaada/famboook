<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CloseNeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('need.close') ?? false;
    }

    public function rules(): array
    {
        return [
            'closure_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'closure_reason.required' => 'سبب الإغلاق مطلوب.',
            'closure_reason.max' => 'سبب الإغلاق طويل جدًا.',
        ];
    }
}
