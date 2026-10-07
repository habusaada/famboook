<?php

namespace App\Support\Credentials;

use App\Models\DigitalCredential;
use App\Models\Family;
use App\Models\FamilyMembership;

/**
 * What the PUBLIC verification page may show about a verified Family
 * credential (docs/11 FP-ADR-070, PFP-010) — an explicit allow-list:
 * the card number, the family_code, the issue date, the Clan and Branch
 * names and the CURRENT household head shortened to the first and last word
 * of the registered full name (omitted when there is no current head).
 *
 * Never a National ID, phone, birth date, residence, household size, member
 * names or count, health, needs, assistance, assessments, any id, the token
 * or audit data. The head is contextual, minimal information — not identity
 * verification — and is resolved live: a head change changes nothing else.
 */
final class FamilyCredentialPresenter
{
    /** @return array<string, string|null> */
    public static function present(DigitalCredential $credential, Family $family): array
    {
        return [
            'type' => 'FAMILY',
            'credential_number' => $credential->credential_number,
            'family_code' => $family->family_code,
            'issued_at' => $credential->issued_at->toDateString(),
            'clan' => $family->clan?->name,
            'branch' => $family->branch?->name,
            'head_name' => self::shortName(self::currentHeadName($family)),
        ];
    }

    /** The current household head's registered full name, or NULL. */
    public static function currentHeadName(Family $family): ?string
    {
        /** @var FamilyMembership|null $membership */
        $membership = $family->householdHeadMembership()->with('person')->first();

        // A soft-deleted Person is not loaded by the relation: no name.
        return $membership?->person?->full_name;
    }

    /**
     * The first and last whitespace-separated words of a name; one word stays
     * as it is. Deliberately no Arabic compound-name heuristics.
     */
    public static function shortName(?string $fullName): ?string
    {
        $words = preg_split('/\s+/u', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return null;
        }

        return count($words) === 1 ? $words[0] : $words[0].' '.$words[count($words) - 1];
    }
}
