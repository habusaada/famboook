"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import { changeRequestErrorCode } from "@/lib/api/change-requests";
import { COORDINATOR_QUERY_KEY } from "@/lib/api/coordinator";
import type { FamilyChangeRequestMutation, FamilyRelationshipOption, FamilyWorkflowEvent } from "@/lib/api/family-change-requests";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type { ChangeRequestOutcome, ChangeRequestRejectionReason, ChangeRequestStatus, ChangeRequestType } from "@/lib/types/api/change-request";

// The Coordinator's own Change Requests (CO-02.4 / CO-02.5 / CO-02.6a API,
// docs/adr/CO-02-COORDINATOR-REQUESTS.md §15–§17). The server decides
// everything: which requests exist for this Coordinator (their own, in the
// CURRENT scope), which Families and placements may be used, which types
// may be submitted and which actions are offered. Nothing here grants
// anything, nothing is retried automatically, and no status is changed
// optimistically. Kept in the query cache only (cleared on sign-out).

/** The two types a Coordinator may propose (CO-02). */
export type CoordinatorRequestType = Extract<ChangeRequestType, "ADD_FAMILY_MEMBER" | "REGISTER_FAMILY">;

export type CoordinatorRequestAction = "resubmit" | "cancel";

/** A proposed placement (REGISTER_FAMILY): public codes and names only. */
export type CoordinatorPlacement = {
  clan: { code: string; name: string | null };
  branch: { code: string; name: string | null } | null;
};

/** A row of GET …/coordinator/requests (CoordinatorChangeRequestSummaryResource). */
export type CoordinatorRequestSummary = {
  id: string;
  request_code: string;
  type: ChangeRequestType;
  type_available: boolean;
  status: ChangeRequestStatus;
  /** The Family the request is about, or — after APPLY — the Family a proposal created. */
  family_code: string | null;
  placement: CoordinatorPlacement | null;
  created_family_code: string | null;
  /** The proposed Person's name, as the Coordinator typed it. */
  summary: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  rejected_at: string | null;
  applied_at: string | null;
  cancelled_at: string | null;
  available_actions: CoordinatorRequestAction[];
};

/** GET …/coordinator/requests/{uuid} (CoordinatorChangeRequestResource). */
export type CoordinatorRequest = {
  id: string;
  request_code: string;
  type: ChangeRequestType;
  type_available: boolean;
  status: ChangeRequestStatus;
  family_code: string | null;
  placement: CoordinatorPlacement | null;
  created_family_code: string | null;
  summary: string | null;
  submitted_at: string | null;
  reason: string | null;
  /** The handler's COORDINATOR presentation (identifiers masked), or null. */
  presentation: unknown;
  approved_at: string | null;
  applied_at: string | null;
  cancelled_at: string | null;
  rejection: { reason_code: ChangeRequestRejectionReason; message: string | null; rejected_at: string } | null;
  /** The family-visible timeline: no names, no internal notes, no apply diagnostics. */
  timeline: FamilyWorkflowEvent[];
  available_actions: CoordinatorRequestAction[];
};

export type CoordinatorRequestTypes = {
  data: { type: ChangeRequestType }[];
  meta: { submission_enabled: boolean };
};

/** One Clan of GET …/requests/placements: what the CURRENT scope allows. */
export type CoordinatorPlacementOption = {
  clan: { code: string; name: string | null };
  branchless_allowed: boolean;
  branches: { code: string; name: string | null; group: { code: string; name: string | null } | null }[];
};

export type CoordinatorRequestFilters = {
  status?: ChangeRequestStatus;
  type?: CoordinatorRequestType;
  page?: number;
};

const BASE = "/api/v1/family/coordinator";
const PATH = `${BASE}/requests`;

export const coordinatorRequestKeys = {
  all: [...COORDINATOR_QUERY_KEY, "requests"] as const,
  list: (filters: CoordinatorRequestFilters & { perPage?: number }) => [...COORDINATOR_QUERY_KEY, "requests", "list", filters] as const,
  detail: (id: string) => [...COORDINATOR_QUERY_KEY, "requests", "detail", id] as const,
  types: [...COORDINATOR_QUERY_KEY, "requests", "types"] as const,
  relationshipTypes: [...COORDINATOR_QUERY_KEY, "requests", "relationship-types"] as const,
  placements: [...COORDINATOR_QUERY_KEY, "requests", "placements"] as const,
};

