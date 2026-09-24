"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { familyActivityKey } from "@/lib/api/activity";
import { apiClient } from "@/lib/api/client";
import type { ResourceResponse } from "@/lib/types/api/family";
import type {
  CloseHealthRecordPayload,
  CreateHealthRecordPayload,
  FamilyHealthResponse,
  HealthRecord,
  UpdateHealthRecordPayload,
} from "@/lib/types/api/health";

const healthKey = (familyCode: string) => ["families", familyCode, "health-records"];

export function useFamilyHealth(familyCode: string) {
  return useQuery({
    queryKey: healthKey(familyCode),
    queryFn: () =>
      apiClient.get<FamilyHealthResponse>(
        `/api/v1/families/${encodeURIComponent(familyCode)}/health-records`
      ),
    enabled: familyCode.length > 0,
    retry: false,
  });
}

// Every write refreshes the family's health records, derived summary and
// activity timeline.
function useHealthMutation<TPayload>(
  familyCode: string,
  request: (payload: TPayload) => Promise<ResourceResponse<HealthRecord>>
) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: request,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: healthKey(familyCode) });
      queryClient.invalidateQueries({ queryKey: familyActivityKey(familyCode) });
    },
  });
}

export function useCreateHealthRecord(familyCode: string) {
  return useHealthMutation(familyCode, (payload: CreateHealthRecordPayload) =>
    apiClient.post<ResourceResponse<HealthRecord>>(
      `/api/v1/families/${encodeURIComponent(familyCode)}/health-records`,
      payload
    )
  );
}

export function useUpdateHealthRecord(familyCode: string, recordId: string) {
  return useHealthMutation(familyCode, (payload: UpdateHealthRecordPayload) =>
    apiClient.patch<ResourceResponse<HealthRecord>>(
      `/api/v1/health-records/${encodeURIComponent(recordId)}`,
      payload
    )
  );
}

export function useCloseHealthRecord(familyCode: string, recordId: string) {
  return useHealthMutation(familyCode, (payload: CloseHealthRecordPayload) =>
    apiClient.post<ResourceResponse<HealthRecord>>(
      `/api/v1/health-records/${encodeURIComponent(recordId)}/close`,
      payload
    )
  );
}
