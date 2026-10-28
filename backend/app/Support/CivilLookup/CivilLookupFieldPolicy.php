<?php

namespace App\Support\CivilLookup;

use App\Support\NationalIdMask;

/**
 * Field-level visibility of the Civil Registry Lookup (FB-CIVIL-02A, ADR §L-4),
 * enforced here, in the API — never only in the UI. Values are returned raw
 * (undocumented codes are never interpreted); an empty value is NULL ("غير
 * متوفر" in the UI). `relgion` is in no level: the database functions already
 * remove it and this policy never lists it.
 */
final class CivilLookupFieldPolicy
{
    public const RESULT = 'RESULT';

    public const STANDARD = 'STANDARD';

    public const FULL = 'FULL';

    /** The 35 displayable source fields by section (the 36 source columns minus relgion). */
    public const SECTIONS = [
        'personal' => ['mainid', 'fullname', 'name1', 'father', 'grandfath', 'family', 'gender', 'datebirth', 'age', 'birthplace',
            'maritalst', 'deathdate', 'presonstate'],
        'family' => ['fatherid', 'motherid', 'partnerid', 'householdid', 'householdrel', 'prevfamily'],
        'geographic' => ['govern', 'city', 'street'],
        'social' => ['worker', 'worktype', 'job', 'civilg', 'cwloc', 'mgrade', 'mstartw', 'msalary'],
        'reference' => ['sert1', 'phonenum', 'att', 'source', 'select'],
    ];

    /** A result-list entry (civil-lookup.search). */
    public const RESULT_FIELDS = ['fullname', 'gender', 'datebirth', 'govern', 'city'];

    /** An opened record without civil-lookup.view-full. */
    public const STANDARD_FIELDS = ['mainid', 'fullname', 'name1', 'father', 'grandfath', 'family', 'gender', 'datebirth', 'age',
        'birthplace', 'maritalst', 'govern', 'city', 'householdrel'];

    /** Fields shown masked unless FULL. */
    private const IDENTIFIERS = ['mainid', 'fatherid', 'motherid', 'partnerid'];

    /** @return array<string, ?string> the result-list fields */
    public static function summary(CivilRecord $record): array
    {
        $fields = ['national_id' => NationalIdMask::mask($record->mainid)];
        foreach (self::RESULT_FIELDS as $key) {
            $fields[$key] = self::value($record->payload[$key] ?? null);
        }

        return $fields;
    }

    /**
     * @return list<array{key: string, fields: list<array{key: string, value: ?string}>}>
     */
    public static function sections(CivilRecord $record, string $level): array
    {
        $visible = $level === self::FULL ? array_merge(...array_values(self::SECTIONS)) : self::STANDARD_FIELDS;
        $sections = [];
        foreach (self::SECTIONS as $section => $keys) {
            $fields = [];
            foreach ($keys as $key) {
                if (in_array($key, $visible, true)) {
                    $value = $key === 'mainid' ? $record->mainid : self::value($record->payload[$key] ?? null);
                    $fields[] = ['key' => $key, 'value' => self::identifier($key, $value, $level)];
                }
            }
            if ($fields !== []) {
                $sections[] = ['key' => $section, 'fields' => $fields];
            }
        }

        return $sections;
    }

    /** A National ID as this level may show it. */
    public static function identifier(string $key, ?string $value, string $level): ?string
    {
        return $value !== null && $level !== self::FULL && in_array($key, self::IDENTIFIERS, true) ? NationalIdMask::mask($value) : $value;
    }

    private static function value(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
