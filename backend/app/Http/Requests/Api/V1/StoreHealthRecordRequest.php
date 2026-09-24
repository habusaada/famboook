<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\HealthRecordType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Field shape per record type. Person eligibility (family membership,
 * FEMALE for pregnancy/breastfeeding) and duplicate-active rules are
 * enforced by CreateHealthRecordAction.
 */
class StoreHealthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('health-record.create') ?? false;
    }

    public function rules(): array
    {
        $disability = HealthRecordType::DISABILITY->value;
        $chronic = HealthRecordType::CHRONIC_DISEASE->value;

        return [
            'person_code' => ['required', 'string'],
            'type' => ['required', Rule::enum(HealthRecordType::class)],
            'disability_type_id' => [
                "required_if:type,{$disability}",
                "prohibited_unless:type,{$disability}",
                'nullable',
                'integer',
                // New records may only use ACTIVE reference values.
                Rule::exists('disability_types', 'id')->where('is_active', true),
            ],
            'condition_name' => [
                "required_if:type,{$chronic}",
                "prohibited_unless:type,{$chronic}",
                'nullable',
                'string',
                'max:255',
            ],
            'details' => ['nullable', 'string', 'max:2000'],
            'started_at' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'disability_type_id.required_if' => 'نوع الإعاقة مطلوب.',
            'disability_type_id.exists' => 'نوع الإعاقة غير صالح أو غير مفعّل.',
            'condition_name.required_if' => 'اسم المرض المزمن مطلوب.',
            'started_at.before_or_equal' => 'لا يمكن أن يكون تاريخ البداية في المستقبل.',
        ];
    }
}
