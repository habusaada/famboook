"use client";

import { type QueryClient, keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";
import type { PaginatedResponse, ResourceResponse } from "@/lib/types/api/family";
import type {
  ConfirmPersonAlivePayload,
  CorrectNationalIdPayload,
  NationalIdMatch,
  PersonDetail,
  PersonSummary,
  RecordPersonDeathPayload,
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

/**
 * Staff confirmation that a Person whose life status is UNKNOWN is alive
 * (person.record-death). Only the verification method is sent; the API
 * decides the outcome (UNKNOWN → ALIVE or a 409).
 */
export function useConfirmPersonAlive(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: ConfirmPersonAlivePayload) =>
      apiClient.post<ResourceResponse<PersonDetail>>(
        `/api/v1/people/${encodeURIComponent(personCode)}/confirm-alive`,
        payload
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["people", personCode] });
      // Life status also appears in family views and the family timeline.
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}

/** Refetches everything that shows a Person's life status. */
export function refreshPersonLifeStatus(queryClient: QueryClient, personCode: string) {
  queryClient.invalidateQueries({ queryKey: ["people", personCode] });
  // Life status appears in family views, the family timeline and the registry.
  queryClient.invalidateQueries({ queryKey: ["families"] });
  queryClient.invalidateQueries({ queryKey: ["people", "registry"] });
}

/**
 * Staff recording of an existing Person's death (person.record-death).
 * Irreversible: there is no path back to ALIVE. After a 409 (already
 * recorded deceased) the dialog refreshes the person when it closes.
 */
export function useRecordPersonDeath(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: RecordPersonDeathPayload) =>
      apiClient.post<ResourceResponse<PersonDetail>>(
        `/api/v1/people/${encodeURIComponent(personCode)}/record-death`,
        payload
      ),
    onSuccess: () => refreshPersonLifeStatus(queryClient, personCode),
  });
}

/**
 * Administrative National ID correction (person.national-id.update). The
 * response carries only the masked value.
 */
export function useCorrectNationalId(personCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CorrectNationalIdPayload) =>
      apiClient.put<ResourceResponse<PersonDetail>>(
        `/api/v1/people/${encodeURIComponent(personCode)}/national-id`,
        payload
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["people", personCode] });
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
