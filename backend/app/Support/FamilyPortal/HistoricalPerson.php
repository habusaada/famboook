<?php

namespace App\Support\FamilyPortal;

/**
 * The Family-facing form of a Person named on a historical household record
 * — a Need's person, an assistance beneficiary or recipient (PWA-3B.7). The
 * record belongs to its Family for good, so a Person who has since left or
 * died keeps their name; a soft-deleted Person is unavailable and nothing of
 * them is returned. The member_ref (FU-13) only while the Person still has an
 * ACTIVE membership in the Family. Never an id or person_code.
 */
final class HistoricalPerson
{
    /** @return array{member_ref: ?string, full_name: ?string, available: bool} */
    public static function member(int $familyId, ?string $fullName, ?string $deletedAt, ?int $currentMembershipId): array
    {
        $available = $deletedAt === null;

        return [
            'member_ref' => $available && $currentMembershipId !== null
                ? HouseholdMemberReference::of($familyId, $currentMembershipId)
                : null,
            'full_name' => $available ? $fullName : null,
            'available' => $available,
        ];
    }

    /** @return array{full_name: ?string, available: bool} */
    public static function name(?string $fullName, ?string $deletedAt): array
    {
        $available = $deletedAt === null;

        return ['full_name' => $available ? $fullName : null, 'available' => $available];
    }

    /** A stored decimal as the Staff App shows it: "5.00" → "5", "2.50" → "2.5"; NULL stays NULL. */
    public static function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
