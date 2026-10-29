<?php

namespace App\Support\CivilLookup;

/**
 * Is the Civil Registry Lookup open at all (FB-CIVIL-02A, ADR §L-1)? Off by
 * default; in production it also needs a well-formed approval reference in
 * the PROCESS environment (never .env or cached configuration), issued only
 * after G1 / OD-08 and OD-09.
 *
 * FB-CIVIL-02B (ADR 02B §B-2): each family-side context is a further gate on
 * top of the global one — its own configuration switch and, in production,
 * its own approval reference plus the EXTENDED_CONTROLLED field-profile
 * approval. No single variable opens a family-side context.
 */
final class CivilLookupGate
{
    public const VARIABLE = 'CIVIL_LOOKUP_PRODUCTION_APPROVAL';

    /** Production approval references of the family-side contexts (ADR 02B §B-2). */
    public const SIDE_VARIABLES = [
        CivilLookupContext::FAMILY => 'CIVIL_LOOKUP_FAMILY_APPROVAL',
        CivilLookupContext::COORDINATOR => 'CIVIL_LOOKUP_COORDINATOR_APPROVAL',
    ];

    /** The privacy approval of the EXTENDED_CONTROLLED field allowlist (ADR 02B §B-5). */
    public const PROFILE_VARIABLE = 'CIVIL_LOOKUP_EXTENDED_PROFILE_APPROVAL';

    private const SIDE_SWITCHES = [
        CivilLookupContext::FAMILY => 'civil_lookup.family_side_enabled',
        CivilLookupContext::COORDINATOR => 'civil_lookup.coordinator_side_enabled',
    ];

    private const REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,63}$/';

    public static function allows(): bool
    {
        return config('civil_lookup.enabled') === true
            && (! app()->environment('production') || self::reference() !== null);
    }

    /** The global gate and, for a family-side context, that context's own gate. */
    public static function allowsContext(string $context): bool
    {
        if (! self::allows()) {
            return false;
        }
        if ($context === CivilLookupContext::STAFF) {
            return true;
        }

        return self::sideSwitchOpen($context)
            && (! app()->environment('production')
                || (self::referenceOf(self::SIDE_VARIABLES[$context]) !== null && self::referenceOf(self::PROFILE_VARIABLE) !== null));
    }

    /** The configuration switch of a family-side context only (what may be GRANTED; ADR 02B §B-4). */
    public static function sideSwitchOpen(string $context): bool
    {
        return isset(self::SIDE_SWITCHES[$context]) && config(self::SIDE_SWITCHES[$context]) === true;
    }

    public static function reference(): ?string
    {
        return self::referenceOf(self::VARIABLE);
    }

    /** A well-formed approval reference in the PROCESS environment, or null (FB-CIVIL-02G reuses the format). */
    public static function approvalReference(string $variable): ?string
    {
        return self::referenceOf($variable);
    }

    public static function reverseLinksEnabled(): bool
    {
        return config('civil_lookup.reverse_links_enabled') === true;
    }

    private static function referenceOf(string $variable): ?string
    {
        $value = (string) getenv($variable);

        return preg_match(self::REFERENCE, $value) === 1 ? $value : null;
    }
}
