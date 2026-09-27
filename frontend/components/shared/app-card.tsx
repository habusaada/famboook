import { cn } from "@/lib/utils";

// Famboook Design System v1 — the one surface primitive (docs/10 §Card).
// Variants map to the elevation model:
//   flat      bordered, no shadow (elevation 0) — registry/profile screens
//   standard  subtle stroke + elevation 1 — dashboard widgets
//   elevated  elevation 2 — emphasized widgets
//   subtle    surface-2 fill, no shadow — nested groups inside a card
// `interactive` adds hover/pressed/focus states for clickable cards.

export type AppCardVariant = "flat" | "standard" | "elevated" | "subtle";

const variants: Record<AppCardVariant, string> = {
  flat: "rounded-lg border bg-surface-1",
  standard: "rounded-widget border border-stroke-subtle/80 bg-surface-1 shadow-e1",
  elevated: "rounded-widget border border-stroke-subtle bg-surface-3 shadow-e2",
  subtle: "rounded-lg bg-surface-2",
};

export function AppCard({
  variant = "standard",
  interactive,
  padded = true,
  className,
  ...props
}: React.ComponentProps<"section"> & { variant?: AppCardVariant; interactive?: boolean; padded?: boolean }) {
  return (
    <section
      className={cn(
        "min-w-0 text-card-foreground",
        variants[variant],
        padded && "p-4 sm:p-5",
        interactive &&
          "transition-[box-shadow,background-color,border-color] duration-150 hover:border-border hover:shadow-e2 active:bg-surface-pressed focus-within:outline-none has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-ring",
        className
      )}
      {...props}
    />
  );
}
