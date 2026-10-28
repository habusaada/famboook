<?php

namespace App\Support\CivilLookup;

/**
 * Is the Civil Registry Lookup open at all (FB-CIVIL-02A, ADR §L-1)? Off by
 * default; in production it also needs a well-formed approval reference in
 * the PROCESS environment (never .env or cached configuration), issued only
 * after G1 / OD-08 and OD-09.
 */
final class CivilLookupGate
{
    public const VARIABLE = 'CIVIL_LOOKUP_PRODUCTION_APPROVAL';

    private const REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,63}$/';

    public static function allows(): bool
    {
        return config('civil_lookup.enabled') === true
            && (! app()->environment('production') || self::reference() !== null);
    }

    public static function reference(): ?string
    {
        $value = (string) getenv(self::VARIABLE);

        return preg_match(self::REFERENCE, $value) === 1 ? $value : null;
    }

    public static function reverseLinksEnabled(): bool
    {
        return config('civil_lookup.reverse_links_enabled') === true;
    }
}
