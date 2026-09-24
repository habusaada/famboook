<?php

namespace App\Http\Requests\Api\V1;

use App\Support\AssistanceFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial update. When `items` is sent it replaces the full planned item
 * list (DRAFT only).
 */
class UpdateAssistanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.update') ?? false;
    }

    public function rules(): array
    {
        return AssistanceFields::rules(partial: true);
    }

    public function messages(): array
    {
        return AssistanceFields::messages();
    }
}
