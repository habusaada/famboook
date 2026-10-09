<?php

namespace App\Support\ChangeRequests;

use App\Enums\ChangeRequestAttestation;
use App\Models\ChangeRequest;

/**
 * Optional handler capability (FP-ADR-076): approving this type needs
 * explicit reviewer attestations, and approval-only checks that must never
 * run where a family could observe them (identity matches would be an
 * enumeration oracle at submission).
 *
 * ApproveChangeRequestAction calls assertApprovable() inside its
 * transaction, after the standard re-validation and base check, and records
 * the required attestation codes in the APPROVED event metadata.
 */
interface RequiresApprovalAttestation
{
    /** @return list<ChangeRequestAttestation> every attestation approval requires */
    public function requiredAttestations(): array;

    /**
     * Refuse approval unless the evidence and the current registry allow it:
     * ValidationException for missing / wrong evidence (field errors for the
     * reviewer), ChangeRequestException PRECONDITION_FAILED for a registry
     * state that makes the request unsafe to approve. Fixed messages, no
     * values.
     */
    public function assertApprovable(ChangeRequest $request, ChangeRequestTarget $target, ChangeRequestApprovalEvidence $evidence): void;
}
