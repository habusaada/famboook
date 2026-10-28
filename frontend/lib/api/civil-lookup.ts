"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type {
  CivilLookupList,
  CivilLookupMode,
  CivilLookupPolicyChange,
  CivilLookupRecordResponse,
  CivilLookupReverseRelation,
  CivilLookupStatus,
  CivilLookupUsage,
  CivilLookupUserAccessRow,
} from "@/lib/types/api/civil-lookup";

// FB-CIVIL-02A — Civil Registry Lookup (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md).
// Every lookup is a POST mutation: criteria and references travel in the
// body, never in a URL. Results are never cached (gcTime 0) and never stored
// in the browser; the page keeps them in component state only, and sign-out
// clears the client (§L-7). The API authorizes and counts every call.

const BASE = "/api/v1/civil-registry/lookup";

export const civilLookupKeys = {
  all: ["civil-lookup"] as const,
  status: () => [...civilLookupKeys.all, "status"] as const,
  usage: () => [...civilLookupKeys.all, "usage"] as const,
  changes: (page: number) => [...civilLookupKeys.all, "changes", page] as const,
  userAccess: () => [...civilLookupKeys.all, "user-access"] as const,
};

export function civilLookupErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError) || !error.payload || typeof error.payload !== "object") return null;
  const code = (error.payload as { code?: unknown }).code;
  return typeof code === "string" ? code : null;
}

const ERROR_MESSAGES: Record<string, string> = {
  LOOKUP_DISABLED: "خدمة الاستعلام عن السجل المدني غير مفعّلة.",
  LOOKUP_NOT_CONFIGURED: "لم تُضبط خدمة الاستعلام عن السجل المدني على هذا الخادم بعد.",
  LOOKUP_UNAVAILABLE: "خدمة الاستعلام غير متاحة مؤقتًا. لم يُحتسب الاستعلام من رصيدك.",
  NO_PUBLISHED_RELEASE: "لا يوجد إصدار منشور من السجل المدني حاليًا.",
  LOOKUP_NOT_ALLOWED: "لا تملك صلاحية استخدام الاستعلام عن السجل المدني، أو أن الخدمة غير مفعّلة لدورك.",
  QUOTA_EXHAUSTED: "استنفدت رصيدك اليومي من الاستعلامات. يتجدد الرصيد عند منتصف الليل بتوقيت غزة.",
  REFERENCE_INVALID: "انتهت صلاحية نتيجة البحث. أعد البحث للمتابعة.",
  RELEASE_CHANGED: "تغيّر الإصدار المنشور من السجل المدني. أعد البحث للمتابعة.",
  LINKS_DISABLED: "عرض هذه العلاقة غير مفعّل حاليًا.",
  RATE_LIMITED: "طلبات كثيرة خلال وقت قصير. حاول مرة أخرى بعد قليل.",
};

/** Fixed Arabic text for a lookup error; the server's message is never shown. */
export function civilLookupErrorMessage(error: unknown): string {
  const code = civilLookupErrorCode(error);
  if (code && ERROR_MESSAGES[code]) return ERROR_MESSAGES[code];
  if (error instanceof ApiError) {
    if (error.status === 422) {
      const first = Object.values(error.validationErrors ?? {})[0]?.[0];
      return first ?? "تحقق من البيانات المدخلة.";
    }
    if (error.status === 403) return ERROR_MESSAGES.LOOKUP_NOT_ALLOWED;
    if (error.status === 429) return ERROR_MESSAGES.RATE_LIMITED;
  }
  return "تعذّر إتمام الاستعلام. حاول مرة أخرى.";
}

export function useCivilLookupStatus() {
  return useQuery({
    queryKey: civilLookupKeys.status(),
    queryFn: async () => (await apiClient.get<{ data: CivilLookupStatus }>(`${BASE}/status`)).data,
    retry: false,
  });
}

