import Link from "next/link";
import { FileText, House, Plus, UserRound, UsersRound, type LucideIcon } from "lucide-react";

// The approved Family Portal navigation (docs/11 §23). In PWA-1F only the
// home exists: every other entry, and the central action, is visible but
// disabled — no link, no route, no placeholder workflow behind it.

const LATER: { label: string; icon: LucideIcon }[] = [
  { label: "أسرتي", icon: UsersRound },
  { label: "طلباتي", icon: FileText },
  { label: "حسابي", icon: UserRound },
];

function Disabled({ label, icon: Icon }: { label: string; icon: LucideIcon }) {
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
  return (
    <nav
      aria-label="التنقل الرئيسي"
      className="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-surface-1 pb-[env(safe-area-inset-bottom)]"
      data-family-nav
    >
      <ul className="mx-auto flex max-w-md items-stretch px-2">
        <li className="flex flex-1 justify-center">
          <Link
            href="/family"
            aria-current="page"
            className="flex min-h-14 w-full flex-col items-center justify-center gap-1 text-[11px] font-semibold text-brand-700 focus-visible:outline-2 focus-visible:outline-ring"
          >
            <House className="size-5" aria-hidden />
            الرئيسية
          </Link>
        </li>
        <Disabled {...LATER[0]} />
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
        <Disabled {...LATER[1]} />
        <Disabled {...LATER[2]} />
      </ul>
    </nav>
  );
}
