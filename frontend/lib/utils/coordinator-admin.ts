import type { CoordinatorAssignment, CoordinatorRecordedRevokeReason, CoordinatorRevokeReason, CoordinatorScopeType } from "@/lib/types/api/coordinator-admin";

// Arabic wording for the Staff coordinator administration (CO-01B).

export const coordinatorScopeTypeLabels: Record<CoordinatorScopeType, string> = {
  CLAN: "العشيرة كاملة",
  BRANCH_GROUP: "مجموعة فروع",
  BRANCH: "فرع",
};

export const coordinatorRevokeReasonLabels: Record<CoordinatorRecordedRevokeReason, string> = {
  ADMINISTRATIVE: "قرار إداري",
  NO_LONGER_ELIGIBLE: "لم يعد مؤهلًا",
  SCOPE_CHANGED: "تغيّر النطاق",
  ROLE_REMOVED: "إلغاء دور المنسق",
};

/** The reasons an administrator may choose (ROLE_REMOVED is set by the server). */
export const COORDINATOR_REVOKE_REASONS: readonly CoordinatorRevokeReason[] = ["ADMINISTRATIVE", "NO_LONGER_ELIGIBLE", "SCOPE_CHANGED"];

/** "عشيرة › مجموعة › فرع" for an assignment's target. */
export function coordinatorScopeTarget(assignment: CoordinatorAssignment): string {
  const parts = [assignment.clan.name];
  if (assignment.branch_group) parts.push(assignment.branch_group.name ?? assignment.branch_group.code);
  if (assignment.branch) parts.push(assignment.branch.name);
  return parts.join(" › ");
}
