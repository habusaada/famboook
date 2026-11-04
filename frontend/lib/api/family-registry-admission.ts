"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
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

export type RegistryChildrenPage = {
  children: RegistryChild[];
  meta: { page: number; per_page: number; total: number };
};

/** POST …/admissions: exactly the four fields of FamilyRegistryAdmissionRequest. */
export type RegistryAdmissionSubmission = {
  ref: string;
  /** A lowercase canonical UUID: one per admission attempt (the idempotency key). */
  client_reference: string;
  declaration_version: string;
  declaration_accepted: true;
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
 * Whether to SHOW the entry point on «أفراد الأسرة» (ADD-WP-08-C decision):
 * hidden when the flag is OFF, PILOT for another Family, the permission is
 * missing, or the answer is unknown. No authenticated contract states this
 * yet, and the client must not guess it: not from a NEXT_PUBLIC flag, and
 * not from a discovery read (audited and counted). So it is hidden until
 * /api/v1/family/me carries a visibility-only field for it (proposed:
 * `registry_admission: boolean`, the gate's own flag and permission check,
 * reading and recording nothing), like `notifications` and `civil_lookup`.
 * The route itself stays safe on direct navigation: a closed gate is 404.
 */
export function registryChildrenEntryVisible(): boolean {
  return false;
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
