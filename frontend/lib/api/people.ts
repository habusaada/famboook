"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type { ResourceResponse } from "@/lib/types/api/family";
import type { PersonDetail, UpdatePersonPayload } from "@/lib/types/api/person";

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
    },
  });
}
