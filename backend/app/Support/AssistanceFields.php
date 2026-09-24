<?php

namespace App\Support;

use App\Enums\AssistanceStatus;
use App\Enums\AssistanceType;
use App\Enums\Currency;
use App\Enums\ExecutionMode;
use App\Models\Assistance;
use App\Models\AssistanceCategory;
use App\Models\AssistanceItem;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Request rules and domain rules for an Assistance definition and its
 * planned items (docs/03-BUSINESS-RULES.md §47a). Shared by the create and
 * update Domain Actions.
 *
 * DRAFT: everything is editable (metadata, items, targeting criteria).
 * OPEN: only description, target count and planned dates — conservative,
 * so nominees are never evaluated against a changed definition.
 */
class AssistanceFields
{
    public const OPEN_EDITABLE = ['description', 'target_beneficiaries', 'start_date', 'end_date'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:150'],
            'category_code' => [$required, 'string', Rule::exists('assistance_categories', 'code')],
            'assistance_type' => [$required, Rule::enum(AssistanceType::class)],
            // Chosen while DRAFT; locked once OPEN (not in OPEN_EDITABLE).
            'execution_mode' => [$required, Rule::enum(ExecutionMode::class)],
            'provider_name' => [$required, 'string', 'max:150'],
            'target_beneficiaries' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'max:50'],
            'items.*' => ['array:item_name,quantity_per_beneficiary,unit,unit_value,currency'],
            'items.*.item_name' => ['required', 'string', 'max:150'],
            'items.*.quantity_per_beneficiary' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_value' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'items.*.currency' => ['nullable', Rule::enum(Currency::class)],
            ...TargetingCriteria::rules('targeting_criteria'),
            // Lifecycle and derived values never come from the payload.
            'status' => ['prohibited'],
            'opened_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'title.required' => 'اسم المساعدة مطلوب.',
            'title.max' => 'اسم المساعدة طويل جدًا.',
            'category_code.required' => 'تصنيف المساعدة مطلوب.',
            'category_code.exists' => 'تصنيف المساعدة غير معروف.',
            'assistance_type.required' => 'نوع المساعدة مطلوب.',
            'assistance_type.enum' => 'نوع المساعدة غير صالح.',
            'execution_mode.required' => 'طريقة التنفيذ مطلوبة.',
            'execution_mode.enum' => 'طريقة التنفيذ غير صالحة.',
            'provider_name.required' => 'الجهة المقدمة مطلوبة.',
            'provider_name.max' => 'اسم الجهة المقدمة طويل جدًا.',
            'target_beneficiaries.integer' => 'عدد المستفيدين المستهدف يجب أن يكون عددًا صحيحًا.',
            'target_beneficiaries.min' => 'عدد المستفيدين المستهدف يجب أن يكون 1 على الأقل.',
            'start_date.date_format' => 'تاريخ البداية غير صالح.',
            'end_date.date_format' => 'تاريخ النهاية غير صالح.',
            'items.max' => 'عدد العناصر كبير جدًا.',
            'items.*.item_name.required' => 'اسم العنصر مطلوب.',
            'items.*.item_name.max' => 'اسم العنصر طويل جدًا.',
            'items.*.quantity_per_beneficiary.gt' => 'الكمية يجب أن تكون أكبر من صفر.',
            'items.*.quantity_per_beneficiary.numeric' => 'الكمية يجب أن تكون رقمًا.',
            'items.*.quantity_per_beneficiary.decimal' => 'الكمية تقبل منزلتين عشريتين على الأكثر.',
            'items.*.unit_value.gt' => 'قيمة الوحدة يجب أن تكون أكبر من صفر.',
            'items.*.unit_value.numeric' => 'قيمة الوحدة يجب أن تكون رقمًا.',
            'items.*.unit_value.decimal' => 'قيمة الوحدة تقبل منزلتين عشريتين على الأكثر.',
            'items.*.currency.enum' => 'العملة غير مدعومة.',
            'status.prohibited' => 'لا يمكن تحديد حالة المساعدة مباشرة.',
            'opened_at.prohibited' => 'لا يمكن تحديد تاريخ الفتح مباشرة.',
            ...TargetingCriteria::messages('targeting_criteria'),
        ];
    }

    /**
     * Applies validated fields (and, when sent, the full item list) to an
     * Assistance and saves it. Returns whether anything changed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function apply(Assistance $assistance, array $data): bool
    {
        if (! $assistance->isDraft() && $assistance->status !== AssistanceStatus::OPEN) {
            abort(409, 'لا يمكن تعديل هذه المساعدة.');
        }

        if ($assistance->status === AssistanceStatus::OPEN) {
            $locked = array_diff(array_keys($data), self::OPEN_EDITABLE);
            abort_if(
                $locked !== [],
                409,
                'بعد فتح المساعدة يمكن تعديل الوصف والعدد المستهدف والتواريخ فقط.'
            );
        }

        if (array_key_exists('category_code', $data)) {
            $category = AssistanceCategory::where('code', $data['category_code'])->firstOrFail();
            if (! $category->is_active && $category->id !== $assistance->getOriginal('assistance_category_id')) {
                throw ValidationException::withMessages([
                    'category_code' => "تصنيف المساعدة «{$category->name}» غير مفعّل.",
                ]);
            }
            $assistance->assistance_category_id = $category->id;
        }

        $assistance->fill(array_intersect_key($data, array_flip([
            'title', 'assistance_type', 'execution_mode', 'provider_name', 'target_beneficiaries',
            'start_date', 'end_date', 'description',
        ])));

        if (array_key_exists('targeting_criteria', $data)) {
            $criteria = TargetingCriteria::normalize($data['targeting_criteria'], 'targeting_criteria');
            $assistance->targeting_criteria = $criteria === [] ? null : $criteria;
        }

        if ($assistance->start_date && $assistance->end_date && $assistance->end_date->lt($assistance->start_date)) {
            throw ValidationException::withMessages([
                'end_date' => 'تاريخ النهاية يجب ألا يسبق تاريخ البداية.',
            ]);
        }

        $changed = ! $assistance->exists || $assistance->isDirty();
        $assistance->save();

        if (array_key_exists('items', $data) && self::syncItems($assistance, $data['items'])) {
            $changed = true;
            $assistance->touch();
        }

        return $changed;
    }

    /**
     * Replaces the planned items of a DRAFT Assistance. Items have no
     * history of their own before the Assistance is opened.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private static function syncItems(Assistance $assistance, array $items): bool
    {
        $rows = [];
        foreach (array_values($items) as $index => $item) {
            $value = $item['unit_value'] ?? null;
            $currency = $item['currency'] ?? null;
            if ($value !== null && $currency === null) {
                throw ValidationException::withMessages(["items.{$index}.currency" => 'اختر العملة عند تحديد قيمة الوحدة.']);
            }
            if ($value === null && $currency !== null) {
                throw ValidationException::withMessages(["items.{$index}.unit_value" => 'أدخل قيمة الوحدة عند تحديد العملة.']);
            }

            $rows[] = [
                'item_name' => trim($item['item_name']),
                'quantity_per_beneficiary' => self::decimal($item['quantity_per_beneficiary'] ?? null),
                'unit' => $item['unit'] ?? null,
                'unit_value' => self::decimal($value),
                'currency' => $currency,
                'sort_order' => $index + 1,
            ];
        }

        $current = $assistance->items()->get()->map(fn (AssistanceItem $i) => [
            'item_name' => $i->item_name,
            'quantity_per_beneficiary' => $i->getRawOriginal('quantity_per_beneficiary') === null ? null : self::decimal($i->getRawOriginal('quantity_per_beneficiary')),
            'unit' => $i->unit,
            'unit_value' => $i->getRawOriginal('unit_value') === null ? null : self::decimal($i->getRawOriginal('unit_value')),
            'currency' => $i->currency?->value,
            'sort_order' => $i->sort_order,
        ])->all();

        if ($current === $rows) {
            return false;
        }

        AssistanceItem::where('assistance_id', $assistance->id)->delete();
        foreach ($rows as $row) {
            $assistance->items()->create($row);
        }

        return true;
    }

    private static function decimal(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }
}
