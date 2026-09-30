"use client";

import { AlertTriangle, Check, CircleDot, Loader2, Lock, X } from "lucide-react";
import { cn } from "@/lib/utils";
import { fmt } from "@/components/administration/import-wizard/labels";
import type { ImportCounts } from "@/lib/types/api/imports";

export type StepState = "completed" | "current" | "locked" | "available" | "warning" | "error";

export const STEP_TITLES = ["العشيرة", "ملف البيانات", "تعيين الأعمدة", "مفاتيح الأسر", "مراجعة البيانات", "المعاينة قبل الاستيراد"];

/** Six-step RTL stepper: completed / current / locked / warning / error. */
export function WizardStepper({
  current,
  states,
  onSelect,
}: {
  current: number;
  states: StepState[];
  onSelect: (step: number) => void;
}) {
  return (
    <ol className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" aria-label="خطوات الاستيراد">
      {STEP_TITLES.map((title, i) => {
        const step = i + 1;
        const state = step === current ? "current" : states[i];
        const locked = state === "locked";
        const Icon =
          state === "completed" ? Check : state === "warning" ? AlertTriangle : state === "error" ? X : locked ? Lock : CircleDot;
        return (
          <li key={title}>
            <button
              type="button"
              disabled={locked}
              onClick={() => onSelect(step)}
              aria-current={state === "current" ? "step" : undefined}
              data-step-state={state}
              className={cn(
                "flex w-full items-center gap-2 rounded-md border px-3 py-2 text-start text-sm transition-colors",
                state === "current" && "border-brand-600 bg-brand-50 font-semibold text-brand-700",
                state === "completed" && "border-stroke-subtle text-foreground hover:bg-surface-hover",
                state === "available" && "border-stroke-subtle text-foreground hover:bg-surface-hover",
                state === "warning" && "border-warning/40 bg-warning-soft text-warning",
                state === "error" && "border-danger/40 bg-danger-soft text-danger",
                locked && "cursor-not-allowed border-dashed text-subtle-foreground"
              )}
            >
              <span
                className={cn(
                  "flex size-6 shrink-0 items-center justify-center rounded-full text-xs tabular-nums",
                  state === "completed" ? "bg-success text-white" : "bg-surface-2"
                )}
              >
                {state === "completed" || state === "warning" || state === "error" || locked ? <Icon className="size-3.5" /> : fmt(step)}
              </span>
              <span className="truncate">{title}</span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}

export type ProcessState = "done" | "active" | "waiting";

/** Honest process checklist: an active item is indeterminate — never a fake percentage. */
export function ProcessList({ items }: { items: { label: string; state: ProcessState }[] }) {
  return (
    <ul className="flex flex-col gap-1.5 text-sm" data-process-list>
      {items.map((item) => (
        <li key={item.label} className="flex items-center gap-2" data-state={item.state}>
          {item.state === "done" ? (
            <Check className="size-4 text-success" aria-hidden />
          ) : item.state === "active" ? (
            <Loader2 className="size-4 animate-spin text-brand-600" aria-hidden />
          ) : (
            <span className="size-4 rounded-full border border-stroke-subtle" aria-hidden />
          )}
          <span className={item.state === "waiting" ? "text-muted-foreground" : "text-foreground"}>{item.label}</span>
        </li>
      ))}
    </ul>
  );
}

/** TOTAL / READY / NEEDS REVIEW / REJECTED — backend counts only. */
export function StagingCounts({ counts }: { counts: ImportCounts }) {
  const cards = [
    { label: "إجمالي الصفوف", value: counts.total, tone: "text-foreground" },
    { label: "جاهز", value: counts.ready, tone: "text-success" },
    { label: "يحتاج مراجعة", value: counts.needs_review, tone: counts.needs_review > 0 ? "text-warning" : "text-foreground" },
    { label: "مرفوض", value: counts.rejected, tone: counts.rejected > 0 ? "text-danger" : "text-foreground" },
  ];
  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-staging-counts>
      {cards.map((c) => (
        <div key={c.label} className="flex flex-col gap-1 rounded-md border border-stroke-subtle bg-surface-1 p-4">
          <span className="text-[13px] text-muted-foreground">{c.label}</span>
          <span className={cn("text-[28px] leading-8 font-bold tabular-nums", c.tone)}>{fmt(c.value)}</span>
        </div>
      ))}
    </div>
  );
}

export function NotAppliedNote() {
  return <p className="text-sm font-medium text-muted-foreground">لم يتم تطبيق البيانات على السجل بعد.</p>;
}
