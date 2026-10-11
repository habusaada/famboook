"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import { useFamilyAccessFailure } from "@/lib/api/family-household";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type {
  ChangeRequestOutcome,
  ChangeRequestRejectionReason,
  ChangeRequestStatus,
  ChangeRequestType,
  WorkflowActorSide,
  WorkflowEventType,
} from "@/lib/types/api/change-request";

// The household head's Change Requests (PWA-5e Family API). The server
// resolves the Family from the session — nothing here sends a family,
// person or member identifier — and the history is the whole Family's.
// Kept in the query cache only (cleared on sign-out), never in browser
// storage. No status is changed optimistically and no mutation is retried.

/** A row of GET /api/v1/family/change-requests (FamilyChangeRequestSummaryResource). */
export type FamilyChangeRequestSummary = {
  id: string;
  request_code: string;
  type: ChangeRequestType;
  type_available: boolean;
  status: ChangeRequestStatus;
  submitted_at: string | null;
  approved_at: string | null;
  rejected_at: string | null;
  applied_at: string | null;
  cancelled_at: string | null;
  available_actions: FamilyChangeRequestAction[];
};

export type FamilyChangeRequestAction = "resubmit" | "cancel";

/** A family-visible timeline event (FamilyWorkflowEventResource): no names, no internal notes. */
export type FamilyWorkflowEvent = {
  event_type: WorkflowEventType;
  from_status: ChangeRequestStatus | null;
  to_status: ChangeRequestStatus;
  actor_side: WorkflowActorSide;
  /** A rejection reason code, on REJECTED events only. */
  reason_code: ChangeRequestRejectionReason | null;
  public_message: string | null;
  created_at: string;
};

/** GET /api/v1/family/change-requests/{uuid} (FamilyChangeRequestResource). */
export type FamilyChangeRequest = {
  id: string;
  request_code: string;
  type: ChangeRequestType;
  type_available: boolean;
  status: ChangeRequestStatus;
  submitted_at: string | null;
  reason: string | null;
  /** The handler's FAMILY presentation, or null without a registered handler. */
  presentation: unknown;
  approved_at: string | null;
  applied_at: string | null;
  cancelled_at: string | null;
  rejection: { reason_code: ChangeRequestRejectionReason; message: string | null; rejected_at: string } | null;
  timeline: FamilyWorkflowEvent[];
  available_actions: FamilyChangeRequestAction[];
};

/** GET /api/v1/family/change-requests/types: what may be submitted NOW. */
export type FamilyChangeRequestTypes = {
  data: { type: ChangeRequestType }[];
  meta: { submission_enabled: boolean };
};

export type FamilyChangeRequestFilters = {
  status?: ChangeRequestStatus;
  type?: ChangeRequestType;
  page?: number;
};

const PATH = "/api/v1/family/change-requests";

export const familyChangeRequestKeys = {
  all: ["family", "change-requests"] as const,
  list: (filters: FamilyChangeRequestFilters) => ["family", "change-requests", "list", filters] as const,
  detail: (id: string) => ["family", "change-requests", "detail", id] as const,
  types: ["family", "change-requests", "types"] as const,
  relationshipTypes: ["family", "change-requests", "relationship-types"] as const,
};

/** Only the PWA-5e history filters, and only those that are set. */
export function familyChangeRequestQuery(filters: FamilyChangeRequestFilters): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.type) params.set("type", filters.type);
  if (filters.page && filters.page > 1) params.set("page", String(filters.page));
  return params.toString();
}

export function useFamilyChangeRequestsQuery(filters: FamilyChangeRequestFilters) {
  const query = familyChangeRequestQuery(filters);
  const result = useQuery({
    queryKey: familyChangeRequestKeys.list(filters),
    queryFn: () => apiClient.get<PaginatedResponse<FamilyChangeRequestSummary>>(query ? `${PATH}?${query}` : PATH),
    placeholderData: keepPreviousData,
    retry: false,
  });
  useFamilyAccessFailure(result.error);

  return result;
}

export function useFamilyChangeRequestQuery(id: string) {
  const result = useQuery({
    queryKey: familyChangeRequestKeys.detail(id),
    queryFn: async () => (await apiClient.get<{ data: FamilyChangeRequest }>(`${PATH}/${encodeURIComponent(id)}`)).data,
    enabled: id.length > 0,
    retry: false,
  });
  useFamilyAccessFailure(result.error);

  return result;
}

