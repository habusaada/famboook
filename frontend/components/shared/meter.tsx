import { cn } from "@/lib/utils";
import { toneFill, type Tone } from "@/components/shared/icon-box";

// Lightweight CSS data visuals (docs/10 §Metrics). No chart library.
// Bars grow from the reading start (right in RTL). Each visual carries a
// text alternative, so meaning never depends on color alone.

const nf = new Intl.NumberFormat("ar");
export const share = (n: number, total: number) => (total > 0 ? Math.round((n / total) * 100) : 0);

export function ProgressBar({
  value,
  total,
  tone = "brand",
  label,
  className,
  size = "md",
}: {
  value: number;
  total: number;
  tone?: Tone;
  label: string;
  className?: string;
  size?: "sm" | "md";
}) {
  const pct = share(value, total);
  return (
    <div
      role="img"
      aria-label={`${label}: ${nf.format(value)} من ${nf.format(total)} (${nf.format(pct)}٪)`}
      className={cn("overflow-hidden rounded-full bg-surface-pressed/70", size === "sm" ? "h-1.5" : "h-2", className)}
    >
      <div className={cn("h-full rounded-full", toneFill[tone])} style={{ width: `${pct}%` }} />
    </div>
  );
}

export type Segment = { key: string; label: string; value: number; tone?: Tone; className?: string };

/** One bar split into proportional segments (e.g. gender, displacement). */
export function SegmentedBar({
  segments,
  label,
  className,
  size = "md",
}: {
  segments: Segment[];
  label: string;
  className?: string;
  size?: "sm" | "md" | "lg";
}) {
  const total = segments.reduce((sum, s) => sum + s.value, 0);
  const description = segments.map((s) => `${s.label} ${nf.format(s.value)}`).join("، ");
  return (
    <div
      role="img"
      aria-label={`${label}: ${description}`}
      className={cn(
        "flex gap-0.5 overflow-hidden rounded-full bg-surface-pressed/70",
        size === "sm" ? "h-1.5" : size === "lg" ? "h-3" : "h-2",
        className
      )}
    >
      {total > 0 &&
        segments
          .filter((s) => s.value > 0)
          .map((s) => (
            <div
              key={s.key}
              className={cn("h-full first:rounded-s-full last:rounded-e-full", s.className ?? toneFill[s.tone ?? "brand"])}
              style={{ width: `${(s.value / total) * 100}%` }}
              title={`${s.label}: ${nf.format(s.value)}`}
            />
          ))}
    </div>
  );
}

/** Legend entry: dot, label, value and optional share. */
export function LegendItem({
  label,
  value,
  total,
  tone = "brand",
  dotClassName,
}: {
  label: string;
  value: number;
  total?: number;
  tone?: Tone;
  dotClassName?: string;
}) {
  return (
    <div className="flex items-center justify-between gap-3 text-sm">
      <span className="flex min-w-0 items-center gap-2">
        <span aria-hidden className={cn("size-2 shrink-0 rounded-full", dotClassName ?? toneFill[tone])} />
        <span className="truncate text-muted-foreground">{label}</span>
      </span>
      {/* Each number isolated: adjacent digit runs would otherwise merge under RTL bidi. */}
      <span className="flex shrink-0 items-baseline gap-2 tabular-nums">
        <bdi className="font-semibold text-foreground">{nf.format(value)}</bdi>
        {total !== undefined && total > 0 && (
          <bdi className="min-w-9 text-end text-xs text-subtle-foreground">{nf.format(share(value, total))}٪</bdi>
        )}
      </span>
    </div>
  );
}
