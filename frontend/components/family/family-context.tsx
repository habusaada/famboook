"use client";

import { createContext, useContext } from "react";
import type { FamilyUser } from "@/lib/api/family-auth";

// The signed-in family-side user, provided once by FamilyGate from the single
// /api/v1/family/me query. UX only: every Family API authorizes on its own.

export const FamilyUserContext = createContext<FamilyUser | null>(null);

export function useFamilyUser(): FamilyUser {
  const user = useContext(FamilyUserContext);
  if (!user) throw new Error("useFamilyUser must be used inside the authenticated Family Portal.");
  return user;
}