export function useFamilyChangeRequestTypesQuery() {
  const result = useQuery({
    queryKey: familyChangeRequestKeys.types,
    queryFn: () => apiClient.get<FamilyChangeRequestTypes>(`${PATH}/types`),
    // Shared by the bottom navigation and the request screens: one read per
    // minute at most, refreshed on focus; cleared with the cache on sign-out.
    staleTime: 60 * 1000,
    retry: false,
  });
  useFamilyAccessFailure(result.error);

  return result;
}

export type FamilyChangeRequestSubmission = {
  type: ChangeRequestType;
  /** A UUID per proposal: the same one is re-sent on a retry, so the server replays instead of duplicating. */
  client_reference: string;
  reason: string | null;
  data: Record<string, unknown>;
};

/**
 * Submit a new request (POST /api/v1/family/change-requests). The Family,
 * requester and target come from the session on the server — never from here.
 * retry: false — a retry is the user's, with the same client_reference.
 */
export function useSubmitFamilyChangeRequest() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: FamilyChangeRequestSubmission) =>
      (await apiClient.post<{ data: ChangeRequestOutcome }>(PATH, input)).data,
    retry: false,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ["family", "change-requests", "list"] });
    },
  });
}

/** An active, non-HEAD relationship type (GET …/change-requests/relationship-types). */
export type FamilyRelationshipOption = { code: string; name: string };

/** The add-member relationship options, from the registry (never a client list). */
export function useFamilyRelationshipTypesQuery(enabled = true) {
  const result = useQuery({
    queryKey: familyChangeRequestKeys.relationshipTypes,
    queryFn: async () => (await apiClient.get<{ data: FamilyRelationshipOption[] }>(`${PATH}/relationship-types`)).data,
    staleTime: 5 * 60 * 1000,
    enabled,
    retry: false,
  });
  useFamilyAccessFailure(result.error);

  return result;
}

export type FamilyChangeRequestMutation = { action: "resubmit"; response: string } | { action: "cancel" };

/**
 * Resubmit or cancel one of the Family's requests. Success OR failure re-reads
 * the request and the history (after a 409 another decision may have moved
 * it). retry: false — never re-sent automatically.
 */
export function useFamilyChangeRequestMutation(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: FamilyChangeRequestMutation) =>
      (
        await apiClient.post<{ data: ChangeRequestOutcome }>(
          `${PATH}/${encodeURIComponent(id)}/${input.action}`,
          input.action === "resubmit" ? { response: input.response } : {}
        )
      ).data,
    retry: false,
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: familyChangeRequestKeys.detail(id) });
      void queryClient.invalidateQueries({ queryKey: ["family", "change-requests", "list"] });
    },
  });
}

/**
 * Whether a type could be STARTED from the Family Portal: the server lists it
 * (registered, family-submittable, switch on) AND a Family form for it exists
 * in this client. PWA-6.1: RESIDENCE_UPDATE only.
 */
export const FAMILY_REQUEST_FORMS: Partial<Record<ChangeRequestType, string>> = {
  RESIDENCE_UPDATE: "/family/requests/new/residence-update",
  // FP-ADR-076. Offered only when the server lists the type — it is not a
  // Production type yet, so discovery never lists it there.
  ADD_FAMILY_MEMBER: "/family/requests/new/add-family-member",
};

export function familyRequestFormFor(type: ChangeRequestType): string | null {
  return FAMILY_REQUEST_FORMS[type] ?? null;
}

/**
 * May the household head START a new request now (docs/11 FP-ADR-074)? Only
 * from a successful server answer: submission enabled, and at least one
 * listed (registered, family-submittable, permitted) type with a Family
 * form in this client. Loading, an error or no answer → false.
 */
export function canStartFamilyRequest(types: FamilyChangeRequestTypes | undefined): boolean {
  if (!types?.meta.submission_enabled) return false;
  return types.data.some(({ type }) => familyRequestFormFor(type) !== null);
}

/** A friendly Arabic message for a failed family action — never a raw error. */
export function familyChangeRequestErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return "تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.";
  switch (error.status) {
    case 401:
      return "انتهت الجلسة. سجّل الدخول مرة أخرى.";
    case 403:
      return "لا يمكنك تنفيذ هذا الإجراء.";
    case 404:
      return "هذا الطلب غير متاح.";
    case 409:
      return "تغيّرت حالة الطلب. حدّثنا الصفحة بحالته الحالية.";
    case 422:
      return "تحقق من النص المدخل.";
    case 429:
      return "محاولات كثيرة خلال وقت قصير. حاول بعد قليل.";
    case 503:
      return "هذه الخدمة غير متاحة حاليًا.";
    default:
      return "حدث خطأ غير متوقع. حاول مرة أخرى لاحقًا.";
  }
}
