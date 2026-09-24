<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class NominateFromNeedsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.nominate') ?? false;
    }

    public function rules(): array
    {
        return [
            'need_ids' => ['required', 'array', 'min:1', 'max:200'],
            'need_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'need_ids.required' => 'اختر احتياجًا واحدًا على الأقل.',
            'need_ids.min' => 'اختر احتياجًا واحدًا على الأقل.',
            'need_ids.max' => 'عدد الاحتياجات المحددة كبير جدًا.',
            'need_ids.*.distinct' => 'تم تحديد الاحتياج نفسه أكثر من مرة.',
            'need_ids.*.uuid' => 'الاحتياج المحدد غير صالح.',
        ];
    }
}
