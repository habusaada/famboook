<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Privacy rule for staged import rows (docs/03 §96a): some source fields
 * are outside the approved Famboook dataset and are never persisted — not
 * in import_rows.raw_payload, not anywhere.
 *
 *   هويتك     no role in the import (never a National ID, never a Person)
 *   الديانة   religion is outside the approved dataset
 *
 * sanitize() is for the parser: it drops excluded fields from a source
 * row. assertClean() is the persistence guard (ImportRow::saving): an
 * excluded field reaching storage is a programming error, not data.
 *
 * Header comparison tolerates cosmetic variants (spacing, tatweel,
 * diacritics, alef/yeh/teh-marbuta forms). It is used only to recognise
 * excluded headers and never rewrites any stored value.
 */
final class ImportRawPayload
{
    /** Source headers that must never be persisted. */
    public const EXCLUDED_FIELDS = ['هويتك', 'الديانة'];

    /**
     * The payload without excluded fields (at any depth).
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isExcluded($key)) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     *
     * @throws InvalidArgumentException when an excluded field is present.
     */
    public static function assertClean(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isExcluded($key)) {
                // Never echo the value; the header is not sensitive.
                throw new InvalidArgumentException('Excluded import source field may not be persisted.');
            }
            if (is_array($value)) {
                self::assertClean($value);
            }
        }
    }

    public static function isExcluded(string $header): bool
    {
        $normalized = self::normalizeHeader($header);

        foreach (self::EXCLUDED_FIELDS as $excluded) {
            if ($normalized === self::normalizeHeader($excluded)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeHeader(string $header): string
    {
        // Diacritics (U+064B–U+0652, superscript alef U+0670) and tatweel.
        $header = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $header) ?? $header;
        $header = strtr($header, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);

        return trim(preg_replace('/\s+/u', ' ', $header) ?? $header);
    }
}
