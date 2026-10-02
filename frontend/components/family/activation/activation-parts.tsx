import { AlertCircle } from "lucide-react";
import { cn } from "@/lib/utils";

/** Shared sizing of the activation controls: 48px targets, no iOS zoom. */
export const fieldClass =
  "h-12 rounded-xl border-input bg-surface-1 px-3.5 text-base md:text-base focus-visible:border-ring";

export const primaryButtonClass =
  "h-12 w-full rounded-xl text-[15px] font-semibold hover:bg-[var(--family-primary-hover)]";

export function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null;
  return (
    <p id={id} className="flex items-start gap-1.5 text-[13px] leading-relaxed text-danger" role="alert">
      <AlertCircle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
      {message}
    </p>
  );
}

export function FormAlert({ message, tone = "error" }: { message: string | null; tone?: "error" | "info" }) {
  if (!message) return null;
  return (
    <div
      role={tone === "error" ? "alert" : "status"}
      className={cn(
        "flex items-start gap-2 rounded-xl border px-3.5 py-3 text-sm leading-relaxed",
        tone === "error" ? "border-danger/20 bg-danger-soft text-danger" : "border-brand-100 bg-brand-50 text-brand-900"
      )}
      data-activation-alert={tone}
    >
      <AlertCircle className="mt-0.5 size-4 shrink-0" aria-hidden />
      <span>{message}</span>
    </div>
  );
}

export function StepHeading({ id, title, children }: { id: string; title: string; children?: React.ReactNode }) {
  return (
    <div className="mb-5 flex flex-col gap-1.5">
      <h1 id={id} className="text-[22px] leading-snug font-bold text-foreground">
        {title}
      </h1>
      {children && <p className="text-[15px] leading-relaxed text-muted-foreground">{children}</p>}
    </div>
  );
}
