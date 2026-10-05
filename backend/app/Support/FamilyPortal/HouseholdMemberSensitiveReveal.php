<?php

namespace App\Support\FamilyPortal;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\SelfRevealField;
use App\Exceptions\HouseholdMemberUnavailableException;
use App\Models\Person;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAccessResult;

/**
 * The household-member sensitive-value reveal (docs/11 §23a, FP-ADR-064,
 * PWA-3B.4): ONE full value of ANOTHER member of the signed-in head's
 * household, for one SelfRevealField (a fixed server-side column mapping).
 *
 * The target is resolved strictly inside the Family Portal context:
 *
 *   family.context Family
 *   → HouseholdMemberReference::resolve (ACTIVE memberships of that Family only)
 *   → never the head's own membership (self values: the self reveal only)
 *   → an available Person (soft-deleted is unavailable)
 *   → the one allow-listed field
 *
 * Every failure is the same HouseholdMemberUnavailableException (404): the
 * reason is never revealed. member_ref grants nothing by itself.
 *
 * Every authorized reveal — also one whose stored value is null — records
 * exactly one HOUSEHOLD_MEMBER_SENSITIVE_REVEALED security event BEFORE the
 * value is returned: person = the TARGET Person, user / actor = the head,
 * link = the head's link, metadata = the field code only (never the value,
 * a mask, the member reference or an identifier). No Family Activity: a
 * reveal changes no registry data.
 */
final class HouseholdMemberSensitiveReveal
{
    /** The stored value as entered, or NULL when nothing is recorded. */
    public function reveal(FamilyAccessResult $context, string $memberRef, SelfRevealField $field): ?string
    {
        $membership = HouseholdMemberReference::resolve($context, $memberRef);
        if (
            $membership === null
            || $membership->is($context->membership)
            || (int) $membership->person_id === (int) $context->person->getKey()
        ) {
            throw new HouseholdMemberUnavailableException;
        }

        $column = $field->column();
        // The default scope leaves soft-deleted Persons out: unavailable.
        $person = Person::query()->whereKey($membership->person_id)->first(['id', $column]);
        if ($person === null) {
            throw new HouseholdMemberUnavailableException;
        }
        $value = $person->getAttribute($column);

        AuthSecurityLog::record(
            AuthSecurityEventType::HOUSEHOLD_MEMBER_SENSITIVE_REVEALED,
            AuthSecurityEventOutcome::SUCCESS,
            person: $person,
            user: $context->user,
            actor: $context->user,
            link: $context->link,
            metadata: ['field' => $field->value],
        );

        return $value === null ? null : (string) $value;
    }
}
