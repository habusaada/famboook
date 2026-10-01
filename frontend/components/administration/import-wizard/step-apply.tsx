"use client";

import { useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, CheckCircle2, Info, PlayCircle } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { useAuth } from "@/components/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import { applyRequests, refreshApplyState, useApplyStatus } from "@/lib/api/imports";
import { applyErrorLabel, fmt } from "@/components/administration/import-wizard/labels";
import { ProcessList } from "@/components/administration/import-wizard/wizard-parts";
import type { ApplyErrorPayload, ApplyProgress, ImportBatchDetail } from "@/lib/types/api/imports";

/**
 * Step 6 Apply (docs/03 §96b): explicit confirmation, then the browser drives
 * the chunk loop (POST run while the outcome is PAUSED). The backend decides
 * everything — gate, permission, state, plan and chunk budget — and progress
 * is always the persisted database state. Closing the page simply stops new
 * requests (a pause); reopening rebuilds the screen from the backend.
 * The plan fingerprint is held in memory only, never stored or shown.
 */

export type ApplyFailure = { code: string; rowNumber: number | null };

// Request failures without a backend Apply code (never shown as raw text).
const CLIENT_FAILURE_LABELS: Record<string, string> = {
  FORBIDDEN: "لا تملك صلاحية تطبيق الاستيراد، أو أن التطبيق غير مفعّل حاليًا",
  REQUEST_FAILED: "تعذّر تنفيذ الطلب",
  NETWORK_ERROR: "تعذّر الاتصال بالخادم",
};

function failureLabel(code: string): string {
  return CLIENT_FAILURE_LABELS[code] ?? applyErrorLabel(code);
}

function failureOf(error: unknown): ApplyFailure {
  if (error instanceof ApiError) {
    const p = error.payload as Partial<ApplyErrorPayload> | null;
    if (p && typeof p === "object" && typeof p.code === "string") {
      return { code: p.code, rowNumber: typeof p.row_number === "number" ? p.row_number : null };
    }
    return { code: error.status === 403 ? "FORBIDDEN" : "REQUEST_FAILED", rowNumber: null };
  }
  return { code: "NETWORK_ERROR", rowNumber: null };
}

export function useApplyRunner(batchId: string) {
  const queryClient = useQueryClient();
  const [phase, setPhase] = useState<"idle" | "starting" | "running">("idle");
  const [pauseRequested, setPauseRequested] = useState(false);
  const [last, setLast] = useState<ApplyProgress | null>(null);
  // When `last` arrived — compared with the progress query's timestamp so the
  // screen always shows the most recent persisted state.
  const [lastAt, setLastAt] = useState(0);
  const [failure, setFailure] = useState<ApplyFailure | null>(null);
  // APPLY_IN_PROGRESS: another session holds the runner. This page then only
  // reads progress; it never retries the mutation by itself.
  const [elsewhere, setElsewhere] = useState(false);
  const pauseRef = useRef(false);
  const aliveRef = useRef(true);

  useEffect(() => {
    aliveRef.current = true;
    return () => {
      // Leaving the step stops issuing new chunk requests (like a pause).
      aliveRef.current = false;
    };
  }, []);

  async function drive(first: "run" | "resume") {
    pauseRef.current = false;
    setPauseRequested(false);
    setFailure(null);
    setElsewhere(false);
    setPhase("running");
    let request = first === "resume" ? applyRequests.resume : applyRequests.run;
    try {
      for (;;) {
        const res = await request(batchId);
        request = applyRequests.run;
        setLast(res.data);
        setLastAt(Date.now());
        refreshApplyState(queryClient, batchId, true);
        // Only a normal pause continues; COMPLETED, ALREADY_APPLIED and
        // FAILED (a 200 with the persisted error) end the loop.
        if (res.data.outcome !== "PAUSED" || pauseRef.current || !aliveRef.current) break;
      }
    } catch (error) {
      const f = failureOf(error);
      if (f.code === "APPLY_IN_PROGRESS") setElsewhere(true);
      else setFailure(f);
    } finally {
      setPhase("idle");
      setPauseRequested(false);
      refreshApplyState(queryClient, batchId);
    }
  }

  /** Start with the reviewed plan; returns the refusal, if any. */
  async function start(planFingerprint: string): Promise<ApplyFailure | null> {
    setFailure(null);
    setElsewhere(false);
    setPhase("starting");
    try {
      const res = await applyRequests.start(batchId, planFingerprint);
      setLast(res.data);
      setLastAt(Date.now());
    } catch (error) {
      const f = failureOf(error);
      setFailure(f);
      setPhase("idle");
      refreshApplyState(queryClient, batchId);
      return f;
    }
    await drive("run");
    return null;
  }

  /** Stop issuing chunk requests; a chunk already running finishes. */
  function pause() {
    pauseRef.current = true;
    setPauseRequested(true);
  }

  return { phase, pauseRequested, last, lastAt, failure, elsewhere, start, drive, pause };
}

