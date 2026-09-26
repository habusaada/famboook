"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type {
  AddFamilyMemberPayload,
  FamiliesListResponse,
  FamilyDetail,
  FamilyMemberDetail,
  RegisterFamilyPayload,
  ResourceResponse,
  UpdateFamilyPayload,
  UpdateFamilyResidencePayload,
} from "@/lib/types/api/family";

/** Family registry: server-side search, status filter and pagination. */
export function useFamilies(params: { search?: string; status?: string; page?: number }) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== "") search.set(key, String(value));
  }
  return useQuery({
    queryKey: ["families", "registry", params],
    queryFn: () => apiClient.get<FamiliesListResponse>(`/api/v1/families?${search.toString()}`),
    placeholderData: keepPreviousData,
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

export function useAddFamilyMember(familyCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: AddFamilyMemberPayload) =>
      apiClient.post<ResourceResponse<FamilyMemberDetail>>(
        `/api/v1/families/${encodeURIComponent(familyCode)}/members`,
        payload
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["families", familyCode] });
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}

export function useUpdateFamily(familyCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: UpdateFamilyPayload) =>
      apiClient.patch<ResourceResponse<FamilyDetail>>(
        `/api/v1/families/${encodeURIComponent(familyCode)}`,
        payload
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["families", familyCode] });
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}

export function useUpdateFamilyResidence(familyCode: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: UpdateFamilyResidencePayload) =>
      apiClient.patch<ResourceResponse<FamilyDetail>>(
        `/api/v1/families/${encodeURIComponent(familyCode)}/residence`,
        payload
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["families", familyCode] });
      queryClient.invalidateQueries({ queryKey: ["families"] });
    },
  });
}
