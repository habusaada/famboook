<?php

namespace App\Support\FamilyPortal\RegistryAdmission;

use App\Enums\CivilValueStatus;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Support\CivilLookup\CivilRecord;
use DateTimeImmutable;

/**
 * The strict reading of raw civil registry values for direct admission
 * (FB-FAMILY-ADD-01 ADD-WP-05; ADR §5). Internal only: nothing here is a response.
 *
 * - A value is matched EXACTLY as stored: never trimmed, normalized or compared by
 *   similarity. Anything not listed is UNSUPPORTED, never guessed.
 * - The listed values are the ones OBSERVED in the 2026-10-25 export (aggregate audit
 *   WP-04B). No source owner has confirmed them, so every mapping is PROVISIONAL
 *   (gate G-04) and cannot authorize an admission.
 * - Parentage comes only from fatherid / motherid (gate G-03), never from names.
 * - The record is only read; nothing is written anywhere.
 */
final class CivilRegistryValues
{
    /** Gate G-04: no mapping is approved by the source owner yet. */
    public const MAPPINGS_APPROVED = false;

    /** Observed gender values, exactly as stored (WP-04B). NULL = listed without a mapping. */
    public const GENDER = [
        'ذكر' => Gender::MALE,
        'أنثى' => Gender::FEMALE,
        'غير معروف' => null,
    ];

    /** Observed marital values, exactly as stored (WP-04B). NULL = listed without a mapping. */
    public const MARITAL = [
        'اعزب' => MaritalStatus::SINGLE,
        'متزوج' => MaritalStatus::MARRIED,
        'أرمل' => MaritalStatus::WIDOWED,
        'مطلق' => MaritalStatus::DIVORCED,
        // No MaritalStatus case says this, and none is guessed.
        'متعدد الزوجات' => null,
        'غير مسجل' => null,
    ];

    /** The one birth-date shape observed (WP-04B); any other is not a date here. */
    private const DATE_FORMAT = '!Y-m-d\TH:i:s';

    public static function gender(?string $raw): MappedValue
    {
        return self::fromList($raw, self::GENDER);
    }

    public static function maritalStatus(?string $raw): MappedValue
    {
        return self::fromList($raw, self::MARITAL);
    }

    /** Exactly nine ASCII digits and not nine identical ones, as stored. */
    public static function validNationalId(#[\SensitiveParameter] ?string $raw): bool
    {
        return is_string($raw) && preg_match('/\A[0-9]{9}\z/', $raw) === 1 && preg_match('/\A([0-9])\1{8}\z/', $raw) !== 1;
    }

    /**
     * Which parent field names the head: 'FATHER', 'MOTHER', NULL for neither, and
     * 'BOTH' when both do (an inconsistent record). Exact comparison only.
     */
    public static function parentLink(CivilRecord $record, #[\SensitiveParameter] string $headNationalId): ?string
    {
        $father = ($record->payload['fatherid'] ?? null) === $headNationalId;
        $mother = ($record->payload['motherid'] ?? null) === $headNationalId;

        return match (true) {
            $father && $mother => 'BOTH',
            $father => 'FATHER',
            $mother => 'MOTHER',
            default => null,
        };
    }

    /** The full name as stored, or NULL when empty. */
    public static function fullName(CivilRecord $record): ?string
    {
        $value = $record->payload['fullname'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** A date in the observed shape as Y-m-d, otherwise NULL (never invented). */
    public static function date(?string $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        // Round trip: a value PHP would silently adjust (e.g. 2026-02-30) is not a date.
        return $date->format('Y-m-d\TH:i:s') === $raw ? $date->format('Y-m-d') : null;
    }

    /** @param  array<string, \BackedEnum|null>  $list */
    private static function fromList(?string $raw, array $list): MappedValue
    {
        if ($raw === null || $raw === '') {
            return new MappedValue(CivilValueStatus::MISSING);
        }
        if (! array_key_exists($raw, $list)) {
            return new MappedValue(CivilValueStatus::UNSUPPORTED);
        }

        return $list[$raw] === null
            ? new MappedValue(CivilValueStatus::INDETERMINATE)
            : new MappedValue(CivilValueStatus::MAPPED, $list[$raw]);
    }
}