/** Only the allow-listed filters, and only those that are set. */
export function coordinatorRequestQuery(filters: CoordinatorRequestFilters & { perPage?: number }): string {
  const params = new URLSearchParams();
  if (filters.status) params.set("status", filters.status);
  if (filters.type) params.set("type", filters.type);
  if (filters.page && filters.page > 1) params.set("page", String(filters.page));
  if (filters.perPage) params.set("per_page", String(filters.perPage));
  return params.toString();
}

export function useCoordinatorRequestsQuery(filters: CoordinatorRequestFilters & { perPage?: number }) {
  const query = coordinatorRequestQuery(filters);
  return useQuery({
    queryKey: coordinatorRequestKeys.list(filters),
    queryFn: () => apiClient.get<PaginatedResponse<CoordinatorRequestSummary>>(query ? `${PATH}?${query}` : PATH),
    placeholderData: keepPreviousData,
    retry: false,
  });
}

/**
 * A count from the server: the `total` of a one-row page with this filter.
 * Statistics are never derived from a single page of rows.
 */
export function useCoordinatorRequestCount(status?: ChangeRequestStatus) {
  const query = coordinatorRequestQuery({ status, perPage: 1 });
  return useQuery({
    queryKey: [...coordinatorRequestKeys.all, "count", status ?? "ALL"],
    queryFn: async () => (await apiClient.get<PaginatedResponse<CoordinatorRequestSummary>>(`${PATH}?${query}`)).meta.total,
    staleTime: 60 * 1000,
    retry: false,
  });
}

export function useCoordinatorRequestQuery(id: string) {
  return useQuery({
    queryKey: coordinatorRequestKeys.detail(id),
    queryFn: async () => (await apiClient.get<{ data: CoordinatorRequest }>(`${PATH}/${encodeURIComponent(id)}`)).data,
    enabled: id.length > 0,
    retry: false,
  });
}

export function useCoordinatorRequestTypesQuery() {
  return useQuery({
    queryKey: coordinatorRequestKeys.types,
    queryFn: () => apiClient.get<CoordinatorRequestTypes>(`${PATH}/types`),
    staleTime: 60 * 1000,
    retry: false,
  });
}

/** The PERSON_ADD relationship options (active, never HEAD), from the registry. */
export function useCoordinatorRelationshipTypesQuery(enabled = true) {
  return useQuery({
    queryKey: coordinatorRequestKeys.relationshipTypes,
    queryFn: async () => (await apiClient.get<{ data: FamilyRelationshipOption[] }>(`${PATH}/relationship-types`)).data,
    staleTime: 5 * 60 * 1000,
    enabled,
    retry: false,
  });
}

/** The FAMILY_ADD placements in the CURRENT scope. Short-lived: a scope may change. */
export function useCoordinatorPlacementsQuery(enabled = true) {
  return useQuery({
    queryKey: coordinatorRequestKeys.placements,
    queryFn: async () => (await apiClient.get<{ data: CoordinatorPlacementOption[] }>(`${PATH}/placements`)).data,
    staleTime: 30 * 1000,
    enabled,
    retry: false,
  });
}

export type CoordinatorPersonAddSubmission = {
  familyCode: string;
  /** A UUID per proposal: the same one is re-sent on a manual retry, so the server replays instead of duplicating. */
  client_reference: string;
  reason: string | null;
  data: Record<string, unknown>;
};

export type CoordinatorFamilyAddSubmission = {
  client_reference: string;
  reason: string | null;
  placement: { clan_code: string; branch_code: string | null };
  data: Record<string, unknown>;
};

function invalidateLists(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: coordinatorRequestKeys.all });
}

/** PERSON_ADD: POST …/families/{familyCode}/requests. retry: false — a retry is the user's. */
export function useSubmitCoordinatorPersonAdd() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ familyCode, ...body }: CoordinatorPersonAddSubmission) =>
      (await apiClient.post<{ data: ChangeRequestOutcome }>(`${BASE}/families/${encodeURIComponent(familyCode)}/requests`, {
        type: "ADD_FAMILY_MEMBER",
        ...body,
      })).data,
    retry: false,
    onSuccess: () => invalidateLists(queryClient),
  });
}

