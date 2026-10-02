"use client";

import { useEffect } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertCircle, Loader2, Lock } from "lucide-react";
import { Button } from "@/components/ui/button";
import { FamilyBrand } from "@/components/family/family-brand";
import { FamilyUserContext } from "@/components/family/family-context";
import { FamilyShell } from "@/components/family/family-shell";
import { ApiError } from "@/lib/api/client";
import { useFamilyMeQuery } from "@/lib/api/family-auth";

/** Where a visitor without a Family session goes. */
export const FAMILY_ENTRY_ROUTE = "/family/login";

function Centered({ children }: { children: React.ReactNode }) {
  return <div className="flex min-h-svh flex-col items-center justify-center gap-5 px-4 text-center">{children}</div>;
}

/**
 * Protects the authenticated Family Portal. Nothing of it renders until
 * /api/v1/family/me has answered: no session → the Family login; an account
 * that is not family-side (403, e.g. a Staff session in this browser) → a
 * neutral notice with the way to the Family login — never a silent redirect,
 * and never the portal. It never mounts or queries the Staff gate. The API
 * remains authoritative.
 */
export function FamilyGate({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const me = useFamilyMeQuery();
  const user = me.data ?? null;

  const unauthenticated = me.isSuccess && user === null;
  useEffect(() => {
    if (unauthenticated) router.replace(FAMILY_ENTRY_ROUTE);
  }, [unauthenticated, router]);

  if (me.isError) {
    const forbidden = me.error instanceof ApiError && me.error.status === 403;

    return (
      <Centered>
        <FamilyBrand />
        {forbidden ? (
          <div className="flex max-w-xs flex-col items-center gap-3" data-family-forbidden>
            <Lock className="size-6 text-muted-foreground" aria-hidden />
            <p className="text-sm leading-relaxed text-muted-foreground" role="alert">
              هذا الحساب ليس حساب أسرة، ولا يمكنه استخدام بوابة الأسرة.
            </p>
            {/* Signing in as a family account replaces the current session. */}
            <Button asChild className="h-11 rounded-xl px-5 text-sm font-semibold hover:bg-[var(--family-primary-hover)]">
              <Link href={FAMILY_ENTRY_ROUTE}>تسجيل الدخول بحساب الأسرة</Link>
            </Button>
          </div>
        ) : (
          <div className="flex max-w-xs flex-col items-center gap-3" data-family-error>
            <AlertCircle className="size-6 text-danger" aria-hidden />
            <p className="text-sm text-muted-foreground" role="alert">
              تعذّر التحقق من الجلسة. تعذّر الاتصال بالخادم.
            </p>
            <Button variant="outline" className="h-10 px-4" onClick={() => me.refetch()}>
              إعادة المحاولة
            </Button>
          </div>
        )}
      </Centered>
    );
  }

  if (!user) {
    return (
      <Centered>
        <Loader2 className="size-6 animate-spin text-brand-700" aria-label="جارٍ التحقق من الجلسة" />
      </Centered>
    );
  }

  return (
    <FamilyUserContext.Provider value={user}>
      <FamilyShell>{children}</FamilyShell>
    </FamilyUserContext.Provider>
  );
}
