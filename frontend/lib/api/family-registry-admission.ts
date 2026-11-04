"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type { FamilyUser } from "@/lib/api/family-auth";
import { changeRequestErrorCode } from "@/lib/api/change-requests";
import {
  FAMILY_HOUSEHOLD_QUERY_KEY,
  FAMILY_MEMBERS_QUERY_KEY,
  FAMILY_PROFILE_QUERY_KEY,
  useFamilyAccessFailure,
} from "@/lib/api/family-household";
import type { PaginationMeta } from "@/lib/types/api/family";

// Direct admission of the head's own civil-verified sons and daughters
// (FB-FAMILY-ADD-01 ADD-WP-08; ADR §7, §7b). The server resolves the
// household and its head from the session: nothing here sends a family,
// person, membership or National ID. Discovery results are never kept: no
// stale reuse, dropped from the cache as soon as the page is left, no
// browser storage, and the Family service worker never caches the API.

export const REGISTRY_CHILDREN_PATH = "/api/v1/family/household/registry-children";
export const REGISTRY_ADMISSIONS_PATH = "/api/v1/family/household/registry-children/admissions";
export const FAMILY_REGISTRY_CHILDREN_QUERY_KEY = ["family", "household", "registry-children"] as const;

/** The documented public statuses (ADR §7b). Anything else is shown as NOT_ELIGIBLE. */
export type RegistryChildStatus = "ELIGIBLE" | "ALREADY_MEMBER" | "REQUEST_OPEN" | "NOT_ELIGIBLE";

/**
 * One suggested child, exactly as FamilyRegistryChildResource answers it
 * (provisional until G-02): the first name, the birth year, the public
 * status and an opaque, short-lived admission reference. `ref` is null
 * whenever the server issues none. Never an identifier, never put in the DOM.
 */
export type RegistryChild = {
  display_name: string | null;
  birth_year: number | null;
  status: RegistryChildStatus;
  ref: string | null;
};

/**
 * The APPROVED declaration the server delivers with the list (RR-02; ADR §6): the
 * exact text to show and the version an admission must carry. The client never
 * holds its own wording or version.
 */
export type RegistryAdmissionDeclaration = {
  version: string;
  text: string;
  /**
   * RR-02A (D-06R, proposed): the head's SEPARATE statement that the child is alive
   * now, delivered only while the revised life policy is active; confirmed with its own
   * checkbox and sent as life_declaration_accepted. null = not required.
   */
  life_text: string | null;
};

export type RegistryChildrenPage = {
  children: RegistryChild[];
  meta: { page: number; per_page: number; total: number };
  /** null (or absent, from an older server) = no approved declaration: submission stays disabled. */
  declaration?: RegistryAdmissionDeclaration | null;
};

/** The server limit of declaration_version (RegistryAdmissionRequest::DECLARATION_VERSION_MAX). */
const DECLARATION_VERSION_MAX = 40;

/**
 * The delivered declaration when it is usable, otherwise null: a version that is a
 * non-empty string with no surrounding spaces, of at most 40 characters, and a non-empty text.
 * Anything else is treated as no declaration, never repaired.
 */
export function usableDeclaration(value: unknown): RegistryAdmissionDeclaration | null {
  if (!value || typeof value !== "object") return null;
  const { version, text, life_text } = value as { version?: unknown; text?: unknown; life_text?: unknown };
  if (typeof version !== "string" || version === "" || version !== version.trim() || version.length > DECLARATION_VERSION_MAX) return null;
  if (typeof text !== "string" || text.trim() === "") return null;
  // A life statement is either absent / null or a real text: anything else makes the declaration unusable.
  if (life_text !== undefined && life_text !== null && (typeof life_text !== "string" || life_text.trim() === "")) return null;

  return { version, text, life_text: typeof life_text === "string" ? life_text : null };
}

/** POST …/admissions: exactly the four fields of FamilyRegistryAdmissionRequest. */
export type RegistryAdmissionSubmission = {
  ref: string;
  /** A lowercase canonical UUID: one per admission attempt (the idempotency key). */
  client_reference: string;
  declaration_version: string;
  declaration_accepted: true;
  /** RR-02A: sent only when the delivered declaration has a life_text, and then only as true. */
  life_declaration_accepted?: true;
};

/** 201 {replayed: false} for an admission, 200 {replayed: true} for a replay. */
export type RegistryAdmissionOutcome = { member_ref: string; replayed: boolean };

/**
 * GET …/registry-children?page=N. Each read is audited and counted by the
 * server, so nothing re-reads it behind the user's back: no retry, no
 * refetch on focus or reconnect, and nothing kept once the page is left.
 */
export function useFamilyRegistryChildrenQuery(page: number) {
  const query = useQuery({
    queryKey: [...FAMILY_REGISTRY_CHILDREN_QUERY_KEY, page],
    queryFn: async () => (await apiClient.get<{ data: RegistryChildrenPage }>(`${REGISTRY_CHILDREN_PATH}?page=${page}`)).data,
    staleTime: 0,
    gcTime: 0,
    retry: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  });
  useFamilyAccessFailure(query.error);

  return query;
}

/**
 * POST …/admissions, never retried automatically: a retry is the user's,
 * with the same client_reference. On success the members, the household
 * summary, the profile and the suggested children are re-read.
 */
export function useAdmitRegistryChild() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (input: RegistryAdmissionSubmission) =>
      (await apiClient.post<{ data: RegistryAdmissionOutcome }>(REGISTRY_ADMISSIONS_PATH, input)).data,
    retry: false,
    onSuccess: () => {
      for (const queryKey of [FAMILY_MEMBERS_QUERY_KEY, FAMILY_HOUSEHOLD_QUERY_KEY, FAMILY_PROFILE_QUERY_KEY, FAMILY_REGISTRY_CHILDREN_QUERY_KEY]) {
        void queryClient.invalidateQueries({ queryKey, exact: queryKey !== FAMILY_REGISTRY_CHILDREN_QUERY_KEY });
      }
    },
  });
}

/**
 * Whether to SHOW the entry point on «أفراد الأسرة» (ADD-WP-08-C decision,
 * RR-01): only when /api/v1/family/me says `registry_admission: true` — the
 * server's own gate check, so OFF, PILOT for another Family, a missing
 * permission or no household context are all hidden. Missing, false or not
 * a boolean is hidden too, and a failed /me never renders the portal. Never
 * guessed: no NEXT_PUBLIC flag, no discovery read (audited and counted). The
 * route stays safe on direct navigation: a closed gate is 404.
 */
export function registryChildrenEntryVisible(user: FamilyUser | null | undefined): boolean {
  return user?.registry_admission === true;
}

/** The machine code of a refusal ({message, code}); the shape is the Change Requests' one. */
export const registryAdmissionErrorCode = changeRequestErrorCode;

/** The server's {page, per_page, total} as the shared pagination bar's meta. */
export function toPaginationMeta(meta: RegistryChildrenPage["meta"], shown: number): PaginationMeta {
  const from = shown === 0 ? null : (meta.page - 1) * meta.per_page + 1;

  return {
    current_page: meta.page,
    last_page: Math.max(1, Math.ceil(meta.total / Math.max(1, meta.per_page))),
    per_page: meta.per_page,
    total: meta.total,
    from,
    to: from === null ? null : from + shown - 1,
    path: REGISTRY_CHILDREN_PATH,
  };
}
