<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\RegistrationSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff recording of a new current household declaration
 * (RecordHouseholdDeclarationAction, docs/03 §55c, docs/11 FU-10).
 *
 * - The three counts are each optional (NULL = not declared), whole numbers
 *   from 0; at least one is required (enforced by the Domain Action, which
 *   mirrors the database CHECKs). No arithmetic relation between them, and
 *   none with the registered members, is checked.
 * - declared_at: when the source made the declaration; NULL = unknown.
 * - source: a direct Staff source only. IMPORT belongs to the import.
 * - expected_current_declaration_id: the current declaration the Staff
 *   screen showed, or null for "none yet" — always sent. A different
 *   current declaration answers 409 HOUSEHOLD_DECLARATION_CHANGED. It is a
 *   Staff-only reference: declarations have no public identifier, and it is
 *   never exposed to the Family Portal.
 *
 * Lifecycle and provenance fields are server-controlled and refused; notes
 * are not part of the Staff capability.
 */
class RecordHouseholdDeclarationRequest extends FormRequest
{
    public const STAFF_SOURCES = [
        RegistrationSource::PAPER_FORM,
        RegistrationSource::MANUAL_ENTRY,
        RegistrationSource::VERIFIED_SOURCE,
    ];

    private const COUNT_RULES = ['nullable', 'integer', 'min:0', 'max:32767'];

    public function authorize(): bool
    {
        return $this->user()?->can('family.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'declared_household_size' => self::COUNT_RULES,
            'declared_living_sons' => self::COUNT_RULES,
            'declared_living_daughters' => self::COUNT_RULES,
            'declared_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'source' => ['required', Rule::in(array_map(fn (RegistrationSource $s) => $s->value, self::STAFF_SOURCES))],
            'expected_current_declaration_id' => ['present', 'nullable', 'integer', 'min:1'],
            // Refused, not ignored: server-controlled or not part of this capability.
            'family_id' => ['prohibited'],
            'is_current' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'notes' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.integer' => 'يجب أن تكون القيمة عددًا صحيحًا.',
            '*.min' => 'لا يمكن أن تكون القيمة سالبة.',
            '*.max' => 'القيمة كبيرة جدًا.',
            'declared_at.date_format' => 'تاريخ الإقرار غير صالح.',
            'declared_at.before_or_equal' => 'تاريخ الإقرار لا يمكن أن يكون في المستقبل.',
            'source.required' => 'مصدر الإقرار مطلوب.',
            'source.in' => 'مصدر الإقرار غير صالح.',
            'expected_current_declaration_id.present' => 'الإقرار الحالي المتوقع مطلوب.',
            'expected_current_declaration_id.integer' => 'الإقرار الحالي المتوقع غير صالح.',
            'expected_current_declaration_id.min' => 'الإقرار الحالي المتوقع غير صالح.',
            'family_id.prohibited' => 'تُحدَّد الأسرة من الرابط فقط.',
            'is_current.prohibited' => 'يحدّد النظام الإقرار الحالي.',
            'created_by.prohibited' => 'يحدّد النظام منشئ الإقرار.',
            'updated_by.prohibited' => 'يحدّد النظام معدّل الإقرار.',
            'notes.prohibited' => 'لا تُرسَل ملاحظات مع الإقرار.',
        ];
    }

    /** @return array<string, mixed> the declaration, as the Domain Action expects it */
    public function declaration(): array
    {
        return $this->safe()->only([
            'declared_household_size', 'declared_living_sons', 'declared_living_daughters', 'declared_at', 'source',
        ]);
    }

    public function expectedCurrentId(): ?int
    {
        $id = $this->validated('expected_current_declaration_id');

        return $id === null ? null : (int) $id;
    }
}
