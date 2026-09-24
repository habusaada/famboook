<?php

namespace App\Http\Requests\Api\V1;

use App\Support\AssessmentRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assessment.create') ?? false;
    }

    public function rules(): array
    {
        return AssessmentRules::draft(partial: false);
    }

    public function messages(): array
    {
        return AssessmentRules::messages();
    }
}
