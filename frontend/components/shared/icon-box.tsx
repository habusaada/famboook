import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

// Contextual icon emphasis (docs/10 §Icons): soft tint + stronger glyph.
// For scanning aids in widgets (KPI cards, section headers) — not for
// every icon in the application.

export type Tone = "brand" | "info" | "success" | "warning" | "danger" | "neutral";

export const toneSoft: Record<Tone, string> = {
  brand: "bg-brand-50 text-brand-700",
  info: "bg-info-soft text-info",
  success: "bg-success-soft text-success",
  warning: "bg-warning-soft text-warning",
  danger: "bg-danger-soft text-danger",
  neutral: "bg-surface-2 text-muted-foreground",
};

/** Solid fill per tone, for bars and legend dots. */
export const toneFill: Record<Tone, string> = {
  brand: "bg-brand-600",
  info: "bg-info",
  success: "bg-success",
  warning: "bg-warning",
  danger: "bg-danger",
  neutral: "bg-subtle-foreground/35",
};

const sizes = {
  sm: "size-8 rounded-control [&>svg]:size-4",
  md: "size-10 rounded-lg [&>svg]:size-5",
  lg: "size-11 rounded-lg [&>svg]:size-[22px]",
};

export function IconBox({
  icon: Icon,
  tone = "brand",
  size = "md",
  className,
}: {
  icon: LucideIcon;
  tone?: Tone;
  size?: keyof typeof sizes;
  className?: string;
}) {
  return (
    <span aria-hidden className={cn("flex shrink-0 items-center justify-center", toneSoft[tone], sizes[size], className)}>
      <Icon strokeWidth={1.75} />
    </span>
  );
}
