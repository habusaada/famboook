import type { LucideIcon } from "lucide-react";

// Compact activity row (docs/10 §Activity): timeline marker, then
// 1. event title (strongest), 2. affected entity, 3. actor/time (quietest).

export function ActivityItem({
  icon: Icon,
  title,
  entity,
  meta,
  last,
  ...props
}: {
  icon: LucideIcon;
  title: React.ReactNode;
  entity?: React.ReactNode;
  meta: React.ReactNode;
  last?: boolean;
} & React.ComponentProps<"li">) {
  return (
    <li className="relative flex gap-3 ps-0" {...props}>
      <div className="relative flex w-8 shrink-0 justify-center">
        <span className="z-10 mt-0.5 flex size-8 items-center justify-center rounded-full border border-stroke-subtle bg-surface-1 text-muted-foreground">
          <Icon className="size-4" strokeWidth={1.75} aria-hidden />
        </span>
        {!last && <span aria-hidden className="absolute top-9 bottom-0 w-px bg-stroke-subtle" />}
      </div>
      <div className="flex min-w-0 flex-1 flex-col gap-0.5 pb-4">
        <p className="text-sm leading-snug font-semibold text-foreground">{title}</p>
        {entity && <p className="truncate text-[13px] text-muted-foreground">{entity}</p>}
        <p className="text-xs text-subtle-foreground">{meta}</p>
      </div>
    </li>
  );
}
