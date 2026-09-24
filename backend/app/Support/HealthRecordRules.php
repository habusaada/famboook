<?php

namespace App\Support;

use App\Enums\Gender;
use App\Enums\HealthRecordType;
use App\Models\Person;
use App\Models\PersonHealthRecord;
use Illuminate\Validation\ValidationException;

/**
 * Domain invariants for person health records (docs/03-BUSINESS-RULES.md
 * §35-36), shared by the create/update actions. Field shape per type is
 * validated by the Form Requests; these rules need the Person and the
 * other stored records, so they live here and are enforced inside the
 * action's transaction.
 */
class HealthRecordRules
{
    /** Pregnancy/breastfeeding records are for FEMALE persons only. */
    public static function assertEligiblePerson(Person $person, HealthRecordType $type): void
    {
        if ($type->isMaternal() && $person->gender !== Gender::FEMALE) {
            throw ValidationException::withMessages([
                'person_code' => $type === HealthRecordType::PREGNANCY
                    ? 'لا يمكن تسجيل حمل إلا لأنثى.'
                    : 'لا يمكن تسجيل رضاعة إلا لأنثى.',
            ]);
        }
    }

    /**
     * No second ACTIVE record of the same kind for one Person: same
     * disability type, same (normalized) chronic disease, or a second
     * pregnancy/breastfeeding. Closed records never conflict.
     */
    public static function assertNoActiveDuplicate(PersonHealthRecord $record): void
    {
        if (! $record->isActive()) {
            return;
        }

        $others = PersonHealthRecord::query()
            ->where('person_id', $record->person_id)
            ->where('type', $record->type->value)
            ->active()
            ->when($record->exists, fn ($q) => $q->whereKeyNot($record->getKey()))
            ->get();

        [$field, $message, $duplicate] = match ($record->type) {
            HealthRecordType::DISABILITY => [
                'disability_type_id',
                'يوجد سجل إعاقة نشط من هذا النوع لهذا الشخص.',
                $others->contains('disability_type_id', $record->disability_type_id),
            ],
            HealthRecordType::CHRONIC_DISEASE => [
                'condition_name',
                'هذا المرض المزمن مسجّل مسبقًا لهذا الشخص.',
                $others->contains(fn ($o) => self::normalizeConditionName($o->condition_name)
                    === self::normalizeConditionName($record->condition_name)),
            ],
            HealthRecordType::PREGNANCY => ['type', 'يوجد سجل حمل نشط لهذا الشخص.', $others->isNotEmpty()],
            HealthRecordType::BREASTFEEDING => ['type', 'يوجد سجل رضاعة نشط لهذا الشخص.', $others->isNotEmpty()],
        };

        if ($duplicate) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /**
     * Gender integrity (docs/03 §36): a Person with an ACTIVE pregnancy or
     * breastfeeding record stays FEMALE. The record is never closed or
     * changed here; close it first, then correct the gender.
     */
    public static function assertGenderChangeAllowed(Person $person, Gender $newGender): void
    {
        if ($newGender === Gender::FEMALE) {
            return;
        }

        $hasActiveMaternal = $person->healthRecords()
            ->active()
            ->whereIn('type', [HealthRecordType::PREGNANCY->value, HealthRecordType::BREASTFEEDING->value])
            ->exists();

        if ($hasActiveMaternal) {
            throw ValidationException::withMessages([
                'gender' => 'لا يمكن تغيير جنس هذا الشخص لوجود سجل حمل أو رضاعة نشط. أغلق السجل أولًا ثم صحّح الجنس.',
            ]);
        }
    }

    public static function assertDates(PersonHealthRecord $record): void
    {
        if ($record->started_at && $record->ended_at && $record->ended_at->lt($record->started_at)) {
            throw ValidationException::withMessages([
                'ended_at' => 'لا يمكن أن يسبق تاريخ الانتهاء تاريخ البداية.',
            ]);
        }
    }

    /** Stored form: trimmed, internal whitespace collapsed. */
    public static function cleanConditionName(?string $name): ?string
    {
        return $name === null ? null : trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Comparison form only (never stored): simple Arabic/case/whitespace
     * normalization so "السكري" and "السكرى" are the same disease. Not
     * medical matching.
     */
    public static function normalizeConditionName(?string $name): string
    {
        $name = mb_strtolower(self::cleanConditionName($name) ?? '');
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $name); // diacritics, tatweel

        return strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }
}
