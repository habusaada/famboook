"use client";

import {
  keepPreviousData,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type {
  AssistanceFilters,
  AssistanceListResponse,
  AssistancePayload,
  AssistanceResponse,
  NominationResult,
  NomineeCandidate,
  NomineesResponse,
  TargetingCriteria,
  TargetingPreviewResponse,
} from "@/lib/types/api/assistance";

const listKey = ["assistances", "list"];
const detailKey = (id: string) => ["assistances", "detail", id];
const nomineesKey = (id: string) => ["assistances", "nominees", id];

const path = (id: string, suffix = "") => `/api/v1/assistances/${encodeURIComponent(id)}${suffix}`;

export function useAssistances(filters: AssistanceFilters) {
  return useInfiniteQuery({
    queryKey: [...listKey, filters],
    queryFn: ({ pageParam }) => {
      const params = new URLSearchParams({ page: String(pageParam) });
      for (const [key, value] of Object.entries(filters)) if (value) params.set(key, value);
      return apiClient.get<AssistanceListResponse>(`/api/v1/assistances?${params}`);
    },
    initialPageParam: 1,
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
    retry: false,
  });
}

export function useAssistance(id: string) {
  return useQuery({
    queryKey: detailKey(id),
    queryFn: () => apiClient.get<AssistanceResponse>(path(id)),
    enabled: id.length > 0,
    retry: false,
  });
}

function useAssistanceWrite<TPayload>(request: (payload: TPayload) => Promise<AssistanceResponse>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: (response) => {
      queryClient.setQueryData(detailKey(response.data.id), response);
      queryClient.invalidateQueries({ queryKey: listKey });
    },
  });
}

export function useCreateAssistance() {
  return useAssistanceWrite((payload: AssistancePayload) =>
    apiClient.post<AssistanceResponse>("/api/v1/assistances", payload)
  );
}

export function useUpdateAssistance(id: string) {
  return useAssistanceWrite((payload: AssistancePayload) =>
    apiClient.patch<AssistanceResponse>(path(id), payload)
  );
}

export function useOpenAssistance(id: string) {
  return useAssistanceWrite(() => apiClient.post<AssistanceResponse>(path(id, "/open"), {}));
}

/**
 * Explicitly requested preview (never on every field change). Read-only on
 * the server: nothing is persisted.
 */
export function useTargetingPreview(id: string) {
  return useMutation({
    mutationFn: ({ criteria, page }: { criteria: TargetingCriteria; page: number }) =>
      apiClient.post<TargetingPreviewResponse>(path(id, "/targeting-preview"), { criteria, page }),
  });
}

export function useNominees(id: string, includeRemoved: boolean, page: number) {
  return useQuery({
    queryKey: [...nomineesKey(id), { includeRemoved, page }],
    queryFn: () =>
      apiClient.get<NomineesResponse>(
        path(id, `/nominees?page=${page}${includeRemoved ? "&include_removed=1" : ""}`)
      ),
    placeholderData: keepPreviousData,
    retry: false,
  });
}

export function useNomineeCandidates(id: string, search: string) {
  const term = search.trim();
  return useQuery({
    queryKey: ["assistances", "candidates", id, term],
    queryFn: () =>
      apiClient.get<{ data: NomineeCandidate[] }>(
        path(id, `/nominee-candidates?search=${encodeURIComponent(term)}`)
      ),
    enabled: term.length >= 2,
    retry: false,
  });
}

// Every nomination change refreshes the nominee list, the derived counts
// on the Assistance, and any open preview's "already nominated" flags.
function useNomination<TPayload, TResult>(id: string, request: (payload: TPayload) => Promise<TResult>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: nomineesKey(id) });
      queryClient.invalidateQueries({ queryKey: detailKey(id) });
      queryClient.invalidateQueries({ queryKey: listKey });
      queryClient.invalidateQueries({ queryKey: ["assistances", "candidates", id] });
      // Nominations appear on family timelines.
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}

export function useNominateManually(id: string) {
  return useNomination(id, (payload: { family_code: string; person_code: string | null }) =>
    apiClient.post<unknown>(path(id, "/nominees/manual"), payload)
  );
}

export function useNominateFromNeeds(id: string) {
  return useNomination(id, (needIds: string[]) =>
    apiClient.post<NominationResult>(path(id, "/nominees/from-needs"), { need_ids: needIds })
  );
}

export function useNominateFromTargeting(id: string) {
  return useNomination(id, (payload: { family_codes: string[]; criteria: TargetingCriteria }) =>
    apiClient.post<NominationResult>(path(id, "/nominees/from-targeting"), payload)
  );
}

export function useRemoveNominee(id: string) {
  return useNomination(id, (nomineeId: string) =>
    apiClient.post<unknown>(path(id, `/nominees/${encodeURIComponent(nomineeId)}/remove`), {})
  );
}
