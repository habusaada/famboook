<?php

namespace App\Support\ChangeRequests;

use App\Models\ChangeRequest;
use App\Models\Family;

/**
 * A ChangeRequestHandler whose request proposes a Family that does not exist
 * yet (CO-02.3b / CO-02.5; REGISTER_FAMILY / FAMILY_ADD, docs/04 DB-ADR-062):
 * the request has no family_id, only a proposed Clan / Branch, and the Family
 * is created by the Staff APPLY. The engine then uses these methods instead
 * of the target-based ones (no ChangeRequestTarget exists before APPLY):
 *
 * - submission (SubmitChangeRequestAction::handleCoordinatorProposal): the
 *   input is validated with proposalRules() and stored as
 *   normalizeProposal(); proposalOpenConflictKey() limits open proposals per
 *   SUBMITTING USER (never across submitters: that would tell one
 *   Coordinator what another proposed); the base fingerprint is taken from
 *   proposalBaseValues(). Submission never answers anything that depends on
 *   the registry (e.g. whether a National ID exists). V1: only a
 *   Coordinator proposes a Family (chk_change_request_channel_type), so this
 *   interface is the opt-in for that submission;
 * - approve and APPLY: the engine locks the request, then the proposed Clan
 *   and Branch rows (refusing an inactive placement as NOT_APPLICABLE), then
 *   calls proposalPreconditions() and compares proposalBaseValues() with
 *   the stored base fingerprint;
 * - approval of a type that also RequiresApprovalAttestation checks the
 *   required attestation codes, then assertProposalApprovable();
 * - APPLY calls applyProposal() — existing canonical Domain Actions only —
 *   and records the returned Family as created_family_id in the same
 *   transaction as APPLIED, after checking that it was created in exactly
 *   the proposed Clan and Branch.
 *
 * No Family, Person or identifier may be created before APPLY.
 */
interface ProposesNewFamily
{
    /**
     * Laravel validation rules for the proposal input; also re-checked
     * against the stored proposal at approve and APPLY.
     *
     * @return array<string, mixed>
     */
    public function proposalRules(): array;

    /**
     * The proposal to store as submitted_data: a non-empty map built only
     * from validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeProposal(array $validated): array;

    /**
     * Two OPEN proposals of this type with the same key may not coexist for
     * one submitting user. NULL = no limit.
     *
     * @param  array<string, mixed>  $data  the normalized proposal
     */
    public function proposalOpenConflictKey(array $data): ?string;

    /** Re-validate the stored proposal against current state (data rules and business rules). */
    public function proposalPreconditions(ChangeRequest $request): void;

    /**
     * The current canonical state the proposal depends on (e.g. who holds
     * the proposed head's National ID), read fresh under the locks it needs;
     * only its keyed fingerprint is stored.
     *
     * @return array<string, mixed>
     */
    public function proposalBaseValues(ChangeRequest $request): array;

    /** Approval-only checks of the reviewer's evidence (RequiresApprovalAttestation types). */
    public function assertProposalApprovable(ChangeRequest $request, ChangeRequestApprovalEvidence $evidence): void;

    /** Create the Family through the canonical Domain Actions; return it. */
    public function applyProposal(ChangeRequest $request, int $actingUserId): Family;
}
