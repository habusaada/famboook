"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type {
  CivilLookupList,
  CivilLookupMode,
  CivilLookupPolicyChange,
  CivilLookupRecordResponse,
  CivilLookupReverseRelation,
  CivilLookupSide,
  CivilLookupSpace,
  CivilLookupStatus,
  CivilLookupUsage,
  CivilLookupUserAccessRow,
} from "@/lib/types/api/civil-lookup";

// FB-CIVIL-02A — Civil Registry Lookup (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md).
// Every lookup is a POST mutation: criteria and references travel in the
// body, never in a URL. Results are never cached (gcTime 0) and never stored
// in the browser; the page keeps them in component state only, and sign-out
// clears the client (§L-7). The API authorizes and counts every call.
//
// FB-CIVIL-02B: the same hooks serve the Family Portal and Coordinator Space
// through their own mounts of the same API (the server fixes the context and
// binds every reference to it); the quota they show is the one shared counter.

const BASE = "/api/v1/civil-registry/lookup";

const SPACE_BASES: Record<CivilLookupSpace, string> = {
  staff: BASE,
  family: "/api/v1/family/civil-registry/lookup",
  coordinator: "/api/v1/family/coordinator/civil-registry/lookup",
};

export const civilLookupKeys = {
  all: ["civil-lookup"] as const,
  status: (space: CivilLookupSpace = "staff") => [...civilLookupKeys.all, "status", space] as const,
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

export function useCivilLookupStatus(space: CivilLookupSpace = "staff") {
  return useQuery({
    queryKey: civilLookupKeys.status(space),
    queryFn: async () => (await apiClient.get<{ data: CivilLookupStatus }>(`${SPACE_BASES[space]}/status`)).data,
    retry: false,
  });
}

const SEARCH_PATHS: Record<CivilLookupMode, string> = {
  "national-id": "/search/national-id",
  name: "/search/name",
  parts: "/search/parts",
};

/** After every counted lookup the quota indicator is refreshed (every space: the counter is shared). */
function useRefreshStatus() {
  const queryClient = useQueryClient();
  return () => queryClient.invalidateQueries({ queryKey: [...civilLookupKeys.all, "status"] });
}

export function useCivilLookupSearch(space: CivilLookupSpace = "staff") {
  const refresh = useRefreshStatus();
  return useMutation({
    gcTime: 0,
    mutationFn: async ({ mode, body }: { mode: CivilLookupMode; body: Record<string, string | null> }) =>
      (await apiClient.post<{ data: CivilLookupList }>(`${SPACE_BASES[space]}${SEARCH_PATHS[mode]}`, body)).data,
    onSettled: refresh,
  });
}

/** Another page of the same search: not counted. */
export function useCivilLookupPage(space: CivilLookupSpace = "staff") {
  return useMutation({
    gcTime: 0,
    mutationFn: async ({ ref, page }: { ref: string; page: number }) =>
      (await apiClient.post<{ data: CivilLookupList }>(`${SPACE_BASES[space]}/search/page`, { ref, page })).data,
  });
}

/** A record listed in a result: not counted. */
export function useCivilLookupOpenRecord(space: CivilLookupSpace = "staff") {
  return useMutation({
    gcTime: 0,
    mutationFn: async (ref: string) =>
      (await apiClient.post<{ data: CivilLookupRecordResponse }>(`${SPACE_BASES[space]}/records/open`, { ref })).data,
  });
}

/** A linked citizen: a new, counted lookup. */
export function useCivilLookupOpenLink(space: CivilLookupSpace = "staff") {
  const refresh = useRefreshStatus();
  return useMutation({
    gcTime: 0,
    mutationFn: async (ref: string) =>
      (await apiClient.post<{ data: CivilLookupRecordResponse }>(`${SPACE_BASES[space]}/links/open`, { ref })).data,
    onSettled: refresh,
  });
}

/** Household members or children (when enabled): a counted lookup. */
export function useCivilLookupLinks(space: CivilLookupSpace = "staff") {
  const refresh = useRefreshStatus();
  return useMutation({
    gcTime: 0,
    mutationFn: async ({ ref, relation }: { ref: string; relation: CivilLookupReverseRelation }) =>
      (await apiClient.post<{ data: CivilLookupList }>(`${SPACE_BASES[space]}/links/list`, { ref, relation })).data,
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

/** The target is a Staff email or, for a family-side account (no email), its person code. */
export type CivilLookupAccessTarget = { email: string } | { person_code: string };

export function useSetCivilLookupUserAccess() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      target,
      access,
      sides,
    }: {
      target: CivilLookupAccessTarget;
      access: "REVOKED" | "DEFAULT" | "GRANTED";
      sides?: CivilLookupSide[];
    }) =>
      (
        await apiClient.put<{ data: { user_id: number; access: string; sides: CivilLookupSide[] } }>(`${BASE}/admin/user-access`, {
          ...target,
          access,
          ...(access === "GRANTED" ? { sides } : {}),
        })
      ).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: civilLookupKeys.all }),
  });
}
