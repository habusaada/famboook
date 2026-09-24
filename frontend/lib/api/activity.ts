"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type { FamilyActivity } from "@/lib/types/api/activity";
import type { PaginatedResponse } from "@/lib/types/api/family";

// Nested under ["families", familyCode], so every family/member/residence
// mutation that invalidates that prefix also refreshes the timeline.
export const familyActivityKey = (familyCode: string) => [
  "families",
  familyCode,
  "activities",
];

export function useFamilyActivities(familyCode: string) {
  return useInfiniteQuery({
    queryKey: familyActivityKey(familyCode),
    queryFn: ({ pageParam }) =>
      apiClient.get<PaginatedResponse<FamilyActivity>>(
        `/api/v1/families/${encodeURIComponent(familyCode)}/activities?page=${pageParam}`
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
