import { cn } from "@/lib/utils";

// One badge language for statuses (docs/10 §Status). The tone carries
// meaning; the dot keeps it readable without relying on color alone —
// the label is always text.

export type StatusTone = "success" | "warning" | "danger" | "info" | "neutral" | "brand";

const tones: Record<StatusTone, string> = {
  success: "bg-success-soft text-success",
  warning: "bg-warning-soft text-warning",
  danger: "bg-danger-soft text-danger",
  info: "bg-info-soft text-info",
  neutral: "bg-secondary text-muted-foreground",
  brand: "bg-brand-50 text-brand-800",
};

export function StatusBadge({
  tone,
  children,
  className,
}: {
  tone: StatusTone;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <span
      className={cn(
        "inline-flex h-6 shrink-0 items-center gap-1.5 rounded-control px-2 text-xs font-medium whitespace-nowrap ring-1 ring-current/10 ring-inset",
        tones[tone],
        className
      )}
      data-status-tone={tone}
    >
      <span aria-hidden className="size-1.5 rounded-full bg-current" />
      {children}
    </span>
  );
}
