"use client";

import { Lock } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";

/**
 * Renders a page only for users holding the permission (UX; the API
 * authorizes every request). Used for screens that are entirely an
 * action, e.g. registering a Family.
 */
export function RequirePermission({ permission, children }: { permission: string; children: React.ReactNode }) {
  const { can } = useAuth();
  if (can(permission)) return <>{children}</>;

  return (
    <div
      className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground"
      data-forbidden
    >
      <Lock className="size-6" />
      لا تملك صلاحية الوصول إلى هذه الصفحة.
    </div>
  );
}
