import { cn } from "@/lib/utils";

/**
 * Initials avatar (docs/10 §Avatar): first letters of the first two words
 * of a display name. Decorative — the name is always shown as text too.
 */
export function Initials({ name, className }: { name: string; className?: string }) {
  const letters = name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => Array.from(word)[0] ?? "")
    .join("");

  return (
    <span
      aria-hidden
      className={cn(
        "flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800",
        className
      )}
    >
      {letters || "؟"}
    </span>
  );
}
