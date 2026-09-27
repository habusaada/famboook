"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "@/lib/utils";
import { useAuth } from "@/components/auth/auth-context";
import { navGroups, navItems } from "@/lib/navigation";

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const { canAny } = useAuth();
  // Only sections the user can open (docs/06 §59c); actions inside a
  // section are gated separately. Empty groups are not shown.
  const items = navItems.filter((item) => canAny(item.permissions));

  return (
    <nav className="flex flex-1 flex-col gap-6 overflow-y-auto px-3 py-5" aria-label="التنقل الرئيسي">
      {navGroups.map((group) => {
        const groupItems = items.filter((item) => item.group === group.key);
        if (groupItems.length === 0) return null;

        return (
          <div key={group.key} className="flex flex-col gap-1">
            {group.label && (
              <p className="px-3 pb-1 text-[11px] font-semibold text-subtle-foreground">{group.label}</p>
            )}
            {groupItems.map((item) => {
              const isActive = item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);
              const Icon = item.icon;

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  onClick={onNavigate}
                  aria-current={isActive ? "page" : undefined}
                  className={cn(
                    "group/nav relative flex h-10 items-center gap-3 rounded-lg px-3 text-sm transition-colors duration-150 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring",
                    isActive
                      ? "bg-surface-selected font-semibold text-brand-800 ring-1 ring-brand-100 ring-inset before:absolute before:inset-y-2.5 before:-start-3 before:w-[3px] before:rounded-e-full before:bg-brand-600"
                      : "font-medium text-muted-foreground hover:bg-surface-hover hover:text-foreground active:bg-surface-pressed"
                  )}
                >
                  <Icon strokeWidth={1.75} className={cn("size-[18px] shrink-0 transition-colors", isActive ? "text-brand-700" : "text-subtle-foreground group-hover/nav:text-muted-foreground")} />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </div>
        );
      })}
    </nav>
  );
}
