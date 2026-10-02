<?php

namespace App\Support\FamilyAuth;

/**
 * The shared character handling of the strict Family Portal normalizers
 * (docs/11 §30a). Arabic-Indic and Persian digits become ASCII digits; then
 * ONLY whitespace, the bidirectional marks an RTL keyboard inserts and the
 * separators - . / _ are removed. Letters and any other symbol are kept, so
 * the caller's exact-format check rejects them: arbitrary input can never be
 * stripped into a valid identifier.
 */
final class FamilyInput
{
    public const MAX_LENGTH = 32;

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** Unicode space separators, tab, line breaks, LRM, RLM, ALM, and - . / _ */
    private const REMOVABLE = '/[\p{Z}\t\n\r\x{200E}\x{200F}\x{061C}\-\.\/_]+/u';

    /** The cleaned text, or null when the input can never be valid. */
    public static function clean(mixed $input): ?string
    {
        if (! is_string($input) || mb_strlen($input) > self::MAX_LENGTH) {
            return null;
        }

        // NULL on malformed UTF-8: rejected, never guessed.
        return preg_replace(self::REMOVABLE, '', strtr($input, self::DIGITS));
    }
}
