"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { FileText, House, Plus, UserRound, UsersRound, type LucideIcon } from "lucide-react";

// The approved Family Portal navigation (docs/11 §23). Only the pages that
// exist are links — الرئيسية (/family) and أسرتي (/family/members); every
// other entry, and the central action, is visible but disabled: no link, no
// route, no placeholder workflow behind it. The current entry follows the
// pathname.

type Entry = { label: string; icon: LucideIcon };

const LATER: Record<"requests" | "account", Entry> = {
  requests: { label: "طلباتي", icon: FileText },
  account: { label: "حسابي", icon: UserRound },
};

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

function Disabled({ label, icon: Icon }: Entry) {
  return (
    <li className="flex flex-1 justify-center">
      <button
        type="button"
        disabled
        aria-disabled="true"
        title="قريبًا"
        className="flex min-h-14 w-full flex-col items-center justify-center gap-1 text-[11px] font-medium text-subtle-foreground opacity-60"
      >
        <Icon className="size-5" aria-hidden />
        {label}
      </button>
    </li>
  );
}

export function FamilyBottomNav() {
  const pathname = usePathname();
  const onMembers = pathname === "/family/members" || pathname.startsWith("/family/members/");

  return (
    <nav
      aria-label="التنقل الرئيسي"
      className="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-surface-1 pb-[env(safe-area-inset-bottom)]"
      data-family-nav
    >
      <ul className="mx-auto flex max-w-md items-stretch px-2">
        <NavLink href="/family" label="الرئيسية" icon={House} current={pathname === "/family"} />
        <NavLink href="/family/members" label="أسرتي" icon={UsersRound} current={onMembers} />
        <li className="flex flex-1 items-center justify-center">
          <button
            type="button"
            disabled
            aria-disabled="true"
            aria-label="إجراء جديد (قريبًا)"
            title="قريبًا"
            className="flex size-12 items-center justify-center rounded-full border border-border bg-surface-2 text-subtle-foreground opacity-70"
            data-family-nav-action
          >
            <Plus className="size-6" aria-hidden />
          </button>
        </li>
        <Disabled {...LATER.requests} />
        <Disabled {...LATER.account} />
      </ul>
    </nav>
  );
}
