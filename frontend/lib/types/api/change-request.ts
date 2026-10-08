// Types mirroring the PWA-5c Staff Change Request Resources:
// backend/app/Http/Resources/ChangeRequestSummaryResource.php,
// ChangeRequestResource.php, WorkflowEventResource.php and
// ChangeRequestOutcomeResource.php. Machine codes only; Arabic wording
// lives in lib/utils/change-request.ts.

// backend/app/Enums/ChangeRequestStatus.php (DRAFT is unused in V1).
export type ChangeRequestStatus =
  | "DRAFT"
  | "SUBMITTED"
  | "UNDER_REVIEW"
  | "RETURNED_FOR_CLARIFICATION"
  | "RESUBMITTED"
  | "APPROVED"
  | "REJECTED"
  | "APPLIED"
  | "CANCELLED";

// backend/app/Enums/ChangeRequestType.php — approved codes. A code is not
// an available type: `type_available` says whether a handler is registered.
export type ChangeRequestType =
  | "CONTACT_UPDATE"
  | "RESIDENCE_UPDATE"
  | "PERSON_CORRECTION"
  | "ADD_FAMILY_MEMBER"
  | "MEMBERSHIP_CHANGE"
  | "HOUSEHOLD_HEAD_CHANGE"
  | "BIRTH_REPORT"
  | "DEATH_REPORT"
  | "MARRIAGE_UPDATE"
  | "DOCUMENT_UPDATE"
  | "OTHER";

// backend/app/Enums/WorkflowEventType.php
export type WorkflowEventType =
  | "SUBMITTED"
  | "REVIEW_STARTED"
  | "RETURNED"
  | "RESUBMITTED"
  | "APPROVED"
  | "REJECTED"
  | "APPLIED"
  | "APPLY_FAILED"
  | "CANCELLED";

export type WorkflowActorSide = "FAMILY" | "STAFF" | "SYSTEM";

// backend/app/Enums/ChangeRequestRejectionReason.php
export type ChangeRequestRejectionReason =
  | "INSUFFICIENT_INFORMATION"
  | "CANNOT_VERIFY"
  | "DATA_ALREADY_CORRECT"
  | "DUPLICATE_REQUEST"
  | "DATA_CHANGED"
  | "NO_LONGER_APPLICABLE"
  | "OTHER";

// backend/app/Enums/ChangeRequestApplyFailure.php
export type ChangeRequestApplyFailure = "BASE_CHANGED" | "PRECONDITION_FAILED" | "NOT_APPLICABLE" | "APPLY_FAILED";

// backend/app/Support/ChangeRequests/ChangeRequestStaffActions.php
export type ChangeRequestAction = "start_review" | "return" | "approve" | "reject" | "apply";

type Named = { name: string | null } | null;

export interface ChangeRequestFamily {
  family_code: string;
  household_head_name: string | null;
}

/** A row of GET /api/v1/change-requests — never the proposal or a message. */
export interface ChangeRequestSummary {
  /** Public UUID (the database id is never exposed). */
  id: string;
  request_code: string;
  type: ChangeRequestType;
  /** Whether a handler for this type is registered (the Production registry is empty until PWA-6). */
  type_available: boolean;
  status: ChangeRequestStatus;
  family: ChangeRequestFamily;
  submitted_by: { name: string | null };
  submitted_at: string | null;
  reviewed_at: string | null;
  approved_at: string | null;
  rejected_at: string | null;
  applied_at: string | null;
  cancelled_at: string | null;
  apply_failure_count: number;
  /** UX hint from status, permissions and handler availability — never authorization. */
  available_actions: ChangeRequestAction[];
}

export interface WorkflowEvent {
  event_type: WorkflowEventType;
  from_status: ChangeRequestStatus | null;
  to_status: ChangeRequestStatus;
  actor: { name: string } | null;
  actor_side: WorkflowActorSide;
  /** A rejection reason (REJECTED) or an apply failure code (APPLY_FAILED). */
  reason_code: string | null;
  /** Family-visible text: a clarification request, the family's response or a rejection message. */
  public_message: string | null;
  /** Present ONLY for holders of change-request.view-internal-notes; the key is absent otherwise. */
  internal_note?: string | null;
  created_at: string;
}

/** GET /api/v1/change-requests/{uuid} — the Staff review view. */
export interface ChangeRequestDetail {
  id: string;
  request_code: string;
  type: ChangeRequestType;
  type_available: boolean;
  payload_version: number;
  status: ChangeRequestStatus;
  family: ChangeRequestFamily;
  target_person: { person_code: string; full_name: string } | null;
  submitted_by: { name: string | null };
  submitted_at: string | null;
  /** The requester's own explanation. */
  reason: string | null;
  /**
   * The type handler's STAFF presentation; null without a registered
   * handler. Its shape belongs to the handler — see comparisonRows().
   */
  presentation: unknown;
  review: {
    reviewed_by: Named;
    reviewed_at: string | null;
    approved_by: Named;
    approved_at: string | null;
    applied_by: Named;
    applied_at: string | null;
    cancelled_at: string | null;
  };
  rejection: {
    reason_code: ChangeRequestRejectionReason;
    message: string | null;
    rejected_by: Named;
    rejected_at: string;
  } | null;
  apply_failures: {
    count: number;
    last_failed_at: string | null;
    last_code: string | null;
  };
  timeline: WorkflowEvent[];
  available_actions: ChangeRequestAction[];
}

/** The outcome of a workflow action: identity, new status, replay flag. */
export interface ChangeRequestOutcome {
  id: string;
  request_code: string;
  status: ChangeRequestStatus;
  replayed: boolean;
}

/** The PWA-5c queue filters (allow-listed by ChangeRequestIndexRequest). */
export interface ChangeRequestFilters {
  status?: ChangeRequestStatus;
  type?: ChangeRequestType;
  family?: string;
  request_code?: string;
  submitted_from?: string;
  submitted_to?: string;
  page?: number;
}
