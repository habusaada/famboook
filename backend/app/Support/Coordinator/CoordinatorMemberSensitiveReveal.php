<?php

namespace App\Support\Coordinator;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\CoordinatorRevealDenial;
use App\Enums\CoordinatorRevealField;
use App\Models\Family;
use App\Models\Person;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\CoordinatorAccessResult;

/**
 * The Coordinator member reveal (CO-01C, docs/06 AUTH-ADR-092): ONE full
 * value — National ID or primary mobile — of ONE member of ONE Family in the
 * Coordinator's scope. Never in bulk.
 *
 *   coordinator.space context (re-resolved on this request)
 *   → CoordinatorScopes::families() WHERE family_code   (out of scope = absent)
 *   → CoordinatorMemberReference::resolve               (ACTIVE memberships of that Family only)
 *   → an available Person (soft-deleted is unavailable)
 *   → the one allow-listed column
 *
 * Every attempt that reaches this class records exactly one
 * COORDINATOR_MEMBER_SENSITIVE_REVEALED security event: SUCCESS before the
 * value is returned, DENIED with a reason code otherwise. actor/user = the
 * Coordinator; family = the Family ONLY when it was resolved inside the scope
 * (never looked up otherwise); person = the target ONLY when resolved;
 * metadata = the field code. Never the value, a mask, the member reference or
 * the requested code. Every refusal is the same `found: false` for the caller
 * (one generic 404): the reason is never revealed.
 */
final class CoordinatorMemberSensitiveReveal
{
    public function __construct(private readonly CoordinatorMemberDirectory $directory) {}

    /**
     * @return array{found: bool, value: ?string} value NULL = nothing recorded
     */
    public function reveal(CoordinatorAccessResult $context, string $familyCode, string $memberRef, CoordinatorRevealField $field): array
    {
        $family = $this->directory->familyIdentity($context, $familyCode);
        if ($family === null) {
            return $this->deny($context, $field, CoordinatorRevealDenial::FAMILY_NOT_IN_SCOPE);
        }

        $membership = CoordinatorMemberReference::resolve($family, $memberRef);
        if ($membership === null) {
            return $this->deny($context, $field, CoordinatorRevealDenial::MEMBER_NOT_FOUND, $family);
        }

        $column = $field->column();
        // The default scope leaves soft-deleted Persons out: unavailable.
        $person = Person::query()->whereKey($membership->person_id)->first(['id', $column]);
        if ($person === null) {
            return $this->deny($context, $field, CoordinatorRevealDenial::MEMBER_UNAVAILABLE, $family);
        }
        $value = $person->getAttribute($column);

        // Recorded BEFORE the value leaves: no audit, no value.
        $this->record(AuthSecurityEventOutcome::SUCCESS, $context, $field, null, $family, $person);

        return ['found' => true, 'value' => $value === null ? null : (string) $value];
    }

    /** @return array{found: false, value: null} */
    private function deny(CoordinatorAccessResult $context, CoordinatorRevealField $field, CoordinatorRevealDenial $reason, ?Family $family = null): array
    {
        $this->record(AuthSecurityEventOutcome::DENIED, $context, $field, $reason, $family);

        return ['found' => false, 'value' => null];
    }

    private function record(
        AuthSecurityEventOutcome $outcome,
        CoordinatorAccessResult $context,
        CoordinatorRevealField $field,
        ?CoordinatorRevealDenial $reason,
        ?Family $family,
        ?Person $person = null,
    ): void {
        AuthSecurityLog::record(
            AuthSecurityEventType::COORDINATOR_MEMBER_SENSITIVE_REVEALED,
            $outcome,
            reason: $reason,
            person: $person,
            user: $context->user,
            actor: $context->user,
            metadata: ['field' => $field->value],
            family: $family,
        );
    }
}
