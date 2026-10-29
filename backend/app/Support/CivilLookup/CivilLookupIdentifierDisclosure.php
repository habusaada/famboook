<?php

namespace App\Support\CivilLookup;

/**
 * Where the eye-icon reveal of a complete National ID is offered
 * (FB-CIVIL-02G, ADR 02B §B-5b). Disclosure is never automatic: lists and
 * records stay masked, and one identifier is revealed per explicit request
 * (LookupCivilRegistryAction::revealIdentifier), authorized, charged and
 * audited there.
 *
 *   STAFF        always (02F approved staff disclosure)
 *   FAMILY       its own switch, OFF by default; in production also its approval reference
 *   COORDINATOR  its own switch, independent of FAMILY, OFF by default; same rule
 *
 * The lookup permission of the side is the authority (no family-relationship
 * requirement, owner decision 2026-10-29); this only says whether a side may
 * reveal at all.
 */
final class CivilLookupIdentifierDisclosure
{
    /** Production approval references of the family-side reveal. */
    public const APPROVAL_VARIABLES = [
        CivilLookupContext::FAMILY => 'CIVIL_LOOKUP_FAMILY_ID_REVEAL_APPROVAL',
        CivilLookupContext::COORDINATOR => 'CIVIL_LOOKUP_COORDINATOR_ID_REVEAL_APPROVAL',
    ];

    private const SWITCHES = [
        CivilLookupContext::FAMILY => 'civil_lookup.family_id_reveal_enabled',
        CivilLookupContext::COORDINATOR => 'civil_lookup.coordinator_id_reveal_enabled',
    ];

    public static function revealOpen(string $context): bool
    {
        if ($context === CivilLookupContext::STAFF) {
            return true;
        }

        return isset(self::SWITCHES[$context]) && config(self::SWITCHES[$context]) === true
            && (! app()->environment('production') || CivilLookupGate::approvalReference(self::APPROVAL_VARIABLES[$context]) !== null);
    }
}
