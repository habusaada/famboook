<?php

namespace App\Http\Requests\Api\V1;

use App\Support\AssessmentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial draft save. When `results` is sent it replaces the draft's
 * whole result set (see App\Support\AssessmentDraft).
 */
class UpdateAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assessment.update') ?? false;
    }

    public function rules(): array
    {
        return AssessmentRules::draft(partial: true);
    }

    public function messages(): array
    {
        return AssessmentRules::messages();
    }
}
