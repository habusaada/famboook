"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type { PaginatedResponse, ResourceResponse } from "@/lib/types/api/family";
import type {
  NationalIdMatch,
  PersonDetail,
  PersonSummary,
  UpdatePersonPayload,
} from "@/lib/types/api/person";

export function usePerson(personCode: string) {
  return useQuery({
    queryKey: ["people", personCode],
    queryFn: () =>
      apiClient.get<ResourceResponse<PersonDetail>>(
        `/api/v1/people/${encodeURIComponent(personCode)}`
      ),
    enabled: personCode.length > 0,
    retry: false,
  });
}

export function useUpdatePerson(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: UpdatePersonPayload) =>
      apiClient.patch<ResourceResponse<PersonDetail>>(
        `/api/v1/people/${encodeURIComponent(personCode)}`,
        payload
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["people", personCode] });
      // Person data (e.g. the household head's name) also appears in
      // family views; ["families"] prefix-matches every family query.
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}

function query(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") search.set(key, String(value));
  }
  return search.toString();
}

/** People registry: server-side search (code / name) and pagination. */
export function usePeople(params: { search?: string; page?: number }) {
  return useQuery({
    queryKey: ["people", "registry", params],
    queryFn: () => apiClient.get<PaginatedResponse<PersonSummary>>(`/api/v1/people?${query(params)}`),
    placeholderData: keepPreviousData,
  });
}

/**
 * Exact National ID duplicate pre-check (AUTH-ADR-058). POST, so the value
 * never appears in a URL; the response never contains a National ID.
 */
export async function checkNationalId(nationalId: string): Promise<NationalIdMatch[]> {
  const response = await apiClient.post<{ data: { exists: boolean; matches: NationalIdMatch[] } }>(
    "/api/v1/people/national-id-check",
    { national_id: nationalId }
  );
  return response.data.matches;
}

/** The existing record(s) of a refused creation (422 with `duplicate`), if any. */
export function duplicateMatches(error: unknown): NationalIdMatch[] | null {
  if (!(error instanceof ApiError) || error.status !== 422) return null;
  const payload = error.payload as { duplicate?: { matches?: NationalIdMatch[] } } | null;
  return payload?.duplicate?.matches ?? null;
}
