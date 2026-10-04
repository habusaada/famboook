import { Baby, FilePenLine, HandHelping, HeartPulse, type LucideIcon } from "lucide-react";

// The approved Home quick actions (docs/11 §23). None exists yet: each is
// visibly "قريبًا", semantically disabled, and never a link.

export const QUICK_ACTIONS: { label: string; icon: LucideIcon }[] = [
  { label: "إضافة مولود", icon: Baby },
  { label: "تحديث بيانات الأسرة", icon: FilePenLine },
  { label: "الصحة والإعاقة", icon: HeartPulse },
  { label: "تسجيل احتياج", icon: HandHelping },
];

export function QuickActions() {
  return (
    <section aria-labelledby="family-quick-actions-title" data-quick-actions>
      <h2 id="family-quick-actions-title" className="mb-2 text-sm font-semibold text-foreground">
        الخدمات السريعة
      </h2>
      <ul className="grid grid-cols-2 gap-3">
        {QUICK_ACTIONS.map(({ label, icon: Icon }) => (
          <li key={label}>
            <button
              type="button"
              disabled
              aria-disabled="true"
              className="flex min-h-20 w-full flex-col items-start justify-between gap-2 rounded-2xl border border-border bg-surface-1 p-3 text-start opacity-70"
            >
              <span className="flex w-full items-center justify-between gap-2">
                <span className="flex size-8 items-center justify-center rounded-lg bg-muted text-subtle-foreground" aria-hidden>
                  <Icon className="size-4" />
                </span>
                <span className="rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium text-subtle-foreground">قريبًا</span>
              </span>
              <span className="text-sm font-medium text-muted-foreground">{label}</span>
            </button>
          </li>
        ))}
      </ul>
    </section>
  );
}
