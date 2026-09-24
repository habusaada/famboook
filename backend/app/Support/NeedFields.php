<?php

namespace App\Support;

use App\Enums\AssessmentStatus;
use App\Enums\NeedPriority;
use App\Models\Assessment;
use App\Models\FamilyNeed;
use App\Models\NeedCategory;
use App\Models\Person;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Request rules and domain checks for the editable fields of an OPEN Need
 * (docs/03-BUSINESS-RULES.md §46a). Shared by the create and update
 * Domain Actions/requests. Status and resolution fields are never
 * accepted from the client: they change only through fulfill/close.
 */
class NeedFields
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            // Absent/null = family-level need.
            'person_code' => ['sometimes', 'nullable', 'string'],
            'source_assessment_id' => ['sometimes', 'nullable', 'uuid'],
            'category_code' => [$required, 'string', Rule::exists('need_categories', 'code')],
            'title' => [$required, 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', Rule::enum(NeedPriority::class)],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            // Resolution happens only through the fulfill/close actions.
            'status' => ['prohibited'],
            'resolved_at' => ['prohibited'],
            'resolved_by' => ['prohibited'],
            'closure_reason' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'category_code.required' => 'تصنيف الاحتياج مطلوب.',
            'category_code.exists' => 'تصنيف الاحتياج غير معروف.',
            'title.required' => 'عنوان الاحتياج مطلوب.',
            'title.max' => 'عنوان الاحتياج طويل جدًا.',
            'description.max' => 'الوصف طويل جدًا.',
            'priority.enum' => 'الأولوية غير صالحة.',
            'quantity.numeric' => 'الكمية يجب أن تكون رقمًا.',
            'quantity.decimal' => 'الكمية تقبل منزلتين عشريتين على الأكثر.',
            'quantity.gt' => 'الكمية يجب أن تكون أكبر من صفر.',
            'quantity.max' => 'الكمية كبيرة جدًا.',
            'unit.max' => 'الوحدة طويلة جدًا.',
            'source_assessment_id.uuid' => 'التقييم المحدد غير صالح.',
            'status.prohibited' => 'لا يمكن تحديد حالة الاحتياج عند الإنشاء أو التعديل.',
            'resolved_at.prohibited' => 'لا يمكن تحديد بيانات الإغلاق مباشرة.',
            'resolved_by.prohibited' => 'لا يمكن تحديد بيانات الإغلاق مباشرة.',
            'closure_reason.prohibited' => 'يُسجَّل سبب الإغلاق عند إغلاق الاحتياج فقط.',
        ];
    }

    /**
     * Applies validated fields to an OPEN Need (not saved). References are
     * re-checked only when they change, so an OPEN Need keeps a member who
     * has since left the family, a deactivated category, etc.
     *
     * @param  array<string, mixed>  $data
     */
    public static function apply(FamilyNeed $need, array $data): void
    {
        if (array_key_exists('person_code', $data)) {
            $need->person_id = self::resolvePerson($need, $data['person_code']);
        }

        if (array_key_exists('source_assessment_id', $data)) {
            $need->source_assessment_id = self::resolveAssessment($need, $data['source_assessment_id']);
        }

        if (array_key_exists('category_code', $data)) {
            $category = NeedCategory::where('code', $data['category_code'])->firstOrFail();
            if (! $category->is_active && $category->id !== $need->getOriginal('need_category_id')) {
                throw ValidationException::withMessages([
                    'category_code' => "تصنيف الاحتياج «{$category->name}» غير مفعّل.",
                ]);
            }
            $need->need_category_id = $category->id;
        }

        $need->fill(array_intersect_key($data, array_flip(['title', 'description', 'priority', 'quantity', 'unit'])));

        // A unit only makes sense with a quantity.
        if ($need->quantity === null && $need->unit !== null) {
            throw ValidationException::withMessages([
                'unit' => 'أدخل الكمية عند تحديد الوحدة.',
            ]);
        }
    }

    private static function resolvePerson(FamilyNeed $need, ?string $personCode): ?int
    {
        if ($personCode === null) {
            return null;
        }

        $current = $need->getOriginal('person_id');
        if ($current !== null && Person::whereKey($current)->value('person_code') === $personCode) {
            return $current;
        }

        // A new target must currently be an active member of this family.
        $personId = Person::query()
            ->where('person_code', $personCode)
            ->whereHas('activeMembership', fn ($q) => $q->where('family_id', $need->family_id))
            ->value('id');

        if ($personId === null) {
            throw ValidationException::withMessages([
                'person_code' => 'الشخص المحدد ليس فردًا نشطًا في هذه الأسرة.',
            ]);
        }

        return $personId;
    }

    private static function resolveAssessment(FamilyNeed $need, ?string $uuid): ?int
    {
        if ($uuid === null) {
            return null;
        }

        $assessment = Assessment::where('uuid', $uuid)->where('family_id', $need->family_id)->first();

        if ($assessment === null) {
            throw ValidationException::withMessages([
                'source_assessment_id' => 'التقييم المحدد لا يخص هذه الأسرة.',
            ]);
        }

        // Only a completed (immutable) assessment can be cited as a source.
        if ($assessment->status !== AssessmentStatus::COMPLETED && $assessment->id !== $need->getOriginal('source_assessment_id')) {
            throw ValidationException::withMessages([
                'source_assessment_id' => 'يمكن ربط الاحتياج بتقييم مكتمل فقط.',
            ]);
        }

        return $assessment->id;
    }
}
