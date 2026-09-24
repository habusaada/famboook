"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/lib/api/client";
import type { DisabilityType, RelationshipType } from "@/lib/types/api/reference";

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

export function useDisabilityTypes(enabled = true) {
  return useQuery({
    queryKey: ["reference", "disability-types"],
    queryFn: () =>
      apiClient.get<{ data: DisabilityType[] }>("/api/v1/reference/disability-types"),
    staleTime: 5 * 60 * 1000,
    enabled,
  });
}
