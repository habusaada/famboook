<?php

namespace App\Support\FamilyPortal;

use App\Enums\AuthSecurityEventOutcome;
use App\Enums\AuthSecurityEventType;
use App\Enums\SelfRevealField;
use App\Models\Person;
use App\Support\FamilyAuth\AuthSecurityLog;
use App\Support\FamilyAuth\FamilyAccessResult;

/**
 * The self sensitive-value reveal (docs/11 §23a, FP-ADR-062, PWA-3B.2): ONE
 * full value of the signed-in household head's OWN Person — the Person the
 * resolver put in the Family context, never one named by the client — for
 * one SelfRevealField, whose column is a fixed server-side mapping.
 *
 * Every authorized reveal is recorded BEFORE the value is returned: one
 * SELF_SENSITIVE_REVEALED security event with the field code as its only
 * metadata — never the value. A reveal of a field with no stored value is
 * still an authorized reveal request and is recorded the same way. No Family
 * Activity event: a reveal changes no registry data.
 */
final class SelfSensitiveReveal
{
    /** The stored value as entered, or NULL when nothing is recorded. */
    public function reveal(FamilyAccessResult $context, SelfRevealField $field): ?string
    {
        $value = Person::query()->whereKey($context->person->getKey())->value($field->column());

        AuthSecurityLog::record(
            AuthSecurityEventType::SELF_SENSITIVE_REVEALED,
            AuthSecurityEventOutcome::SUCCESS,
            person: $context->person,
            user: $context->user,
            actor: $context->user,
            link: $context->link,
            metadata: ['field' => $field->value],
        );

        return $value === null ? null : (string) $value;
    }
}
