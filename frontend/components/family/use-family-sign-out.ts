"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { FAMILY_ME_QUERY_KEY, familyLogout } from "@/lib/api/family-auth";

/**
 * The one Family sign-out (header and «حسابي»): POST
 * /api/v1/family/auth/logout, then every cached Family query is dropped and
 * the Family login opens. Even if the server call fails the local session
 * state is dropped; the next API call would be refused anyway.
 */
export function useFamilySignOut() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);

  async function signOut() {
    setPending(true);
    await familyLogout().catch(() => undefined);
    queryClient.clear();
    queryClient.setQueryData(FAMILY_ME_QUERY_KEY, null);
    router.replace("/family/login");
  }

  return { signOut, pending };
}
