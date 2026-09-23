"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type { RelationshipType } from "@/lib/types/api/reference";

export function useRelationshipTypes() {
  return useQuery({
    queryKey: ["reference", "relationship-types"],
    queryFn: () =>
      apiClient.get<{ data: RelationshipType[] }>(
        "/api/v1/reference/relationship-types"
      ),
    staleTime: 5 * 60 * 1000,
  });
}
