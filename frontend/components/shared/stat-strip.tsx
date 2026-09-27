import Link from "next/link";
import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

// Restrained key figures (visual prototype): one surface divided into
// cells — label, readable number, optional context. No icons, no colors
// per metric. Used by the Dashboard KPIs and the Families registry summary.

export type StatItem = {
  label: string;
  value: React.ReactNode | undefined; // undefined = loading
  hint?: React.ReactNode;
  href?: string;
};

export function StatStrip({ items, className, size = "lg" }: { items: StatItem[]; className?: string; size?: "lg" | "md" }) {
  return (
    <div
      className={cn(
        "grid grid-cols-2 overflow-hidden rounded-lg border bg-card lg:grid-cols-4",
        "[&>*]:border-border [&>*:nth-child(odd)]:border-e max-lg:[&>*:nth-child(-n+2)]:border-b lg:[&>*:not(:last-child)]:border-e",
        className
      )}
    >
      {items.map((item) => {
        const body = (
          <>
            <span className="text-[13px] font-medium text-muted-foreground">{item.label}</span>
            <span className={cn("leading-tight font-semibold tabular-nums text-foreground", size === "lg" ? "text-[28px]" : "text-2xl")}>
              {item.value === undefined ? <Skeleton className="h-8 w-14" /> : item.value}
            </span>
            {item.hint && <span className="text-xs text-subtle-foreground">{item.hint}</span>}
          </>
        );
        const cell = "flex min-w-0 flex-col gap-0.5 px-4 py-3.5 sm:px-5 sm:py-4";
        return item.href ? (
          <Link
            key={item.label}
            href={item.href}
            data-kpi={item.label}
            className={cn(cell, "transition-colors hover:bg-brand-50/60 focus-visible:relative focus-visible:z-10 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring")}
          >
            {body}
          </Link>
        ) : (
          <div key={item.label} className={cell} data-stat={item.label}>
            {body}
          </div>
        );
      })}
    </div>
  );
}
