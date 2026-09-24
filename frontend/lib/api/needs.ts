"use client";

import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import { familyActivityKey } from "@/lib/api/activity";
import { apiClient } from "@/lib/api/client";
import type { PaginatedResponse } from "@/lib/types/api/family";
import type {
  FamilyNeedsResponse,
  Need,
  NeedFilters,
  NeedPayload,
  NeedResponse,
} from "@/lib/types/api/need";

// Family lists live under ["families", code, "needs", ...]; the global
// queue and details under ["needs", ...]. Every write refreshes both.
const familyNeedsKey = (familyCode: string) => ["families", familyCode, "needs"];
const needKey = (id: string) => ["needs", "detail", id];

function query(filters: NeedFilters, page: number): string {
  const params = new URLSearchParams({ page: String(page) });
  for (const [key, value] of Object.entries(filters)) {
    if (value) params.set(key, value);
  }
  return params.toString();
}

const nextPage = (last: PaginatedResponse<Need>) =>
  last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined;

export function useFamilyNeeds(familyCode: string, filters: NeedFilters = {}, enabled = true) {
  return useInfiniteQuery({
    queryKey: [...familyNeedsKey(familyCode), filters],
    queryFn: ({ pageParam }) =>
      apiClient.get<FamilyNeedsResponse>(
        `/api/v1/families/${encodeURIComponent(familyCode)}/needs?${query(filters, pageParam)}`
      ),
    initialPageParam: 1,
    getNextPageParam: nextPage,
    enabled: enabled && familyCode.length > 0,
    retry: false,
  });
}

/** Cross-family work queue. */
export function useNeedsQueue(filters: NeedFilters) {
  return useInfiniteQuery({
    queryKey: ["needs", "queue", filters],
    queryFn: ({ pageParam }) =>
      apiClient.get<PaginatedResponse<Need>>(`/api/v1/needs?${query(filters, pageParam)}`),
    initialPageParam: 1,
    getNextPageParam: nextPage,
    retry: false,
  });
}

export function useNeed(id: string) {
  return useQuery({
    queryKey: needKey(id),
    queryFn: () => apiClient.get<NeedResponse>(`/api/v1/needs/${encodeURIComponent(id)}`),
    enabled: id.length > 0,
    retry: false,
  });
}

function useNeedMutation<TPayload>(
  familyCode: string,
  request: (payload: TPayload) => Promise<NeedResponse>
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: (response) => {
      queryClient.setQueryData(needKey(response.data.id), response);
      queryClient.invalidateQueries({ queryKey: familyNeedsKey(familyCode) });
      queryClient.invalidateQueries({ queryKey: ["needs", "queue"] });
      queryClient.invalidateQueries({ queryKey: familyActivityKey(familyCode) });
    },
  });
}

export function useCreateNeed(familyCode: string) {
  return useNeedMutation(familyCode, (payload: NeedPayload) =>
    apiClient.post<NeedResponse>(`/api/v1/families/${encodeURIComponent(familyCode)}/needs`, payload)
  );
}

export function useUpdateNeed(familyCode: string, id: string) {
  return useNeedMutation(familyCode, (payload: NeedPayload) =>
    apiClient.patch<NeedResponse>(`/api/v1/needs/${encodeURIComponent(id)}`, payload)
  );
}

export function useFulfillNeed(familyCode: string, id: string) {
  return useNeedMutation(familyCode, () =>
    apiClient.post<NeedResponse>(`/api/v1/needs/${encodeURIComponent(id)}/fulfill`, {})
  );
}

export function useCloseNeed(familyCode: string, id: string) {
  return useNeedMutation(familyCode, (payload: { closure_reason: string }) =>
    apiClient.post<NeedResponse>(`/api/v1/needs/${encodeURIComponent(id)}/close`, payload)
  );
}
