"use client";

import { useState } from "react";
import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ApiError } from "@/lib/api/client";
import { ME_QUERY_KEY } from "@/lib/api/auth";

export function QueryProvider({ children }: { children: React.ReactNode }) {
  const [client] = useState(() => {
    // Any 401 (session expired, logged out elsewhere, account deactivated)
    // ends the session in the UI: AuthGate then redirects to /login.
    const onError = (error: unknown) => {
      if (error instanceof ApiError && error.status === 401) {
        queryClient.setQueryData(ME_QUERY_KEY, null);
      }
    };
    const queryClient: QueryClient = new QueryClient({
      queryCache: new QueryCache({ onError }),
      mutationCache: new MutationCache({ onError }),
      defaultOptions: {
        queries: {
          staleTime: 30_000,
          // Never retry an authentication/authorization failure.
          retry: (failureCount, error) =>
            !(error instanceof ApiError && (error.status === 401 || error.status === 403)) && failureCount < 1,
        },
      },
    });
    return queryClient;
  });

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
