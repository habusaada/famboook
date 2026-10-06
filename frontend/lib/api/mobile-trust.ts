"use client";

import { type QueryClient, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type { ResourceResponse } from "@/lib/types/api/family";
import type {
  GrantMobileTrustPayload,
  PersonMobileTrust,
  RevokeMobileTrustPayload,
} from "@/lib/types/api/mobile-trust";

// Staff mobile trust (docs/06 §22b, FU-15). The key sits under
// ["people", personCode], so every invalidation of the Person — e.g. after
// its mobile was edited — also refetches the trust state.
export const mobileTrustKey = (personCode: string) => ["people", personCode, "mobile-trust"] as const;

function path(personCode: string, suffix = "") {
  return `/api/v1/people/${encodeURIComponent(personCode)}/mobile-trust${suffix}`;
}

/** State and history; only requested by holders of person-mobile-trust.view. */
export function usePersonMobileTrust(personCode: string, enabled: boolean) {
  return useQuery({
    queryKey: mobileTrustKey(personCode),
    queryFn: () => apiClient.get<ResourceResponse<PersonMobileTrust>>(path(personCode)),
    enabled: enabled && personCode.length > 0,
    retry: false,
  });
}

/**
 * Every response carries the authoritative state: it replaces the cached one
 * and the query is refetched.
 */
function replaceState(queryClient: QueryClient, personCode: string) {
  return (response: ResourceResponse<PersonMobileTrust>) => {
    queryClient.setQueryData(mobileTrustKey(personCode), response);
    void queryClient.invalidateQueries({ queryKey: mobileTrustKey(personCode) });
  };
}

/**
 * After a refusal (404 / 409 / 422) another operator may have changed the
 * record: the dialog refetches the authoritative state when it closes. Not
 * at once — the refreshed state could remove the action, and with it the
 * open dialog and its message.
 */
export function refreshMobileTrustAfterRefusal(queryClient: QueryClient, personCode: string, error: unknown) {
  if (error instanceof ApiError && [404, 409, 422].includes(error.status)) {
    void queryClient.invalidateQueries({ queryKey: mobileTrustKey(personCode) });
  }
}

/** Trusts the Person's CURRENT registered mobile; no number is ever sent. */
export function useGrantMobileTrust(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: GrantMobileTrustPayload) =>
      apiClient.post<ResourceResponse<PersonMobileTrust>>(path(personCode), {
        verification_method: payload.verification_method,
      }),
    onSuccess: replaceState(queryClient, personCode),
  });
}

/** TRUSTED → REVOKED with a reason code; the row stays as history. */
export function useRevokeMobileTrust(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: RevokeMobileTrustPayload) =>
      apiClient.post<ResourceResponse<PersonMobileTrust>>(path(personCode, "/revoke"), { reason: payload.reason }),
    onSuccess: replaceState(queryClient, personCode),
  });
}
