import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

/** Explains what happened and, where an existing action applies, what to do. */
export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
}: {
  icon: LucideIcon;
  title: string;
  description?: React.ReactNode;
  action?: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex flex-col items-center justify-center gap-2 px-6 py-10 text-center", className)} data-empty-state>
      <Icon className="size-8 text-subtle-foreground" aria-hidden />
      <p className="text-[15px] font-semibold text-foreground">{title}</p>
      {description && <p className="max-w-md text-sm text-muted-foreground">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  );
}
