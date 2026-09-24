<?php

namespace App\Support;

use App\Enums\DisplacementStatus;
use App\Enums\Gender;
use App\Enums\HealthRecordType;
use App\Enums\LifeStatus;
use App\Enums\MaritalStatus;
use App\Models\AssistanceBeneficiary;
use App\Models\Person;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The controlled catalog of fields an EXTERNAL Assistance may include in an
 * issued beneficiary list (docs/03-BUSINESS-RULES.md §47g). Not a report
 * builder: only these keys exist, each with fixed semantics; a custom
 * column label never changes what a field means.
 *
 * Subject person: the nominated Person for a person-level beneficiary, the
 * CURRENT household head for a family-level one. Family fields always
 * describe the beneficiary's family. "Members" are persons with an ACTIVE
 * membership who are not DECEASED (same population as health indicators).
 */
class ExportFieldCatalog
{
    public const STANDARD = 'STANDARD';

    public const CONTACT = 'CONTACT';

    public const SENSITIVE = 'SENSITIVE';

    /** key => [default label, classification, semantics] */
    public const FIELDS = [
        'family_code' => ['رقم الأسرة', self::STANDARD, 'Family code of the beneficiary family.'],
        'person_code' => ['رقم الشخص', self::STANDARD, 'Person code of the subject person.'],
        'beneficiary_name' => ['اسم المستفيد', self::STANDARD, 'Full name of the subject person.'],
        'household_head_name' => ['اسم رب الأسرة', self::STANDARD, 'Full name of the current household head.'],
        'national_id' => ['رقم الهوية', self::SENSITIVE, 'National ID of the subject person, as stored.'],
        'date_of_birth' => ['تاريخ الميلاد', self::STANDARD, 'Birth date of the subject person (YYYY-MM-DD).'],
        'gender' => ['الجنس', self::STANDARD, 'Gender of the subject person (ذكر/أنثى).'],
        'marital_status' => ['الحالة الاجتماعية', self::STANDARD, 'Marital status of the subject person.'],
        'primary_mobile' => ['رقم الجوال', self::CONTACT, 'Mobile of the subject person.'],
        'alternate_mobile' => ['رقم جوال بديل', self::CONTACT, 'Alternate mobile of the subject person.'],
        'family_members_count' => ['عدد أفراد الأسرة', self::STANDARD, 'Active living members of the family.'],
        'original_residence' => ['السكن الأصلي', self::STANDARD, 'original_residence_text of the current residence.'],
        'displacement_status' => ['حالة النزوح', self::STANDARD, 'Current displacement status (نازحة/غير نازحة).'],
        'displacement_location' => ['مكان النزوح', self::STANDARD, 'displacement_location_text of the current residence.'],
        'children_under_2_count' => ['عدد الأطفال دون سنتين', self::STANDARD, 'Active living members under two years old today.'],
        'has_disability' => ['يوجد إعاقة', self::SENSITIVE, 'نعم/لا: an active disability record (family: any member; person: that person).'],
        'has_chronic_disease' => ['يوجد مرض مزمن', self::SENSITIVE, 'نعم/لا: an active chronic-disease record (same scope).'],
        'has_pregnancy' => ['يوجد حمل', self::SENSITIVE, 'نعم/لا: an active pregnancy record (same scope).'],
        'has_breastfeeding' => ['يوجد رضاعة', self::SENSITIVE, 'نعم/لا: an active breastfeeding record (same scope).'],
    ];

    private const HEALTH_FIELDS = [
        'has_disability' => HealthRecordType::DISABILITY,
        'has_chronic_disease' => HealthRecordType::CHRONIC_DISEASE,
        'has_pregnancy' => HealthRecordType::PREGNANCY,
        'has_breastfeeding' => HealthRecordType::BREASTFEEDING,
    ];

