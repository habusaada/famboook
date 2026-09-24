<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CloseHealthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('health-record.close') ?? false;
    }

    public function rules(): array
    {
        return [
            // Defaults to today when omitted.
            'ended_at' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'ended_at.before_or_equal' => 'لا يمكن أن يكون تاريخ الانتهاء في المستقبل.',
        ];
    }
}