export type ApplyRunner = ReturnType<typeof useApplyRunner>;

/** The approved confirmation wording; counts come from the Dry Run plan. */
export function ApplyConfirmDialog({ families, persons, onConfirm, onClose }: { families: number; persons: number; onConfirm: () => void; onClose: () => void }) {
  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg" data-apply-confirm>
        <DialogHeader>
          <DialogTitle>تأكيد بدء الاستيراد</DialogTitle>
          <DialogDescription className="leading-7">
            سيتم إنشاء {fmt(families)} أسرة و{fmt(persons)} أشخاص في السجل اعتمادًا على هذه المعاينة. بعد بدء الاستيراد قد يتم حفظ البيانات على دفعات، ولا يتوفر
            تراجع تلقائي عن السجلات التي تم إنشاؤها. هل تريد بدء الاستيراد؟
          </DialogDescription>
        </DialogHeader>
        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose}>إلغاء</Button>
          <Button onClick={onConfirm}>بدء الاستيراد</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function FailureText({ failure }: { failure: ApplyFailure }) {
  return (
    <span data-apply-error={failure.code}>
      {failureLabel(failure.code)}
      {failure.rowNumber !== null && ` — الصف ${fmt(failure.rowNumber)}`}
      <span className="ms-2 font-mono text-xs text-muted-foreground" dir="ltr">{failure.code}</span>
    </span>
  );
}

/** A refused request that is not (yet) the batch's persisted state. */
export function ApplyRequestFailure({ failure }: { failure: ApplyFailure }) {
  return (
    <Alert variant="destructive" data-apply-request-error>
      <AlertTriangle className="size-4" />
      <AlertTitle>تعذّر تنفيذ الطلب</AlertTitle>
      <AlertDescription>
        <FailureText failure={failure} />
      </AlertDescription>
    </Alert>
  );
}

/**
 * The persistent Apply screen once Apply has started (APPLYING,
 * PARTIALLY_APPLIED, APPLIED). Everything shown is read from the backend.
 */
