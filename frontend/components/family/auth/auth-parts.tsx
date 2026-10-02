import { AlertCircle } from "lucide-react";
import { FamilyBrand } from "@/components/family/family-brand";
import { cn } from "@/lib/utils";

// The shared pieces of the Family Portal's public screens — activation,
// login, password reset — so the three clearly belong to one product.

/** Shared sizing of the form controls: 48px targets, no iOS zoom. */
export const fieldClass =
  "h-12 rounded-xl border-input bg-surface-1 px-3.5 text-base md:text-base focus-visible:border-ring";

export const primaryButtonClass =
  "h-12 w-full rounded-xl text-[15px] font-semibold hover:bg-[var(--family-primary-hover)]";

/** An inline link with a 40px touch target. */
export const textLinkClass =
  "inline-flex min-h-10 items-center rounded-md px-1 font-semibold text-brand-700 underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring";

/**
 * The public page frame: the brand, one narrow card, a short safety note.
 * Full width on a phone; a focused 400px column on anything wider.
 */
export function FamilyAuthCard({
  step,
  progress,
  children,
  below,
}: {
  /** Reflected as data-auth-step, for tests and tooling only. */
  step: string;
  /** "الخطوة 2 من 3", when the page has steps. */
  progress?: string | null;
  children: React.ReactNode;
  /** Links under the card (another way in). */
  below?: React.ReactNode;
}) {
  return (
    <main className="flex min-h-svh flex-col items-center px-4 py-8 sm:justify-center sm:py-12" data-auth-step={step}>
      <div className="flex w-full max-w-[400px] flex-col">
        <FamilyBrand size="lg" className="mb-5" />

        <div className="rounded-2xl border border-border bg-surface-1 p-5 sm:p-7">
          {progress && (
            <p className="mb-3 text-xs font-medium text-subtle-foreground" data-auth-progress>
              {progress}
            </p>
          )}
          {children}
        </div>

        {below && <div className="mt-5 flex flex-col items-center gap-2 text-center text-sm text-muted-foreground">{below}</div>}

        <p className="mt-4 text-center text-xs leading-relaxed text-subtle-foreground">
          لا تشارك رمز التحقق أو كلمة المرور مع أي شخص.
        </p>
      </div>
    </main>
  );
}

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
      data-auth-alert={tone}
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
