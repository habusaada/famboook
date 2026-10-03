"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { ArrowRight, Loader2, LogOut, Network, ShieldAlert } from "lucide-react";
import { FamilyBottomNav } from "@/components/family/bottom-nav";
import { FamilyBrand } from "@/components/family/family-brand";
import { useFamilyUser } from "@/components/family/family-context";
import { FAMILY_ME_QUERY_KEY, familyLogout } from "@/lib/api/family-auth";

function LogoutButton() {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);

  async function signOut() {
    setPending(true);
    // Even if the server call fails the local session state is dropped;
    // the next API call would be refused anyway.
    await familyLogout().catch(() => undefined);
    queryClient.clear();
    queryClient.setQueryData(FAMILY_ME_QUERY_KEY, null);
    router.replace("/family/login");
  }

  return (
    <button
      type="button"
      onClick={signOut}
      disabled={pending}
      className="flex h-10 items-center gap-1.5 rounded-xl px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-60"
      data-family-logout
    >
      {pending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <LogOut className="size-4" aria-hidden />}
      تسجيل الخروج
    </button>
  );
}

/**
 * The account exists, but no Family context does right now. The reason is
 * never sent by the API and never guessed here: no family data, no
 * navigation — only a neutral notice and a way out.
 */
function AccessUnavailable() {
  return (
    <section
      className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-10 text-center"
      aria-labelledby="family-unavailable-title"
      data-family-unavailable
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground" aria-hidden>
        <ShieldAlert className="size-6" />
      </span>
      <h1 id="family-unavailable-title" className="text-lg font-semibold text-foreground">
        الوصول غير متاح حاليًا
      </h1>
      <p className="max-w-xs text-sm leading-relaxed text-muted-foreground">
        لا يمكن عرض بيانات الأسرة من هذا الحساب في الوقت الحالي. للاستفسار، يُرجى مراجعة إدارة السجل.
      </p>
    </section>
  );
}

/**
 * Coordinator Space is a separate MODE of the same portal (docs/11 §7–§8): a
 * strip says so on every Coordinator page and leads back to the household.
 */
function CoordinatorModeStrip() {
  return (
    <div className="border-b border-brand-100 bg-brand-50" data-coordinator-mode>
      <div className="mx-auto flex min-h-12 max-w-md items-center justify-between gap-3 px-4 py-1.5">
        <p className="flex items-center gap-2 text-sm font-semibold text-brand-900">
          <Network className="size-4 shrink-0" aria-hidden />
          أنت في مساحة التنسيق
        </p>
        <Link
          href="/family"
          className="inline-flex min-h-10 shrink-0 items-center gap-1 rounded-lg px-2 text-[13px] font-semibold text-brand-700 hover:bg-brand-100 focus-visible:outline-2 focus-visible:outline-ring"
        >
          <ArrowRight className="size-4" aria-hidden />
          العودة إلى أسرتي
        </Link>
      </div>
    </div>
  );
}

/**
 * The authenticated Family Portal shell (docs/11 §23–24): mobile-first, one
 * narrow column at every width, bottom navigation, no sidebar. Without a
 * Family context it renders the neutral notice INSTEAD of the page and the
 * navigation. Inside Coordinator Space the household navigation is hidden
 * and the mode strip is shown: assigned Families never appear as part of the
 * user's own household.
 */
export function FamilyShell({ children }: { children: React.ReactNode }) {
  const user = useFamilyUser();
  const pathname = usePathname();
  const available = user.context.available;
  const coordinatorMode = available && (pathname === "/family/coordinator" || pathname.startsWith("/family/coordinator/"));

  return (
    <div className="flex min-h-svh flex-col">
      <header className="sticky top-0 z-10 border-b border-border bg-surface-1">
        <div className="mx-auto flex h-16 max-w-md items-center justify-between px-4">
          <FamilyBrand />
          <LogoutButton />
        </div>
        {coordinatorMode && <CoordinatorModeStrip />}
      </header>

      <main className={`mx-auto w-full max-w-md flex-1 px-4 pt-6 ${available && !coordinatorMode ? "pb-28" : "pb-10"}`}>
        {available ? children : <AccessUnavailable />}
      </main>

      {available && !coordinatorMode && <FamilyBottomNav />}
    </div>
  );
}
