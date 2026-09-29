<?php

namespace App\Support\Import;

/**
 * The explicit source vocabulary for comparing staged text with registry
 * enums during reconciliation (docs/03 §96a). These are fixed, documented
 * spelling lists — NOT fuzzy Arabic normalization: a value outside a list is
 * "unmapped" and surfaces for review; it is never guessed.
 */
final class SourceValues
{
    public const GENDER = [
        'ذكر' => 'MALE',
        'أنثى' => 'FEMALE',
        'انثى' => 'FEMALE',
    ];

    public const MARITAL_STATUS = [
        'أعزب' => 'SINGLE',
        'اعزب' => 'SINGLE',
        'عزباء' => 'SINGLE',
        'متزوج' => 'MARRIED',
        'متزوجة' => 'MARRIED',
        // A polygamous head is married; the wives are separate Persons.
        'متعدد الزوجات' => 'MARRIED',
        'مطلق' => 'DIVORCED',
        'مطلقة' => 'DIVORCED',
        'أرمل' => 'WIDOWED',
        'ارمل' => 'WIDOWED',
        'أرملة' => 'WIDOWED',
        'ارملة' => 'WIDOWED',
    ];

    // The exact source text of a polygamous head (maps to MARRIED above); only
    // this value enables the polygamous-household rule (docs/03 §96a).
    public const POLYGAMOUS = 'متعدد الزوجات';

    // Both approved death spellings (متوفى in the real workbook, متوفي earlier).
    public const LIFE_STATUS = [
        'حي' => 'ALIVE',
        'حية' => 'ALIVE',
        'متوفى' => 'DECEASED',
        'متوفي' => 'DECEASED',
        'متوفاة' => 'DECEASED',
    ];

    /** @param array<string, string> $list */
    public static function map(array $list, ?string $value): ?string
    {
        return $value === null ? null : ($list[$value] ?? null);
    }
}
