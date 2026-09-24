<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class NotDeliveredRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.deliver') ?? false;
    }

    public function rules(): array
    {
        return [
            'not_delivered_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'not_delivered_reason.required' => 'سبب عدم التسليم مطلوب.',
            'not_delivered_reason.max' => 'السبب طويل جدًا.',
        ];
    }
}
