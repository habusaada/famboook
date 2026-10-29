"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type {
  CivilLookupAccessValue,
  CivilLookupAccountState,
  CivilLookupList,
  CivilLookupMode,
  CivilLookupPolicyChange,
  CivilLookupRecordResponse,
  CivilLookupReverseRelation,
  CivilLookupSide,
  CivilLookupSpace,
  CivilLookupStaffAccount,
  CivilLookupStatus,
  CivilLookupUsage,
  CivilLookupUserAccessList,
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
  changes: (page: number, filter: CivilLookupChangesFilter = {}) => [...civilLookupKeys.all, "changes", page, filter] as const,
  userAccess: () => [...civilLookupKeys.all, "user-access"] as const,
  account: (target: CivilLookupAccessTarget | null) => [...civilLookupKeys.all, "account", target] as const,
  staffAccounts: (q: string, page: number) => [...civilLookupKeys.all, "staff-accounts", q, page] as const,
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
export function useRefreshStatus() {
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

/**
 * A record listed in a result: not counted — except FB-CIVIL-02F: at STANDARD the
 * first full-ID disclosure of a record each day is, so the quota is refreshed.
 */
export function useCivilLookupOpenRecord(space: CivilLookupSpace = "staff") {
  const refresh = useRefreshStatus();
  return useMutation({
    gcTime: 0,
    mutationFn: async (ref: string) =>
      (await apiClient.post<{ data: CivilLookupRecordResponse }>(`${SPACE_BASES[space]}/records/open`, { ref })).data,
    onSettled: refresh,
  });
}

/**
 * FB-CIVIL-02G — ONE complete National ID, named by its opaque reference (a
 * result card, a list entry, an opened record or a linked person). A plain
 * request, never a query or a mutation: the value never enters a cache. The
 * caller keeps it in transient component state only, as SensitiveValue does
 * on «بياناتي الشخصية». The first reveal of a record each day is counted.
 */
export async function revealCivilLookupId(space: CivilLookupSpace, ref: string): Promise<string> {
  const response = await apiClient.post<{ data: { national_id: string } }>(`${SPACE_BASES[space]}/identifiers/reveal`, { ref });
  return response.data.national_id;
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

/** FB-CIVIL-02D: role-policy (ROLE) or per-user (USER) changes, optionally one user's. */
export interface CivilLookupChangesFilter {
  subject?: "ROLE" | "USER";
  userId?: number;
}

export function useCivilLookupChanges(page: number, enabled: boolean, filter: CivilLookupChangesFilter = {}) {
  const params = new URLSearchParams({ page: String(page) });
  if (filter.subject) params.set("subject", filter.subject);
  if (filter.userId) params.set("user_id", String(filter.userId));
  return useQuery({
    queryKey: civilLookupKeys.changes(page, filter),
    queryFn: () =>
      apiClient.get<{ data: CivilLookupPolicyChange[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        `${BASE}/admin/changes?${params.toString()}`,
      ),
    placeholderData: keepPreviousData,
    enabled,
    retry: false,
  });
}

/** The latest individual overrides (a recent list with its limit and total — never proof that an account has none). */
export function useCivilLookupUserAccess(enabled: boolean) {
  return useQuery({
    queryKey: civilLookupKeys.userAccess(),
    queryFn: () => apiClient.get<CivilLookupUserAccessList>(`${BASE}/admin/user-access`),
    enabled,
    retry: false,
  });
}

/** The target is a Staff email or, for a family-side account (no email), its person code. */
export type CivilLookupAccessTarget = { email: string } | { person_code: string };

/** FB-CIVIL-02D: one account's current access, resolved by the server (POST: the identifier never goes in a URL). */
export function useCivilLookupAccount(target: CivilLookupAccessTarget | null) {
  return useQuery({
    queryKey: civilLookupKeys.account(target),
    queryFn: async () => (await apiClient.post<{ data: CivilLookupAccountState }>(`${BASE}/admin/user-access/resolve`, target)).data,
    enabled: target !== null,
    retry: false,
    staleTime: 0,
  });
}

/** FB-CIVIL-02D: Staff accounts by name or email (two characters at least). */
export function useCivilLookupStaffAccounts(q: string, page: number) {
  return useQuery({
    queryKey: civilLookupKeys.staffAccounts(q, page),
    queryFn: () =>
      apiClient.get<{ data: CivilLookupStaffAccount[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }>(
        `${BASE}/admin/accounts/staff?${new URLSearchParams({ q, page: String(page) }).toString()}`,
      ),
    enabled: q.length >= 2,
    placeholderData: keepPreviousData,
    retry: false,
  });
}

/**
 * GRANTED replaces the whole set of sides, so the caller sends the full set
 * it shows. `expected` is the state the editor loaded: the server refuses
 * with 409 ACCESS_CHANGED if another administrator changed it meanwhile.
 */
export function useSetCivilLookupUserAccess() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      target,
      access,
      sides,
      expected,
    }: {
      target: CivilLookupAccessTarget;
      access: CivilLookupAccessValue;
      sides?: CivilLookupSide[];
      expected?: { access: CivilLookupAccessValue; sides: CivilLookupSide[] };
    }) =>
      (
        await apiClient.put<{ data: { user_id: number; access: CivilLookupAccessValue; sides: CivilLookupSide[] } }>(`${BASE}/admin/user-access`, {
          ...target,
          access,
          ...(access === "GRANTED" ? { sides } : {}),
          ...(expected ? { expected_access: expected.access, expected_sides: expected.sides } : {}),
        })
      ).data,
    onSettled: () => queryClient.invalidateQueries({ queryKey: civilLookupKeys.all }),
  });
}

/** Fixed Arabic text for an administration refusal; the server's validation text is Arabic already. */
export function civilLookupAdminErrorMessage(error: unknown): string {
  if (civilLookupErrorCode(error) === "ACCESS_CHANGED") {
    return "تغيّرت إعدادات هذا الحساب بواسطة مسؤول آخر منذ تحميلها. أُعيد تحميل الحالة الحالية؛ راجعها ثم أعد المحاولة.";
  }
  if (error instanceof ApiError) {
    if (error.status === 401) return "انتهت الجلسة. سجّل الدخول مجددًا.";
    if (error.status === 403) return "لا تملك صلاحية إدارة تصاريح الاستعلام المدني.";
    if (error.status === 422) return Object.values(error.validationErrors ?? {})[0]?.[0] ?? "تحقق من البيانات المدخلة.";
    if (error.status === 429) return "طلبات كثيرة خلال وقت قصير. حاول مرة أخرى بعد قليل.";
  }
  return "تعذّر إتمام العملية. حاول مرة أخرى.";
}
