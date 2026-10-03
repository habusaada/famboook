"use client";

import Link from "next/link";
import { AlertCircle, Loader2, Lock } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ApiError } from "@/lib/api/client";
import { type CoordinatorContext, useCoordinatorContextQuery } from "@/lib/api/coordinator";
import { createContext, useContext } from "react";

const CoordinatorContextValue = createContext<CoordinatorContext | null>(null);

export function useCoordinatorContext(): CoordinatorContext {
  const value = useContext(CoordinatorContextValue);
  if (!value) throw new Error("useCoordinatorContext must be used inside Coordinator Space.");
  return value;
}

function Notice({ icon, children }: { icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-10 text-center">
      {icon}
      {children}
      <Button asChild variant="outline" className="h-11 rounded-xl px-5 text-sm font-semibold">
        <Link href="/family">العودة إلى أسرتي</Link>
      </Button>
    </section>
  );
}

/**
 * Coordinator Space opens only when the server says so: GET
 * /family/coordinator/context answers 200 for an account with a Family
 * context, the COORDINATOR role and an effective scope, and 403 otherwise.
 * The /family/me flag only decides whether the entry is shown; this gate asks
 * the authoritative endpoint every time.
 */
export function CoordinatorGate({ children }: { children: React.ReactNode }) {
  const context = useCoordinatorContextQuery();

  if (context.isError) {
    const forbidden = context.error instanceof ApiError && context.error.status === 403;
    return forbidden ? (
      <Notice icon={<Lock className="size-6 text-muted-foreground" aria-hidden />}>
        <p className="max-w-xs text-sm leading-relaxed text-muted-foreground" role="alert" data-coordinator-unavailable>
          مساحة التنسيق غير متاحة لهذا الحساب.
        </p>
      </Notice>
    ) : (
      <Notice icon={<AlertCircle className="size-6 text-danger" aria-hidden />}>
        <p className="text-sm text-muted-foreground" role="alert">
          تعذّر الاتصال بالخادم.
        </p>
      </Notice>
    );
  }

  if (!context.data) {
    return (
      <div className="flex justify-center py-16">
        <Loader2 className="size-6 animate-spin text-brand-700" aria-label="جارٍ تحميل مساحة التنسيق" />
      </div>
    );
  }

  return <CoordinatorContextValue.Provider value={context.data}>{children}</CoordinatorContextValue.Provider>;
}
