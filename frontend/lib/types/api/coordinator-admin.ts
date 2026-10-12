// Types mirroring the Staff coordinator API (CO-01B):
// backend/app/Http/Controllers/Api/V1/PersonCoordinatorController.php
// (state), CoordinatorAssignmentResource.php (one assignment),
// AssignCoordinatorScopeRequest.php and RevokeCoordinatorRequest.php
// (payloads). Machine codes only; Arabic wording lives in
// lib/utils/coordinator-admin.ts.

/** backend/app/Enums/CoordinatorScopeType.php */
export type CoordinatorScopeType = "CLAN" | "BRANCH_GROUP" | "BRANCH";

/** The reasons Staff may choose (CoordinatorRevokeReason::chosen()). */
export type CoordinatorRevokeReason = "ADMINISTRATIVE" | "NO_LONGER_ELIGIBLE" | "SCOPE_CHANGED";

/** Every recorded reason: ROLE_REMOVED is set by a role revocation only. */
export type CoordinatorRecordedRevokeReason = CoordinatorRevokeReason | "ROLE_REMOVED";

/** One scope assignment, with its history. `id` is the public uuid. */
export interface CoordinatorAssignment {
  id: string;
  scope_type: CoordinatorScopeType;
  clan: { code: string; name: string };
  branch_group: { code: string; name: string | null } | null;
  branch: { code: string; name: string } | null;
  active: boolean;
  assigned_at: string | null;
  assigned_by: string | null;
  revoked_at: string | null;
  revoked_by: string | null;
  revoke_reason: CoordinatorRecordedRevokeReason | null;
}

/** GET/POST /api/v1/people/{person}/coordinator — the authoritative state. */
export interface PersonCoordinatorState {
  person_code: string;
  /** The Person has a family-side (Family Portal) account. */
  has_family_account: boolean;
  /** The account holds the COORDINATOR role. */
  coordinator: boolean;
  /** Coordinator Space is open right now (role + permission + scope + head eligibility). */
  coordinator_space: boolean;
  /** Newest first, active and revoked. */
  assignments: CoordinatorAssignment[];
}

/** POST /api/v1/people/{person}/coordinator/scopes — target by public codes. */
export type AssignCoordinatorScopePayload =
  | { scope_type: "CLAN"; clan: string }
  | { scope_type: "BRANCH_GROUP"; clan: string; branch_group: string }
  | { scope_type: "BRANCH"; clan: string; branch: string };
