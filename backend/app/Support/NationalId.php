<?php

namespace App\Support;

/**
 * Purpose-limited National ID comparison (docs/03-BUSINESS-RULES.md §47e).
 *
 * National IDs are stored as entered. For identity verification both the
 * stored and the typed value are normalized — Arabic-Indic and Persian
 * digits become ASCII digits; spaces, dashes, dots and slashes are dropped;
 * letters are upper-cased — and compared in constant time. There is no
 * global lookup by National ID: callers only compare against the specific
 * persons expected in an authorized context. Values are never logged,
 * echoed back or copied.
 */
class NationalId
{
    public static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return mb_strtoupper(preg_replace('/[\s\-\.\/_]+/u', '', $value) ?? '');
    }

    /** Whether a typed value identifies the person whose stored ID is $stored. */
    public static function matches(?string $stored, ?string $typed): bool
    {
        $stored = self::normalize($stored);
        $typed = self::normalize($typed);

        return $stored !== '' && $typed !== '' && hash_equals($stored, $typed);
    }
}
