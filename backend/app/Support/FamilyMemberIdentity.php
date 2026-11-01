<?php

namespace App\Support;

use App\Enums\FamilyMemberIdentityOutcome;
use App\Enums\LifeStatus;
use App\Models\Person;

/**
 * Who a National ID proposed as a new family member resolves to in the
 * registry (docs/11 FP-ADR-076). It decides and locks; it creates, attaches and
 * changes nothing, and the caller maps a refusal to its own error.
 *
 * Lock order: called with the Family locked and takes the National ID lock,
 * then — only for a Person with no active membership — the Person row, and
 * re-checks under it. A Person who is active in a Family is refused WITHOUT
 * locking their row: that Family's own writers lock Person → Family (e.g. a
 * death record's activity), so locking it here could close a cycle.
 */
final class FamilyMemberIdentity
{
    /** Inside the caller's transaction (the advisory lock is transaction-level). */
    public static function resolve(#[\SensitiveParameter] string $nationalId): FamilyMemberIdentityResolution
    {
        NationalIdGuard::lock($nationalId);
        $matches = NationalIdGuard::equivalentMatches($nationalId);
        if ($matches->count() > 1) {
            return new FamilyMemberIdentityResolution(FamilyMemberIdentityOutcome::AMBIGUOUS);
        }
        $match = $matches->first();
        if ($match === null) {
            return new FamilyMemberIdentityResolution(FamilyMemberIdentityOutcome::NEW_PERSON);
        }
        if ($match->activeMembership !== null) {
            return new FamilyMemberIdentityResolution(FamilyMemberIdentityOutcome::ACTIVE_MEMBERSHIP);
        }

        $person = Person::query()->whereKey($match->getKey())->lockForUpdate()->first();
        if ($person === null || ! self::attachable($person)) {
            return new FamilyMemberIdentityResolution(FamilyMemberIdentityOutcome::NOT_ATTACHABLE);
        }
        if ($person->activeMembership()->exists()) {
            return new FamilyMemberIdentityResolution(FamilyMemberIdentityOutcome::ACTIVE_MEMBERSHIP);
        }

        return new FamilyMemberIdentityResolution(FamilyMemberIdentityOutcome::ATTACHABLE, $person);
    }

    /** FP-ADR-076: only a living, active registry Person may be attached. */
    public static function attachable(Person $person): bool
    {
        return $person->life_status === LifeStatus::ALIVE && (bool) $person->is_active;
    }
}