export function ApplyStatusPanel({ batch, runner }: { batch: ImportBatchDetail; runner: ApplyRunner }) {
  const { can } = useAuth();
  const busy = runner.phase !== "idle";
  const knownStatus = runner.last?.status ?? batch.status;
  // While nothing runs here, keep reading persisted progress (another
  // session may be applying); never send a mutation by itself.
  const status = useApplyStatus(batch.id, true, !busy && knownStatus === "APPLYING" ? 4000 : false);
  const s = status.data?.data;
  // Both are persisted state; show the more recent one.
  const view = runner.last && (!s || runner.lastAt > status.dataUpdatedAt) ? runner.last : s;
  const current = view?.status ?? knownStatus;
  const canApply = can("import.apply");
  // The failing row number is only in POST responses (import.apply).
  const errorRow = runner.last && runner.last.error_code === view?.error_code ? runner.last.error_row_number : null;

  return (
    <Panel className="flex flex-col gap-4" data-section="apply" data-apply-status={current}>
      <SectionHeader icon={PlayCircle} title="تطبيق الدفعة على السجل" description="التقدم محسوب من البيانات المحفوظة في الخادم، وليس من المتصفح." />

      {view ? (
        <div className="flex flex-col gap-2 rounded-md border border-stroke-subtle p-3" data-apply-progress>
          <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
            <span>طُبّق <strong className="tabular-nums">{fmt(view.applied_rows)} / {fmt(view.total_rows)}</strong> صفًا</span>
            <span>متبقٍ <strong className="tabular-nums">{fmt(view.remaining_rows)}</strong></span>
            {runner.last?.pending_links != null && runner.last.pending_links > 0 && (
              <span>روابط معلّقة بين الصفوف <strong className="tabular-nums">{fmt(runner.last.pending_links)}</strong></span>
            )}
          </div>
          <div className="h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuemin={0} aria-valuemax={view.total_rows} aria-valuenow={view.applied_rows}>
            <div className="h-full bg-success" style={{ width: `${view.total_rows ? (view.applied_rows / view.total_rows) * 100 : 0}%` }} />
          </div>
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">جارٍ قراءة حالة التطبيق…</p>
      )}

      {current === "APPLYING" && busy && (
        <div className="flex flex-col gap-3">
          <ProcessList items={[{ label: runner.pauseRequested ? "سيتوقف التطبيق بعد انتهاء الدفعة الجارية معالجتها" : "تطبيق الصفوف على دفعات متتالية", state: "active" }]} />
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="outline" disabled={runner.pauseRequested} onClick={runner.pause}>إيقاف مؤقت</Button>
            <span className="text-xs text-muted-foreground">عند الإيقاف المؤقت قد تكتمل الدفعة الجارية معالجتها حاليًا قبل أن يسري الإيقاف.</span>
          </div>
        </div>
      )}

      {current === "APPLYING" && !busy && !runner.elsewhere && (
        <Alert data-apply-paused>
          <Info className="size-4" />
          <AlertTitle>التطبيق متوقف مؤقتًا</AlertTitle>
          <AlertDescription className="flex flex-col items-start gap-2">
            يمكن المتابعة من حيث توقف التطبيق؛ الصفوف المطبّقة لا تُعاد.
            {canApply && <Button size="sm" onClick={() => runner.drive("run")}>متابعة التطبيق</Button>}
          </AlertDescription>
        </Alert>
      )}

      {runner.elsewhere && (
        <Alert data-apply-elsewhere>
          <Info className="size-4" />
          <AlertTitle>التطبيق جارٍ في جلسة أخرى</AlertTitle>
          <AlertDescription className="flex flex-col items-start gap-2">
            يُعرض التقدم المحفوظ ويتحدّث تلقائيًا، ولن تُرسل هذه الصفحة طلبات تطبيق من تلقاء نفسها.
            {canApply && current === "APPLYING" && <Button size="sm" variant="outline" onClick={() => runner.drive("run")}>المتابعة من هذه الصفحة</Button>}
          </AlertDescription>
        </Alert>
      )}

      {current === "PARTIALLY_APPLIED" && (
        <Alert variant="destructive" data-apply-partial>
          <AlertTriangle className="size-4" />
          <AlertTitle>توقف التطبيق بعد حفظ جزء من الصفوف</AlertTitle>
          <AlertDescription className="flex flex-col items-start gap-2">
            {view?.error_code ? <FailureText failure={{ code: view.error_code, rowNumber: errorRow }} /> : null}
            <span>الصفوف المحفوظة تبقى كما هي. الاستئناف يتحقق أولًا من أن الخطة المعتمدة لم تتغير ثم يكمل الصفوف المتبقية فقط.</span>
            {canApply && !busy && <Button size="sm" onClick={() => runner.drive("resume")}>استئناف التطبيق</Button>}
          </AlertDescription>
        </Alert>
      )}

      {current === "APPLIED" && (
        <Alert data-apply-completed>
          <CheckCircle2 className="size-4 text-success" />
          <AlertTitle>اكتمل تطبيق الدفعة</AlertTitle>
          <AlertDescription>طُبّقت جميع الصفوف ({fmt(view?.applied_rows ?? 0)}) وتم التحقق من اكتمالها في الخادم.</AlertDescription>
        </Alert>
      )}

      {runner.failure && <ApplyRequestFailure failure={runner.failure} />}

      {current !== "APPLIED" && (
        <p className="text-xs text-muted-foreground">إغلاق الصفحة يوقف إرسال دفعات جديدة؛ عند العودة تُستعاد الحالة من الخادم ويمكن المتابعة.</p>
      )}
    </Panel>
  );
}
