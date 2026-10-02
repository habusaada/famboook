"use client";

import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { FAMILY_ME_QUERY_KEY, type FamilyUser } from "@/lib/api/family-auth";

/**
 * What every successful Family sign-in does on the client — after login,
 * activation or a password reset: the server already established the
 * session, so the cache of any previous session is dropped, the user is
 * seeded and the portal opens.
 */
export function useFamilySignIn(): (user: FamilyUser) => void {
  const router = useRouter();
  const queryClient = useQueryClient();

  return (user) => {
    // Nothing cached for a previous session survives into this one.
    queryClient.clear();
    queryClient.setQueryData(FAMILY_ME_QUERY_KEY, user);
    router.replace("/family");
  };
}