    private const MARITAL_LABELS = [
        'SINGLE' => 'أعزب/عزباء',
        'MARRIED' => 'متزوج/ة',
        'DIVORCED' => 'مطلق/ة',
        'WIDOWED' => 'أرمل/ة',
        'UNKNOWN' => 'غير معروف',
    ];

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::FIELDS);
    }

    public static function classification(string $key): string
    {
        return self::FIELDS[$key][1];
    }

    /** @param  list<array{field_key: string}>  $fields */
    public static function containsSensitive(array $fields): bool
    {
        foreach ($fields as $field) {
            if (self::classification($field['field_key']) === self::SENSITIVE) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, string>> */
    public static function catalog(): array
    {
        return collect(self::FIELDS)->map(fn ($f, $key) => [
            'field_key' => $key,
            'default_label' => $f[0],
            'classification' => $f[1],
        ])->values()->all();
    }

    /**
     * Current values for the given beneficiaries and field keys, in
     * beneficiary order. Only the requested keys are ever resolved.
     *
     * @param  Collection<int, AssistanceBeneficiary>  $beneficiaries
     * @param  list<string>  $keys
     * @return list<array<string, string|int|null>>
     */
    public static function resolve(Collection $beneficiaries, array $keys, CarbonInterface $today): array
    {
        // Accept any collection of beneficiaries (order is preserved).
        $beneficiaries = EloquentCollection::make($beneficiaries->all());
        $beneficiaries->loadMissing([
            'person',
            'family.householdHeadMembership.person',
            'family.currentResidence',
            'family.memberships' => fn ($q) => $q->where('is_active', true)->with([
                'person.healthRecords' => fn ($h) => $h->active(),
            ]),
        ]);

        return $beneficiaries->map(function (AssistanceBeneficiary $b) use ($keys, $today) {
            $family = $b->family;
            $head = $family->householdHeadMembership?->person;
            $subject = $b->person ?? $head;
            $members = $family->memberships
                ->map->person
                ->filter(fn (?Person $p) => $p !== null && $p->life_status !== LifeStatus::DECEASED);
            $residence = $family->currentResidence;
            // Health indicators: that person, or any living member for the family.
            $healthScope = $b->person_id !== null
                ? $members->where('id', $b->person_id)
                : $members;

            $row = [];
            foreach ($keys as $key) {
                $row[$key] = match ($key) {
                    'family_code' => $family->family_code,
                    'person_code' => $subject?->person_code,
                    'beneficiary_name' => $subject?->full_name,
                    'household_head_name' => $head?->full_name,
                    'national_id' => $subject?->national_id,
                    'date_of_birth' => $subject?->birth_date?->toDateString(),
                    'gender' => match ($subject?->gender) {
                        Gender::MALE => 'ذكر',
                        Gender::FEMALE => 'أنثى',
                        default => null,
                    },
                    'marital_status' => $subject ? self::MARITAL_LABELS[($subject->marital_status ?? MaritalStatus::UNKNOWN)->value] : null,
                    'primary_mobile' => $subject?->mobile,
                    'alternate_mobile' => $subject?->alternate_mobile,
                    'family_members_count' => $members->count(),
                    'original_residence' => $residence?->original_residence_text,
                    'displacement_status' => match ($residence?->displacement_status) {
                        DisplacementStatus::DISPLACED->value, DisplacementStatus::DISPLACED => 'نازحة',
                        DisplacementStatus::NOT_DISPLACED->value, DisplacementStatus::NOT_DISPLACED => 'غير نازحة',
                        default => null,
                    },
                    'displacement_location' => $residence?->displacement_location_text,
                    'children_under_2_count' => $members->filter(fn (Person $p) => FamilyHealthSummary::isUnderTwo($p, $today))->count(),
                    default => $healthScope->contains(
                        fn (Person $p) => $p->healthRecords->contains('type', self::HEALTH_FIELDS[$key])
                    ) ? 'نعم' : 'لا',
                };
            }

            return $row;
        })->values()->all();
    }
}
