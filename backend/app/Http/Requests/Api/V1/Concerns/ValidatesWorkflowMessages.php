<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Support\ChangeRequests\WorkflowEventRecorder;
use Closure;
use InvalidArgumentException;

/**
 * Change Request workflow text (PWA-5c): validated as it will be STORED —
 * WorkflowEventRecorder::cleanText removes control and invisible format
 * characters (bidi overrides included) and trims; a text that is blank
 * after cleaning counts as missing; at most 2000 characters. Plain text,
 * never HTML.
 */
trait ValidatesWorkflowMessages
{
    /** @return list<mixed> */
    protected function workflowTextRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:'.WorkflowEventRecorder::MAX_MESSAGE_LENGTH,
            function (string $attribute, mixed $value, Closure $fail) use ($required) {
                try {
                    $clean = WorkflowEventRecorder::cleanText(is_string($value) ? $value : null, $attribute);
                } catch (InvalidArgumentException) {
                    $fail('النص أطول من المسموح.');

                    return;
                }
                if ($clean === null && $required) {
                    $fail('هذا الحقل مطلوب.');
                }
            },
        ];
    }
}
