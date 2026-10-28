<?php

namespace App\Support\CivilLookup;

/**
 * Name normalization for the Civil Registry Lookup (ADR §L-5), identical to
 * the SQL function identity_ref_lookup.normalize_name (08-lookup.dba.sql):
 * diacritics (U+064B–U+065F, U+0670) and tatweel (U+0640) removed; أ إ آ ٱ → ا;
 * whitespace collapsed and trimmed. ة/ه and ى/ي are NOT merged. Used for the
 * search criteria only — displayed values always stay as in the source.
 */
final class ArabicNameNormalizer
{
    public const MAX_TOKENS = 6;

    public const MAX_LENGTH = 100;

    public static function normalize(string $value): string
    {
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا']);
        $value = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @return list<string> the normalized, non-empty tokens */
    public static function tokens(string $value): array
    {
        $normalized = self::normalize($value);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * The token rules of the database functions: $min..6 tokens, each at least
     * 2 characters, at least one of 3 or more.
     *
     * @param  list<string>  $tokens
     */
    public static function acceptable(array $tokens, int $min): bool
    {
        if (count($tokens) < $min || count($tokens) > self::MAX_TOKENS) {
            return false;
        }
        $lengths = array_map(fn (string $t) => mb_strlen($t), $tokens);

        return min($lengths) >= 2 && max($lengths) >= 3;
    }
}
