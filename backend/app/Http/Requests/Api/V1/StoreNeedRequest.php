<?php

namespace App\Http\Requests\Api\V1;

use App\Support\NeedFields;
use Illuminate\Foundation\Http\FormRequest;

class StoreNeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('need.create') ?? false;
    }

    public function rules(): array
    {
        return NeedFields::rules(partial: false);
    }

    public function messages(): array
    {
        return NeedFields::messages();
    }
}
