<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\HealthRecordType;
use App\Models\DisabilityType;
use App\Models\PersonHealthRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial correction of a health record. The Person and the type are
 * fixed; ended_at changes only through the close operation. Type-specific
 * fields are accepted only for the record's own type.
 */
class UpdateHealthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('health-record.update') ?? false;
    }

    public function rules(): array
    {
        $type = $this->record()->type;

        return [
            'disability_type_id' => $type === HealthRecordType::DISABILITY
                ? ['sometimes', 'required', 'integer', 'exists:disability_types,id', $this->activeUnlessUnchanged(...)]
                : ['prohibited'],
            'condition_name' => $type === HealthRecordType::CHRONIC_DISEASE
                ? ['sometimes', 'required', 'string', 'max:255']
                : ['prohibited'],
            'details' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'started_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'disability_type_id.required' => 'نوع الإعاقة مطلوب.',
            'condition_name.required' => 'اسم المرض المزمن مطلوب.',
            'prohibited' => 'هذا الحقل لا ينطبق على نوع هذا السجل.',
            'started_at.before_or_equal' => 'لا يمكن أن يكون تاريخ البداية في المستقبل.',
        ];
    }

    /** A deactivated type may stay on a record, but cannot be newly chosen. */
    private function activeUnlessUnchanged(string $attribute, mixed $value, \Closure $fail): void
    {
        if ((int) $value === $this->record()->disability_type_id) {
            return;
        }

        if (! DisabilityType::whereKey($value)->where('is_active', true)->exists()) {
            $fail('نوع الإعاقة غير صالح أو غير مفعّل.');
        }
    }

    private function record(): PersonHealthRecord
    {
        return $this->route('healthRecord');
    }
}
