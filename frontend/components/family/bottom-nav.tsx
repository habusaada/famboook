"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { FileText, House, Plus, UserRound, UsersRound, type LucideIcon } from "lucide-react";
import { canStartFamilyRequest, useFamilyChangeRequestTypesQuery } from "@/lib/api/family-change-requests";

// The approved Family Portal navigation (docs/11 §23): الرئيسية | أسرتي | + |
// طلباتي | حسابي. The current entry follows the pathname. The shell renders
// it only for a household head WITH a Family context.
//
// docs/11 FP-ADR-074 (resolves PFP-025):
// - «طلباتي» always links to /family/requests: history, clarification replies
//   and cancellation work whether or not new submissions are open;
// - «+» links to /family/requests/new only when the SERVER says a request can
//   be started now (type discovery: submission enabled and a listed type with
//   a Family form here). While loading, on any error, or otherwise, it keeps
//   the established disabled state — never an environment variable alone.

type Entry = { label: string; icon: LucideIcon };

function NavLink({ href, label, icon: Icon, current }: Entry & { href: string; current: boolean }) {
  return (
    <li className="flex flex-1 justify-center">
      <Link
        href={href}
        aria-current={current ? "page" : undefined}
        className={`flex min-h-14 w-full flex-col items-center justify-center gap-1 text-[11px] focus-visible:outline-2 focus-visible:outline-ring ${
          current ? "font-semibold text-brand-700" : "font-medium text-muted-foreground hover:text-foreground"
        }`}
      >
        <Icon className="size-5" aria-hidden />
        {label}
      </Link>
    </li>
  );
}

function NewRequestAction({ current }: { current: boolean }) {
  const types = useFamilyChangeRequestTypesQuery();

  if (!canStartFamilyRequest(types.data)) {
    return (
      <button
        type="button"
        disabled
        aria-disabled="true"
        aria-label="إجراء جديد (قريبًا)"
        title="قريبًا"
        className="flex size-12 items-center justify-center rounded-full border border-border bg-surface-2 text-subtle-foreground opacity-70"
        data-family-nav-action="disabled"
      >
        <Plus className="size-6" aria-hidden />
      </button>
    );
  }

  return (
    <Link
      href="/family/requests/new"
      aria-label="طلب جديد"
      aria-current={current ? "page" : undefined}
      className="flex size-12 items-center justify-center rounded-full bg-brand-700 text-white transition-colors hover:bg-brand-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      data-family-nav-action="enabled"
    >
      <Plus className="size-6" aria-hidden />
    </Link>
  );
}

export function FamilyBottomNav() {
  const pathname = usePathname();
  const within = (root: string) => pathname === root || pathname.startsWith(`${root}/`);
  const inHousehold = within("/family/household") || within("/family/members");
  const newRequest = within("/family/requests/new");

  return (
    <nav
      aria-label="التنقل الرئيسي"
      className="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-surface-1 pb-[env(safe-area-inset-bottom)]"
      data-family-nav
    >
      <ul className="mx-auto flex max-w-md items-stretch px-2">
        <NavLink href="/family" label="الرئيسية" icon={House} current={pathname === "/family"} />
        <NavLink href="/family/household" label="أسرتي" icon={UsersRound} current={inHousehold} />
        <li className="flex flex-1 items-center justify-center">
          <NewRequestAction current={newRequest} />
        </li>
        <NavLink href="/family/requests" label="طلباتي" icon={FileText} current={within("/family/requests") && !newRequest} />
        <NavLink href="/family/account" label="حسابي" icon={UserRound} current={within("/family/account")} />
      </ul>
    </nav>
  );
}
