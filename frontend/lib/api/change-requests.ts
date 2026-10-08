"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type {
  ChangeRequestAction,
  ChangeRequestDetail,
  ChangeRequestFilters,
  ChangeRequestOutcome,
  ChangeRequestSummary,
} from "@/lib/types/api/change-request";

// The Staff Change Request API (PWA-5c). The server decides every
// transition; nothing here changes a status optimistically, and no
// workflow mutation is ever retried automatically (an APPLY retry is an
// explicit Staff action).

export const changeRequestKeys = {
  all: ["change-requests"] as const,
  queue: (filters: ChangeRequestFilters) => ["change-requests", "queue", filters] as const,
  detail: (id: string) => ["change-requests", "detail", id] as const,
};

const PATH = "/api/v1/change-requests";

/** Only the allow-listed filters, and only those that are set. */
export function changeRequestQuery(filters: ChangeRequestFilters): string {
  const params = new URLSearchParams();
  for (const key of ["status", "type", "family", "request_code", "submitted_from", "submitted_to"] as const) {
    const value = filters[key];
    if (value) params.set(key, value);
  }
  if (filters.page && filters.page > 1) params.set("page", String(filters.page));
  return params.toString();
}

export function useChangeRequestQueue(filters: ChangeRequestFilters) {
  const query = changeRequestQuery(filters);

  return useQuery({
    queryKey: changeRequestKeys.queue(filters),
    queryFn: () => apiClient.get<PaginatedResponse<ChangeRequestSummary>>(query ? `${PATH}?${query}` : PATH),
    placeholderData: keepPreviousData,
    retry: false,
  });
}

export function useChangeRequest(id: string) {
  return useQuery({
    queryKey: changeRequestKeys.detail(id),
    queryFn: async () => (await apiClient.get<{ data: ChangeRequestDetail }>(`${PATH}/${encodeURIComponent(id)}`)).data,
    enabled: id.length > 0,
    retry: false,
  });
}

const ENDPOINTS: Record<ChangeRequestAction, string> = {
  start_review: "start-review",
  return: "return",
  approve: "approve",
  reject: "reject",
  apply: "apply",
};

export type ChangeRequestActionInput =
  | { action: "start_review" | "approve" | "apply" }
  | { action: "return"; body: { public_message: string; internal_note?: string } }
  | {
      action: "reject";
      body: { rejection_reason_code: string; public_message?: string; internal_note?: string };
    };

/**
 * One workflow action on one request. Success OR failure re-reads the
 * request and the queue: after a refusal another reviewer may have moved
 * it, and a failed APPLY leaves a new failure record to show. A successful
 * APPLY changed the Family's registry data: its queries are refreshed too.
 * retry: false — never re-sent automatically.
 */
export function useChangeRequestAction(id: string, familyCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: ChangeRequestActionInput) =>
      (
        await apiClient.post<{ data: ChangeRequestOutcome }>(
          `${PATH}/${encodeURIComponent(id)}/${ENDPOINTS[input.action]}`,
          "body" in input ? input.body : {}
        )
      ).data,
    retry: false,
    onSuccess: (_outcome, input) => {
      if (input.action === "apply" && familyCode) {
        void queryClient.invalidateQueries({ queryKey: ["families", familyCode] });
      }
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: changeRequestKeys.detail(id) });
      void queryClient.invalidateQueries({ queryKey: ["change-requests", "queue"] });
    },
  });
}

/** The backend's machine code of a refusal ({message, code}), if any. */
export function changeRequestErrorCode(error: unknown): string | null {
  if (error instanceof ApiError && error.payload && typeof error.payload === "object" && "code" in error.payload) {
    const code = (error.payload as { code: unknown }).code;
    return typeof code === "string" ? code : null;
  }
  return null;
}

/**
 * A safe Arabic message for a failed workflow action. Change Request
 * refusals carry fixed Arabic messages from the server (no values); an
 * unexpected APPLY failure and every other error get a generic message —
 * never a raw exception, stack trace or SQL.
 */
export function changeRequestErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return "تعذّر الاتصال بالخادم. تحقق من الاتصال وحاول مرة أخرى.";
  const code = changeRequestErrorCode(error);
  if (code === "CHANGE_REQUEST_APPLY_FAILED") {
    return "تعذّر تطبيق التعديل بسبب خطأ غير متوقع، ولم يتغير سجل الأسرة. يمكن إعادة المحاولة لاحقًا.";
  }
  if (code?.startsWith("CHANGE_REQUEST_") && error.status < 500 && error.message422) return error.message422;
  switch (error.status) {
    case 401:
      return "انتهت الجلسة. الرجاء تسجيل الدخول مجددًا.";
    case 403:
      return "لا تملك صلاحية تنفيذ هذا الإجراء.";
    case 404:
      return "هذا الطلب غير متاح.";
    case 409:
      return "تغيّرت حالة الطلب. تم تحديث الصفحة بالحالة الحالية.";
    case 422:
      return "تحقق من البيانات المدخلة.";
    case 429:
      return "طلبات كثيرة خلال وقت قصير. حاول مرة أخرى بعد قليل.";
    default:
      return "حدث خطأ غير متوقع. حاول مرة أخرى لاحقًا.";
  }
}
