<?php

namespace App\Http\Requests\Api\V1;

use App\Support\AssistanceFields;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssistanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistance.create') ?? false;
    }

    public function rules(): array
    {
        return AssistanceFields::rules(partial: false);
    }

    public function messages(): array
    {
        return AssistanceFields::messages();
    }
}
