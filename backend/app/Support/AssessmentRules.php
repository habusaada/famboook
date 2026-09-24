<?php

namespace App\Support;

use App\Enums\AssessmentRating;
use Illuminate\Validation\Rule;

/**
 * Shared request-level validation for assessment drafts. Domain activity
 * (inactive domains cannot be newly added) and completion rules are
 * enforced by the Domain Actions.
 */
class AssessmentRules
{
    /**
     * @return array<string, mixed>
     */
    public static function draft(bool $partial): array
    {
        return [
            'assessment_date' => [
                $partial ? 'sometimes' : 'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            'general_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'results' => ['sometimes', 'array', 'max:50'],
            'results.*' => ['array:domain_code,rating,notes'],
            'results.*.domain_code' => [
                'required',
                'string',
                'distinct',
                Rule::exists('assessment_domains', 'code'),
            ],
            // Only the five ratings. "Not assessed" is never a value: the
            // domain is simply left out of `results`.
            'results.*.rating' => ['required', Rule::enum(AssessmentRating::class)],
            'results.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'assessment_date.required' => 'تاريخ التقييم مطلوب.',
            'assessment_date.date_format' => 'تاريخ التقييم غير صالح.',
            'assessment_date.before_or_equal' => 'لا يمكن أن يكون تاريخ التقييم في المستقبل.',
            'general_notes.max' => 'الملاحظات العامة طويلة جدًا.',
            'results.array' => 'نتائج التقييم غير صالحة.',
            'results.*.array' => 'نتيجة التقييم غير صالحة.',
            'results.*.domain_code.required' => 'مجال التقييم مطلوب.',
            'results.*.domain_code.distinct' => 'لا يمكن تقييم المجال نفسه أكثر من مرة.',
            'results.*.domain_code.exists' => 'مجال التقييم غير معروف.',
            'results.*.rating.required' => 'درجة الاحتياج مطلوبة.',
            'results.*.rating.enum' => 'درجة الاحتياج غير صالحة.',
            'results.*.notes.max' => 'ملاحظات المجال طويلة جدًا.',
        ];
    }
}
