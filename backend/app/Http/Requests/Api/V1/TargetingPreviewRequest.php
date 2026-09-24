<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TargetingCriteria;
use Illuminate\Foundation\Http\FormRequest;

class TargetingPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.nominate') ?? false;
    }

    public function rules(): array
    {
        return [
            ...TargetingCriteria::rules('criteria', required: true),
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return TargetingCriteria::messages('criteria');
    }
}
