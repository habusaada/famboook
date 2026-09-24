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
  AssessmentDraftPayload,
  AssessmentResponse,
  AssessmentSummary,
} from "@/lib/types/api/assessment";

type AssessmentListResponse = PaginatedResponse<AssessmentSummary> & {
  abilities: { create: boolean };
};

export const familyAssessmentsKey = (familyCode: string) => [
  "families",
  familyCode,
  "assessments",
];

const assessmentKey = (id: string) => ["assessments", id];

export function useFamilyAssessments(familyCode: string) {
  return useInfiniteQuery({
    queryKey: familyAssessmentsKey(familyCode),
    queryFn: ({ pageParam }) =>
      apiClient.get<AssessmentListResponse>(
        `/api/v1/families/${encodeURIComponent(familyCode)}/assessments?page=${pageParam}`
      ),
    initialPageParam: 1,
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page
        ? last.meta.current_page + 1
        : undefined,
    enabled: familyCode.length > 0,
    retry: false,
  });
}

export function useAssessment(id: string | undefined) {
  return useQuery({
    queryKey: assessmentKey(id ?? ""),
    queryFn: () =>
      apiClient.get<AssessmentResponse>(`/api/v1/assessments/${encodeURIComponent(id!)}`),
    enabled: !!id,
    retry: false,
  });
}

export interface SaveAssessmentVariables {
  // Undefined → a new draft is created first.
  id?: string;
  complete: boolean;
  payload: AssessmentDraftPayload;
  // Called as soon as a new draft exists, so a retry after a failed
  // completion updates that draft instead of creating another one.
  onCreated?: (id: string) => void;
}

/**
 * Save as draft, or save and complete. For an existing draft, "save and
 * complete" is one atomic request (POST /complete with the payload). A
 * brand-new assessment is created as a draft first, then completed; if
 * completion fails the draft is kept.
 */
export function useSaveAssessment(
  familyCode: string,
  onSaved?: (response: AssessmentResponse) => void
) {
  const queryClient = useQueryClient();

  const refresh = (id?: string) => {
    queryClient.invalidateQueries({ queryKey: familyAssessmentsKey(familyCode) });
    queryClient.invalidateQueries({ queryKey: familyActivityKey(familyCode) });
    if (id) queryClient.invalidateQueries({ queryKey: assessmentKey(id) });
  };

  return useMutation({
    mutationFn: async ({ id, complete, payload, onCreated }: SaveAssessmentVariables) => {
      if (!id) {
        const created = await apiClient.post<AssessmentResponse>(
          `/api/v1/families/${encodeURIComponent(familyCode)}/assessments`,
          payload
        );
        onCreated?.(created.data.id);
        refresh();
        if (!complete) return created;

        return apiClient.post<AssessmentResponse>(
          `/api/v1/assessments/${encodeURIComponent(created.data.id)}/complete`,
          {}
        );
      }

      return complete
        ? apiClient.post<AssessmentResponse>(
            `/api/v1/assessments/${encodeURIComponent(id)}/complete`,
            payload
          )
        : apiClient.patch<AssessmentResponse>(
            `/api/v1/assessments/${encodeURIComponent(id)}`,
            payload
          );
    },
    onSuccess: (response) => {
      queryClient.setQueryData(assessmentKey(response.data.id), response);
      refresh(response.data.id);
      onSaved?.(response);
    },
  });
}

/** Completes a draft exactly as stored (no content changes). */
export function useCompleteAssessment(familyCode: string, id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () =>
      apiClient.post<AssessmentResponse>(
        `/api/v1/assessments/${encodeURIComponent(id)}/complete`,
        {}
      ),
    onSuccess: (response) => {
      queryClient.setQueryData(assessmentKey(id), response);
      queryClient.invalidateQueries({ queryKey: familyAssessmentsKey(familyCode) });
      queryClient.invalidateQueries({ queryKey: familyActivityKey(familyCode) });
    },
  });
}
