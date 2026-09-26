<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AssessmentRating;
use App\Enums\AssistanceStatus;
use App\Enums\NeedPriority;
use App\Enums\NeedStatus;
use App\Support\Reporting\DataQualityReport;
use Illuminate\Validation\Rule;

/**
 * Reports V1 (docs/03 §55b): the shared organizational scope plus the
 * report-specific filters. Every filter is optional and validated against
 * canonical codes; unknown values are rejected (422).
 */
class ReportRequest extends ScopedRequest
{
    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('report.view') ?? false;
    }

    protected function filterRules(): array
    {
        $values = fn (string $enum) => array_column($enum::cases(), 'value');

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            // Needs
            'status' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', Rule::in($values(NeedPriority::class))],
            'category' => ['sometimes', 'nullable', 'string', 'max:50'],
            'target' => ['sometimes', 'nullable', Rule::in(['FAMILY', 'PERSON'])],
            // Assessments drill-down
            'domain' => ['sometimes', 'nullable', 'string', 'max:50'],
            'rating' => ['sometimes', 'nullable', Rule::in([...$values(AssessmentRating::class), 'NOT_ASSESSED'])],
            // Data Quality drill-down / export
            'issue' => ['sometimes', 'nullable', Rule::in(DataQualityReport::issueCodes())],
        ];
    }

    public function perPage(): int
    {
        return min(max($this->integer('per_page', 25), 1), self::MAX_PER_PAGE);
    }

    /** Validated Need status filter (null = all statuses). */
    public function needStatus(): ?string
    {
        $status = $this->input('status');
        abort_if($status !== null && NeedStatus::tryFrom($status) === null, 422, 'حالة الاحتياج غير صالحة.');

        return $status;
    }

    /**
     * Assistance status filter: one supported status, or the default
     * OPEN + COMPLETED.
     *
     * @return list<string>
     */
    public function assistanceStatuses(): array
    {
        $status = $this->input('status');
        if ($status === null) {
            return [AssistanceStatus::OPEN->value, AssistanceStatus::COMPLETED->value];
        }
        abort_if(AssistanceStatus::tryFrom($status) === null, 422, 'حالة المساعدة غير صالحة.');

        return [$status];
    }
}
