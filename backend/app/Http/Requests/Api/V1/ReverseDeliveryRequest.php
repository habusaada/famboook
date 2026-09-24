<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ReverseDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.reverse') ?? false;
    }

    public function rules(): array
    {
        return [
            'reversal_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reversal_reason.required' => 'سبب عكس التسليم مطلوب.',
            'reversal_reason.max' => 'السبب طويل جدًا.',
        ];
    }
}
