"use client";

import Link from "next/link";
import { BellOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useFamilyUser } from "@/components/family/family-context";

/**
 * The household's notification pages open only when the server shows them
 * (`notifications` in /family/me — pilot isolation, ADR FB-NOTIFY-01 §2h).
 * Otherwise nothing below renders, so no inbox request is made. UX only:
 * the inbox API authorizes on its own.
 */
export function FamilyNotificationsGate({ children }: { children: React.ReactNode }) {
  const user = useFamilyUser();
  if (user.notifications === true) return <>{children}</>;

  return (
    <section
      className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center"
      aria-labelledby="family-notifications-unavailable"
      data-family-notifications-unavailable
    >
      <BellOff className="size-6 text-muted-foreground" aria-hidden />
      <h1 id="family-notifications-unavailable" className="text-base font-semibold text-foreground">
        الإشعارات غير متاحة حاليًا
      </h1>
      <Button asChild variant="outline" className="h-11 rounded-xl px-4">
        <Link href="/family">العودة إلى الرئيسية</Link>
      </Button>
    </section>
  );
}
