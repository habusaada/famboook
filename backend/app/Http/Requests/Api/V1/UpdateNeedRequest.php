<?php

namespace App\Http\Requests\Api\V1;

use App\Support\NeedFields;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('need.update') ?? false;
    }

    public function rules(): array
    {
        return NeedFields::rules(partial: true);
    }

    public function messages(): array
    {
        return NeedFields::messages();
    }
}
