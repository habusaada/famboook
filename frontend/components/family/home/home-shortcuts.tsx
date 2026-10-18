import Link from "next/link";
import { FileText, IdCard, UserRound, UsersRound, type LucideIcon } from "lucide-react";

// FB-FAMILY-DASHBOARD-01: the Family home's shortcuts — existing routes of
// the portal only; each destination authorizes on its own.

export const HOME_SHORTCUTS: { href: string; label: string; hint: string; icon: LucideIcon; field: string }[] = [
  { href: "/family/card", label: "بطاقة الأسرة", hint: "البطاقة الرقمية ورمز التحقق", icon: IdCard, field: "card" },
  { href: "/family/household", label: "أسرتي", hint: "بيانات الأسرة والسكن والأفراد", icon: UsersRound, field: "household" },
  { href: "/family/requests", label: "طلباتي", hint: "متابعة طلبات التحديث", icon: FileText, field: "requests" },
  { href: "/family/account", label: "حسابي", hint: "بياناتي وكلمة المرور", icon: UserRound, field: "account" },
];

export function HomeShortcuts() {
  return (
    <nav aria-labelledby="family-shortcuts-title" data-home-shortcuts>
      <h2 id="family-shortcuts-title" className="mb-2 text-sm font-semibold text-foreground">
        الوصول السريع
      </h2>
      <ul className="grid grid-cols-2 gap-2.5">
        {HOME_SHORTCUTS.map(({ href, label, hint, icon: Icon, field }) => (
          <li key={href}>
            <Link
              href={href}
              className="flex h-full min-h-20 flex-col gap-2 rounded-2xl border border-border bg-surface-1 p-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
              data-home-shortcut={field}
            >
              <span className="flex size-8 items-center justify-center rounded-lg bg-brand-50 text-brand-700" aria-hidden>
                <Icon className="size-4" />
              </span>
              <span className="flex flex-col gap-0.5">
                <span className="text-sm font-semibold text-foreground">{label}</span>
                <span className="text-xs leading-snug text-muted-foreground">{hint}</span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