const SEARCH_PATHS: Record<CivilLookupMode, string> = {
  "national-id": "/search/national-id",
  name: "/search/name",
  parts: "/search/parts",
};

/** After every counted lookup the quota indicator is refreshed. */
function useRefreshStatus() {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: civilLookupKeys.status() });
}

export function useCivilLookupSearch() {
  const refresh = useRefreshStatus();
  return useMutation({
    gcTime: 0,
    mutationFn: async ({ mode, body }: { mode: CivilLookupMode; body: Record<string, string | null> }) =>
      (await apiClient.post<{ data: CivilLookupList }>(`${BASE}${SEARCH_PATHS[mode]}`, body)).data,
    onSettled: refresh,
  });
}

/** Another page of the same search: not counted. */
export function useCivilLookupPage() {
  return useMutation({
    gcTime: 0,
    mutationFn: async ({ ref, page }: { ref: string; page: number }) =>
      (await apiClient.post<{ data: CivilLookupList }>(`${BASE}/search/page`, { ref, page })).data,
  });
}

/** A record listed in a result: not counted. */
export function useCivilLookupOpenRecord() {
  return useMutation({
    gcTime: 0,
    mutationFn: async (ref: string) => (await apiClient.post<{ data: CivilLookupRecordResponse }>(`${BASE}/records/open`, { ref })).data,
  });
}

/** A linked citizen: a new, counted lookup. */
export function useCivilLookupOpenLink() {
  const refresh = useRefreshStatus();
  return useMutation({
    gcTime: 0,
    mutationFn: async (ref: string) => (await apiClient.post<{ data: CivilLookupRecordResponse }>(`${BASE}/links/open`, { ref })).data,
    onSettled: refresh,
  });
}

/** Household members or children (when enabled): a counted lookup. */
export function useCivilLookupLinks() {
  const refresh = useRefreshStatus();
  return useMutation({
    gcTime: 0,
    mutationFn: async ({ ref, relation }: { ref: string; relation: CivilLookupReverseRelation }) =>
      (await apiClient.post<{ data: CivilLookupList }>(`${BASE}/links/list`, { ref, relation })).data,
    onSettled: refresh,
  });
}

// ---------------------------------------------------------------- administration

export function useCivilLookupUsage() {
  return useQuery({
    queryKey: civilLookupKeys.usage(),
    queryFn: async () => (await apiClient.get<{ data: CivilLookupUsage }>(`${BASE}/admin/usage`)).data,
    retry: false,
  });
}

export function useUpdateCivilLookupPolicy() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ role, enabled, daily_limit }: { role: string; enabled: boolean; daily_limit: number | null }) =>
      (await apiClient.put<{ data: { role: string; enabled: boolean; daily_limit: number | null } }>(
        `${BASE}/admin/policies/${encodeURIComponent(role)}`,
        { enabled, daily_limit },
      )).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: civilLookupKeys.all }),
  });
}

export function useCivilLookupChanges(page: number, enabled: boolean) {
  return useQuery({
    queryKey: civilLookupKeys.changes(page),
    queryFn: () =>
      apiClient.get<{ data: CivilLookupPolicyChange[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        `${BASE}/admin/changes?page=${page}`,
      ),
    placeholderData: keepPreviousData,
    enabled,
    retry: false,
  });
}

export function useCivilLookupUserAccess(enabled: boolean) {
  return useQuery({
    queryKey: civilLookupKeys.userAccess(),
    queryFn: async () => (await apiClient.get<{ data: CivilLookupUserAccessRow[] }>(`${BASE}/admin/user-access`)).data,
    enabled,
    retry: false,
  });
}

export function useSetCivilLookupUserAccess() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ email, access }: { email: string; access: "REVOKED" | "DEFAULT" }) =>
      (await apiClient.put<{ data: { user_id: number; access: string } }>(`${BASE}/admin/user-access`, { email, access })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: civilLookupKeys.all }),
  });
}
