"use client";

import { useEffect, useMemo } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { AlertCircle, Loader2, Lock } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { AppShell } from "@/components/layout/app-shell";
import { useMeQuery } from "@/lib/api/auth";
import { isPublicRoute, navItemFor } from "@/lib/navigation";

function FullScreen({ children }: { children: React.ReactNode }) {
  return <div className="flex min-h-svh items-center justify-center bg-muted/40 p-4">{children}</div>;
}

/**
 * Protects the Staff Portal (docs/06 §59c). Nothing Staff-related renders
 * until /api/v1/me has answered: no session → /login?next=…; a route whose
 * section the user may not open → an in-shell "no access" notice. The API
 * remains authoritative; this only avoids presenting unusable screens.
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const search = useSearchParams();
  const router = useRouter();
  const isPublic = isPublicRoute(pathname);
  const me = useMeQuery();
  const user = me.data ?? null;
  const value = useMemo(() => (user ? authValue(user) : null), [user]);

  const unauthenticated = !isPublic && me.isSuccess && user === null;
  useEffect(() => {
    if (!unauthenticated) return;
    const query = search.toString();
    const next = pathname + (query ? `?${query}` : "");
    router.replace(next === "/" ? "/login" : `/login?next=${encodeURIComponent(next)}`);
  }, [unauthenticated, pathname, search, router]);

  if (isPublic) return <>{children}</>;

  if (me.isError) {
    return (
      <FullScreen>
        <Alert variant="destructive" className="max-w-sm">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر التحقق من الجلسة</AlertTitle>
          <AlertDescription className="flex flex-col items-start gap-3">
            تعذّر الاتصال بالخادم.
            <Button size="sm" variant="outline" onClick={() => me.refetch()}>
              إعادة المحاولة
            </Button>
          </AlertDescription>
        </Alert>
      </FullScreen>
    );
  }

  if (!value) {
    return (
      <FullScreen>
        <Loader2 className="size-6 animate-spin text-muted-foreground" aria-label="جارٍ التحقق من الجلسة" />
      </FullScreen>
    );
  }

  const section = navItemFor(pathname);
  const allowed = !section || value.canAny(section.permissions);

  return (
    <AuthContext.Provider value={value}>
      <AppShell>
        {allowed ? (
          children
        ) : (
          <div
            className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground"
            data-forbidden
          >
            <Lock className="size-6" />
            لا تملك صلاحية الوصول إلى هذا القسم.
          </div>
        )}
      </AppShell>
    </AuthContext.Provider>
  );
}