/** FAMILY_ADD: POST …/requests (REGISTER_FAMILY). retry: false — a retry is the user's. */
export function useSubmitCoordinatorFamilyAdd() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (body: CoordinatorFamilyAddSubmission) =>
      (await apiClient.post<{ data: ChangeRequestOutcome }>(PATH, { type: "REGISTER_FAMILY", ...body })).data,
    retry: false,
    onSuccess: () => invalidateLists(queryClient),
  });
}

/**
 * Resubmit (a text reply — the proposal itself never changes) or cancel one
 * of the Coordinator's requests. Success OR failure re-reads the request and
 * the lists. retry: false. Same shape as the family mutation, so the shared
 * confirmation dialogs drive it.
 */
export function useCoordinatorRequestMutation(id: string) {
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
    onSettled: () => invalidateLists(queryClient),
  });
}

/** The client forms that exist for each Coordinator type. */
export const COORDINATOR_REQUEST_FORMS: Partial<Record<ChangeRequestType, string>> = {
  ADD_FAMILY_MEMBER: "/family/coordinator/requests/new/add-family-member",
  REGISTER_FAMILY: "/family/coordinator/requests/new/register-family",
};

export function coordinatorRequestFormFor(type: ChangeRequestType): string | null {
  return COORDINATOR_REQUEST_FORMS[type] ?? null;
}

/** Whether a new request can be started now — only from a successful server answer. */
export function canStartCoordinatorRequest(types: CoordinatorRequestTypes | undefined): boolean {
  if (!types?.meta.submission_enabled) return false;
  return types.data.some(({ type }) => coordinatorRequestFormFor(type) !== null);
}

/** A safe Arabic message for a failed read or action — never a raw error. */
export function coordinatorRequestErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return "تعذّر الاتصال. تحقق من الإنترنت وحاول مرة أخرى.";
  switch (error.status) {
    case 401:
      return "انتهت الجلسة. سجّل الدخول مرة أخرى.";
    case 403:
      return "لا تملك صلاحية تنفيذ هذا الإجراء.";
    case 404:
      return "هذا الطلب غير متاح ضمن نطاقك.";
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

/**
 * A safe Arabic message for a refused submission — never a raw error, and
 * never anything that says whether a National ID is known to the registry.
 */
export function coordinatorSubmitErrorMessage(error: unknown): string {
  switch (changeRequestErrorCode(error)) {
    case "CHANGE_REQUEST_ALREADY_OPEN":
      return "يوجد طلب مفتوح لنفس رقم الهوية. تابعه من «طلبات التنسيق».";
    case "CHANGE_REQUEST_SUBMISSION_DISABLED":
      return "تقديم طلبات التنسيق غير متاح حاليًا.";
    case "CHANGE_REQUEST_TYPE_UNAVAILABLE":
      return "هذا النوع من الطلبات غير متاح حاليًا.";
    case "CHANGE_REQUEST_IDEMPOTENCY_CONFLICT":
      return "تعذّر إرسال الطلب. أعد الإرسال.";
    case "CHANGE_REQUEST_NOT_FOUND":
      return "الأسرة غير متاحة ضمن نطاقك.";
  }
  if (!(error instanceof ApiError)) return "تعذّر الاتصال. تحقق من الإنترنت ثم أعد الإرسال؛ لن يُكرَّر طلبك.";
  switch (error.status) {
    case 401:
      return "انتهت الجلسة. سجّل الدخول مرة أخرى.";
    case 403:
      return "لا تملك صلاحية تقديم هذا الطلب.";
    case 404:
      return "الأسرة غير متاحة ضمن نطاقك.";
    case 422:
      return "تحقق من البيانات المدخلة.";
    case 429:
      return "محاولات كثيرة خلال وقت قصير. حاول بعد قليل.";
    case 503:
      return "تقديم طلبات التنسيق غير متاح حاليًا.";
    default:
      return "حدث خطأ غير متوقع. أعد المحاولة لاحقًا؛ لن يُكرَّر طلبك.";
  }
}
