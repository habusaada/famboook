<?php

namespace App\Http\Requests\Api\V1;

use App\Support\AssessmentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Completion, optionally with a final draft payload saved in the same
 * transaction. Sending draft changes additionally requires
 * assessment.update: assessment.complete alone never edits content.
 */
class CompleteAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null || ! $user->can('assessment.complete')) {
            return false;
        }

        return ! $this->hasDraftChanges() || $user->can('assessment.update');
    }

    public function rules(): array
    {
        return AssessmentRules::draft(partial: true);
    }

    public function messages(): array
    {
        return AssessmentRules::messages();
    }

    private function hasDraftChanges(): bool
    {
        return $this->hasAny(['assessment_date', 'general_notes', 'results']);
    }
}
