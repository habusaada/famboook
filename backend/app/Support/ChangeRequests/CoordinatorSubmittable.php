<?php

namespace App\Support\ChangeRequests;

/**
 * A ChangeRequestHandler whose type a Coordinator may submit about a Family
 * in their scope (CO-02.3b; docs/06 AUTH-ADR-093). Opt-in: a handler that
 * does not implement it can never be submitted through the COORDINATOR
 * channel. Everything else — validation, normalization, base values,
 * preconditions, open conflicts, Staff approval and APPLY — is the handler's
 * existing contract, unchanged.
 */
interface CoordinatorSubmittable
{
    /**
     * The target inside the context's Family, for a COORDINATOR submission.
     * There is no family.context for that Family: never resolve a household
     * member reference here, never read an id from the input.
     *
     * @param  array<string, mixed>  $validated
     */
    public function resolveCoordinatorTarget(ChangeRequestSubmissionContext $context, array $validated): ChangeRequestTarget;
}
