<?php

namespace App\Support;

use App\Enums\AssessmentRating;
use App\Enums\DisplacementStatus;
use App\Enums\NeedPriority;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The V1 assistance targeting criteria (docs/03-BUSINESS-RULES.md §47b):
 * a flat set of explicitly approved, family-oriented filters combined
 * with AND. Multi-value criteria (priorities, ratings) match any value.
 * There are no rule groups and no other keys.
 */
class TargetingCriteria
{
    public const KEYS = [
        'min_family_members',
        'max_family_members',
        'displacement_status',
        'displacement_location_text',
        'has_child_under_two',
        'min_children_under_two',
        'has_pregnant_member',
        'has_breastfeeding_member',
        'has_member_with_disability',
        'has_member_with_chronic_disease',
        'need_category_code',
        'need_priorities',
        'assessment_domain_code',
        'assessment_ratings',
    ];

    public const BOOLEAN_KEYS = [
        'has_child_under_two',
        'has_pregnant_member',
        'has_breastfeeding_member',
        'has_member_with_disability',
        'has_member_with_chronic_disease',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $field, bool $required = false): array
    {
        $p = "{$field}.";

        return [
            $field => [$required ? 'present' : 'sometimes', 'nullable', 'array:'.implode(',', self::KEYS)],
            $p.'min_family_members' => ['nullable', 'integer', 'min:1', 'max:100'],
            $p.'max_family_members' => ['nullable', 'integer', 'min:1', 'max:100'],
            $p.'displacement_status' => ['nullable', Rule::enum(DisplacementStatus::class)],
            $p.'displacement_location_text' => ['nullable', 'string', 'max:100'],
            $p.'has_child_under_two' => ['nullable', 'boolean'],
            $p.'min_children_under_two' => ['nullable', 'integer', 'min:1', 'max:20'],
            $p.'has_pregnant_member' => ['nullable', 'boolean'],
            $p.'has_breastfeeding_member' => ['nullable', 'boolean'],
            $p.'has_member_with_disability' => ['nullable', 'boolean'],
            $p.'has_member_with_chronic_disease' => ['nullable', 'boolean'],
            $p.'need_category_code' => ['nullable', 'string', Rule::exists('need_categories', 'code')],
            $p.'need_priorities' => ['nullable', 'array', 'max:4'],
            $p.'need_priorities.*' => [Rule::enum(NeedPriority::class)],
            $p.'assessment_domain_code' => ['nullable', 'string', Rule::exists('assessment_domains', 'code')],
            $p.'assessment_ratings' => ['nullable', 'array', 'max:5'],
            $p.'assessment_ratings.*' => [Rule::enum(AssessmentRating::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        return [
            "{$field}.array" => 'معايير الاستهداف غير صالحة.',
            "{$field}.*.integer" => 'القيمة يجب أن تكون عددًا صحيحًا.',
            "{$field}.*.min" => 'القيمة يجب أن تكون 1 على الأقل.',
            "{$field}.*.max" => 'القيمة كبيرة جدًا.',
            "{$field}.*.boolean" => 'القيمة غير صالحة.',
            "{$field}.need_category_code.exists" => 'تصنيف الاحتياج غير معروف.',
            "{$field}.assessment_domain_code.exists" => 'مجال التقييم غير معروف.',
            "{$field}.need_priorities.*.enum" => 'أولوية الاحتياج غير صالحة.',
            "{$field}.assessment_ratings.*.enum" => 'درجة التقييم غير صالحة.',
            "{$field}.displacement_status.enum" => 'حالة النزوح غير صالحة.',
        ];
    }

    /**
     * Cross-field checks and a canonical form: absent/null/empty values are
     * dropped (= no filter), lists are de-duplicated and sorted, booleans
     * are real booleans. An empty result means "no targeting filter".
     *
     * @param  array<string, mixed>|null  $criteria  Request-validated criteria.
     * @return array<string, mixed>
     */
    public static function normalize(?array $criteria, string $field): array
    {
        $c = array_filter(
            $criteria ?? [],
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );

        foreach (self::BOOLEAN_KEYS as $key) {
            if (array_key_exists($key, $c)) {
                $c[$key] = filter_var($c[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['min_family_members', 'max_family_members', 'min_children_under_two'] as $key) {
            if (array_key_exists($key, $c)) {
                $c[$key] = (int) $c[$key];
            }
        }
        foreach (['need_priorities', 'assessment_ratings'] as $key) {
            if (array_key_exists($key, $c)) {
                $c[$key] = array_values(array_unique($c[$key]));
                sort($c[$key]);
            }
        }
        if (array_key_exists('displacement_location_text', $c)) {
            $c['displacement_location_text'] = trim($c['displacement_location_text']);
            if ($c['displacement_location_text'] === '') {
                unset($c['displacement_location_text']);
            }
        }

        $errors = [];
        if (isset($c['min_family_members'], $c['max_family_members']) && $c['max_family_members'] < $c['min_family_members']) {
            $errors["{$field}.max_family_members"] = 'الحد الأعلى لعدد الأفراد يجب ألا يقل عن الحد الأدنى.';
        }
        if (isset($c['min_children_under_two']) && ($c['has_child_under_two'] ?? true) === false) {
            $errors["{$field}.min_children_under_two"] = 'لا يمكن تحديد عدد أطفال دون سنتين مع اشتراط عدم وجودهم.';
        }
        if (isset($c['assessment_ratings']) && ! isset($c['assessment_domain_code'])) {
            $errors["{$field}.assessment_domain_code"] = 'اختر مجال التقييم عند تحديد الدرجات.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        ksort($c);

        return $c;
    }
}
