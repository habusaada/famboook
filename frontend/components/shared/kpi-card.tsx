import Link from "next/link";
import type { LucideIcon } from "lucide-react";
import { AppCard } from "@/components/shared/app-card";
import { IconBox, type Tone } from "@/components/shared/icon-box";

// KPI card (docs/10 §KPI). Composition: the icon anchors a label + metric
// block at the reading start; context and an optional data visual follow.
// Secondary content is shown only when the existing data supports it —
// never invented trends.

export function KpiCard({
  icon,
  tone = "brand",
  label,
  value,
  context,
  secondary,
  href,
}: {
  icon: LucideIcon;
  tone?: Tone;
  label: string;
  value: React.ReactNode;
  context?: React.ReactNode;
  secondary?: React.ReactNode;
  href?: string;
}) {
  const body = (
    <div className="flex h-full flex-col gap-3">
      <div className="flex items-center gap-3">
        <IconBox icon={icon} tone={tone} size="lg" />
        <div className="flex min-w-0 flex-col">
          <span className="truncate text-[13px] font-medium text-muted-foreground">{label}</span>
          <span className="text-[30px] leading-9 font-bold tracking-tight tabular-nums text-foreground">{value}</span>
        </div>
      </div>
      {(context || secondary) && (
        <div className="mt-auto flex flex-col gap-1.5 border-t border-stroke-subtle pt-3">
          {secondary}
          {context && <p className="text-xs text-subtle-foreground">{context}</p>}
        </div>
      )}
    </div>
  );

  return (
    <AppCard interactive={Boolean(href)} padded={false} className="relative h-full p-4" data-kpi={label}>
      {href ? (
        <Link href={href} className="block h-full rounded-widget focus-visible:outline-none" aria-label={`${label} — عرض التفاصيل`}>
          {body}
        </Link>
      ) : (
        body
      )}
    </AppCard>
  );
}
