"use client";

import { createContext, useContext } from "react";
import type { CurrentUser } from "@/lib/api/auth";

// The authenticated Staff user and a permission helper, provided once by
// AuthGate from the single /api/v1/me query. UX only: the Laravel API
// authorizes every request (docs/06 §12).

type AuthValue = {
  user: CurrentUser;
  can: (permission: string) => boolean;
  canAny: (permissions: readonly string[]) => boolean;
};

export const AuthContext = createContext<AuthValue | null>(null);

export function authValue(user: CurrentUser): AuthValue {
  const granted = new Set(user.permissions);
  return {
    user,
    can: (permission) => granted.has(permission),
    canAny: (permissions) => permissions.some((p) => granted.has(p)),
  };
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error("useAuth must be used inside the authenticated Staff Portal.");
  return value;
}

/** Shorthand for components that only need to gate an action. */
export function useCan(): AuthValue["can"] {
  return useAuth().can;
}
