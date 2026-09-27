import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { AppCard } from "@/components/shared/app-card";
import { IconBox, type Tone } from "@/components/shared/icon-box";

// Page-level layout primitives (Famboook Design System v1, docs/10):
// page header, the flat bordered section used by registry/profile screens,
// section header, label/value list and bidi-safe codes.

export function PageHeader({
  title,
  description,
  actions,
  eyebrow,
}: {
  title: React.ReactNode;
  description?: React.ReactNode;
  actions?: React.ReactNode;
  eyebrow?: React.ReactNode;
}) {
  return (
    <header className="flex flex-col gap-2.5 sm:flex-row sm:items-end sm:justify-between">
      <div className="flex min-w-0 flex-col gap-1">
        {eyebrow}
        <h1 className="text-2xl leading-tight font-bold text-foreground">{title}</h1>
        {description && <p className="text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
    </header>
  );
}

/** Flat bordered section (AppCard `flat`). `flush` removes inner padding (tables, lists). */
export function Panel({ flush, ...props }: React.ComponentProps<"section"> & { flush?: boolean }) {
  return <AppCard variant="flat" padded={!flush} {...props} />;
}

export function SectionHeader({
  title,
  description,
  action,
  className,
  icon,
  tone = "brand",
}: {
  title: React.ReactNode;
  description?: React.ReactNode;
  action?: React.ReactNode;
  className?: string;
  /** Optional contextual icon (dashboard widgets). */
  icon?: LucideIcon;
  tone?: Tone;
}) {
  return (
    <div className={cn("flex items-start justify-between gap-3", className)}>
      {icon && <IconBox icon={icon} tone={tone} size="sm" className="mt-0.5" />}
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <h2 className="text-base leading-snug font-semibold">{title}</h2>
        {description && <p className="text-[13px] leading-relaxed text-muted-foreground">{description}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  );
}

/** Label / value pairs in a readable two-column grid (desktop), stacked on phones. */
export function DetailList({ className, ...props }: React.ComponentProps<"dl">) {
  return <dl className={cn("grid grid-cols-1 gap-x-8 sm:grid-cols-2", className)} {...props} />;
}

export function DetailItem({
  label,
  children,
  ltr,
  muted,
}: {
  label: string;
  children: React.ReactNode;
  ltr?: boolean;
  muted?: boolean;
}) {
  return (
    <div className="flex items-baseline justify-between gap-4 border-b border-border/70 py-2 last:border-0 sm:[&:nth-last-child(2):nth-child(odd)]:border-0">
      <dt className="shrink-0 text-[13px] font-medium text-muted-foreground">{label}</dt>
      <dd className={cn("min-w-0 text-end text-sm font-medium", muted && "font-normal text-subtle-foreground")}>
        {ltr ? <bdi dir="ltr">{children}</bdi> : children}
      </dd>
    </div>
  );
}

/** Codes such as FAM-000050 / PER-000181: isolated LTR, never reordered by RTL text. */
export function Code({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    <bdi dir="ltr" className={cn("font-medium tracking-wide tabular-nums", className)}>
      {children}
    </bdi>
  );
}
