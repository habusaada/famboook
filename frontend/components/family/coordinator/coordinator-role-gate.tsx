"use client";

import Link from "next/link";
import { Lock } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useFamilyUser } from "@/components/family/family-context";

/**
 * The guard of role-only Coordinator pages (FB-NOTIFY-01C.3b): pages a
 * coordinator keeps after their scope assignments are revoked — the read-only
 * history of what THEY sent (ADR FB-NOTIFY-01 ADR-04). It renders inside
 * FamilyGate, so the session and the Family context are already settled
 * there (no session → login, not family-side → notice).
 *
 * It needs only the COORDINATOR role as /family/me reports it
 * (`coordinator`); it never requires the current scope — `coordinator_space`
 * false is a revoked or empty scope, NOT a lost role. It asks the server
 * nothing itself: each page's own endpoint authorizes again (permission and
 * ownership), and its 401 / 403 go through the session and context flows.
 * Never use it for a scope-dependent operation: those stay in the
 * coordinator (space) group behind CoordinatorGate.
 */
export function CoordinatorRoleGate({ children }: { children: React.ReactNode }) {
  const user = useFamilyUser();

  if (!user.coordinator) {
    return (
      <section
        className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-10 text-center"
        data-coordinator-role-required
      >
        <Lock className="size-6 text-muted-foreground" aria-hidden />
        <p className="max-w-xs text-sm leading-relaxed text-muted-foreground" role="alert">
          هذه الصفحة متاحة لمنسقي العائلات فقط.
        </p>
        <Button asChild variant="outline" className="h-11 rounded-xl px-5 text-sm font-semibold">
          <Link href="/family">العودة إلى أسرتي</Link>
        </Button>
      </section>
    );
  }

  return <>{children}</>;
}
