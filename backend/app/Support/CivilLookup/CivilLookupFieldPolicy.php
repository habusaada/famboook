<?php

namespace App\Support\CivilLookup;

use App\Support\NationalIdMask;

/**
 * Field-level visibility of the Civil Registry Lookup (FB-CIVIL-02A, ADR §L-4),
 * enforced here, in the API — never only in the UI. Values are returned raw
 * (undocumented codes are never interpreted); an empty value is NULL ("غير
 * متوفر" in the UI). `relgion` is in no level: the database functions already
 * remove it and this policy never lists it.
 *
 * Every level is an explicit allowlist: a source field that is not listed is
 * never returned, so a new source column can never appear by itself.
 *
 *   FULL                 Staff with civil-lookup.view-full (all 35 fields, identifiers unmasked)
 *   STANDARD             other Staff
 *   EXTENDED_CONTROLLED  FB-CIVIL-02B, the Family Portal and Coordinator Space (ADR 02B §B-5):
 *                        identity, family-status and place fields only; identifiers always masked.
 *                        Each family-side context maps to it separately (CONTEXT_LEVELS), so the
 *                        two can diverge when their legal entitlements do.
 */
final class CivilLookupFieldPolicy
{
    public const RESULT = 'RESULT';

    public const STANDARD = 'STANDARD';

    public const FULL = 'FULL';

    public const EXTENDED_CONTROLLED = 'EXTENDED_CONTROLLED';

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

    /**
     * FB-CIVIL-02B: the EXTENDED_CONTROLLED allowlist, subject to the field-level privacy approval
     * (CIVIL_LOOKUP_EXTENDED_PROFILE_APPROVAL). The father / mother / spouse references and the
     * household reference are the record's links (masked identifiers, each opening a new lookup).
     */
    public const EXTENDED_CONTROLLED_FIELDS = ['mainid', 'fullname', 'name1', 'father', 'grandfath', 'family', 'gender', 'datebirth',
        'age', 'maritalst', 'govern', 'city', 'birthplace'];

    /** The profile of each family-side lookup context (the Staff level depends on civil-lookup.view-full). */
    public const CONTEXT_LEVELS = [
        CivilLookupContext::FAMILY => self::EXTENDED_CONTROLLED,
        CivilLookupContext::COORDINATOR => self::EXTENDED_CONTROLLED,
    ];

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
        $visible = match ($level) {
            self::FULL => array_merge(...array_values(self::SECTIONS)),
            self::STANDARD => self::STANDARD_FIELDS,
            self::EXTENDED_CONTROLLED => self::EXTENDED_CONTROLLED_FIELDS,
        };
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
