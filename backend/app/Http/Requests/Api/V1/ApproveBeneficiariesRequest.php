<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ApproveBeneficiariesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'nominee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'nominee_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'nominee_ids.required' => 'اختر مرشحًا واحدًا على الأقل.',
            'nominee_ids.min' => 'اختر مرشحًا واحدًا على الأقل.',
            'nominee_ids.*.distinct' => 'تم تحديد المرشح نفسه أكثر من مرة.',
        ];
    }
}
