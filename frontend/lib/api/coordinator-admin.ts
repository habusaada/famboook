"use client";

import { type QueryClient, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type { ResourceResponse } from "@/lib/types/api/family";
import type {
  AssignCoordinatorScopePayload,
  CoordinatorAssignment,
  CoordinatorRevokeReason,
  PersonCoordinatorState,
} from "@/lib/types/api/coordinator-admin";

// Staff coordinator administration (CO-01B) on the EXISTING API
// (PersonCoordinatorController, docs/06 §22b). Routes: Staff-side only,
// coordinator-scope.view to read, coordinator-scope.manage to change; the
// Domain Actions re-check both and the server validates every scope target
// by its codes. Nothing here decides authorization.

export const coordinatorStateKey = (personCode: string) => ["people", personCode, "coordinator"] as const;

function path(personCode: string, suffix = "") {
  return `/api/v1/people/${encodeURIComponent(personCode)}/coordinator${suffix}`;
}

export function usePersonCoordinator(personCode: string | null) {
  return useQuery({
    queryKey: coordinatorStateKey(personCode ?? ""),
    queryFn: () => apiClient.get<ResourceResponse<PersonCoordinatorState>>(path(personCode ?? "")),
    enabled: Boolean(personCode),
    retry: false,
  });
}

/** Every state response is authoritative: it replaces the cached one. */
function replaceState(queryClient: QueryClient, personCode: string) {
  return (response: ResourceResponse<PersonCoordinatorState>) => {
    queryClient.setQueryData(coordinatorStateKey(personCode), response);
  };
}

/** After a refusal another administrator may have changed the record: re-read it. */
export function refreshCoordinatorAfterRefusal(queryClient: QueryClient, personCode: string, error: unknown) {
  if (error instanceof ApiError && [404, 409, 422].includes(error.status)) {
    void queryClient.invalidateQueries({ queryKey: coordinatorStateKey(personCode) });
  }
}

/** Grants the existing COORDINATOR role to the Person's Family Portal account. */
export function useGrantCoordinator(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiClient.post<ResourceResponse<PersonCoordinatorState>>(path(personCode), {}),
    onSuccess: replaceState(queryClient, personCode),
  });
}

/** Revokes the role; the server ends every active scope (ROLE_REMOVED). */
export function useRevokeCoordinator(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (reason: CoordinatorRevokeReason) =>
      apiClient.post<ResourceResponse<PersonCoordinatorState>>(path(personCode, "/revoke"), { reason }),
    onSuccess: replaceState(queryClient, personCode),
  });
}

/** Adds one scope; several branches are several assignments. */
export function useAssignCoordinatorScope(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: AssignCoordinatorScopePayload) =>
      apiClient.post<ResourceResponse<PersonCoordinatorState>>(path(personCode, "/scopes"), payload),
    onSuccess: replaceState(queryClient, personCode),
  });
}

/** Revokes one scope assignment (by its public uuid), then re-reads the state. */
export function useRevokeCoordinatorScope(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: CoordinatorRevokeReason }) =>
      apiClient.post<ResourceResponse<CoordinatorAssignment>>(`/api/v1/coordinator-scopes/${encodeURIComponent(id)}/revoke`, { reason }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: coordinatorStateKey(personCode) });
    },
  });
}

/** The stable error code of a refusal (CoordinatorException), if any. */
export function coordinatorErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError) || !error.payload || typeof error.payload !== "object") return null;
  const code = (error.payload as { code?: unknown }).code;
  return typeof code === "string" ? code : null;
}

/** A safe Arabic message for a failed call — never a raw error. */
export function coordinatorErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return "تعذّر الاتصال بالخادم. حاول مرة أخرى.";
  const known: Record<string, string> = {
    NO_FAMILY_ACCOUNT: "لا يوجد حساب بوابة أسرة لهذا الشخص. يجب أن يفعّل حسابه كرب أسرة أولًا.",
    NOT_ELIGIBLE: "لا يمكن تعيين منسق: صاحب الحساب ليس رب أسرة مؤهلًا لاستخدام بوابة الأسرة.",
    ALREADY_COORDINATOR: "هذا الحساب يحمل دور المنسق مسبقًا.",
    NOT_COORDINATOR: "هذا الحساب لا يحمل دور المنسق.",
    TARGET_INACTIVE: "لا يمكن التعيين على عشيرة أو مجموعة أو فرع غير نشط.",
    SCOPE_EXISTS: "هذا النطاق معيّن لهذا المنسق مسبقًا.",
    ALREADY_REVOKED: "هذا التعيين ملغى مسبقًا.",
  };
  const code = coordinatorErrorCode(error);
  if (code && known[code]) return known[code];
  switch (error.status) {
    case 401:
      return "انتهت الجلسة. سجّل الدخول مرة أخرى.";
    case 403:
      return "لا تملك صلاحية إدارة المنسقين.";
    case 404:
      return "لم يُعثر على الشخص أو التعيين. حدّث الصفحة.";
    case 409:
      return "تغيّرت الحالة. حُدّثت البيانات المعروضة.";
    case 422:
      return Object.values(error.validationErrors ?? {})[0]?.[0] ?? "تحقق من البيانات المدخلة.";
    case 429:
      return "محاولات كثيرة خلال وقت قصير. حاول بعد قليل.";
    default:
      return "حدث خطأ غير متوقع. حاول مرة أخرى لاحقًا.";
  }
}
