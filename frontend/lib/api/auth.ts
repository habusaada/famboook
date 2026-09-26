"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiError, apiClient } from "@/lib/api/client";

// Staff session (docs/06 §59c, AUTH-ADR-057): Sanctum HttpOnly session
// cookie + XSRF token. No token is ever read or stored by JavaScript.

export type CurrentUser = {
  name: string;
  email: string;
  role: string | null;
  role_label: string | null;
  permissions: string[];
};

export const ME_QUERY_KEY = ["auth", "me"] as const;

/** The one /api/v1/me query: the user, or null when not authenticated. */
export function useMeQuery() {
  return useQuery({
    queryKey: ME_QUERY_KEY,
    queryFn: async (): Promise<CurrentUser | null> => {
      try {
        return (await apiClient.get<{ user: CurrentUser }>("/api/v1/me")).user;
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return null;
        throw error;
      }
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}

export async function login(email: string, password: string): Promise<CurrentUser> {
  return (await apiClient.post<{ user: CurrentUser }>("/api/v1/auth/login", { email, password })).user;
}

export async function logout(): Promise<void> {
  await apiClient.post("/api/v1/auth/logout", {});
}
