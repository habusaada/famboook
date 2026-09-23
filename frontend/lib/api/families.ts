"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type {
  FamilyDetail,
  FamilySummary,
  PaginatedResponse,
  RegisterFamilyPayload,
  ResourceResponse,
} from "@/lib/types/api/family";

export function useFamilies() {
  return useQuery({
    queryKey: ["families"],
    queryFn: () => apiClient.get<PaginatedResponse<FamilySummary>>("/api/v1/families"),
  });
}

export function useFamily(familyCode: string) {
  return useQuery({
    queryKey: ["families", familyCode],
    queryFn: () =>
      apiClient.get<ResourceResponse<FamilyDetail>>(
        `/api/v1/families/${encodeURIComponent(familyCode)}`
      ),
    enabled: familyCode.length > 0,
    retry: false,
  });
}

export function useRegisterFamily() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: RegisterFamilyPayload) =>
      apiClient.post<ResourceResponse<FamilyDetail>>("/api/v1/families", payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}
