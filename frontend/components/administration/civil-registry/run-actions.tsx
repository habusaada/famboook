"use client";

import { useState } from "react";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ApiError } from "@/lib/api/client";
import {
  civilImportErrorCode,
  newIdempotencyKey,
  useQueueCivilImportRequest,
  type CivilImportAbortReason,
  type CivilImportCommand,
} from "@/lib/api/civil-registry";
import type { CivilImportAction, CivilImportRunDetail } from "@/lib/types/api/civil-registry";
import { ABORT_REASON_LABELS, ACTION_CONFIRMATIONS, ACTION_LABELS } from "@/components/administration/civil-registry/labels";

// FB-CIVIL-01A-UI U2 — the operator's actions on a run. Each one RECORDS a
// request (the API answers 202); the dedicated worker executes it. Buttons
// follow allowed_actions (phase ∩ permissions) — a hint only: the API and the
// worker re-check everything. No publication action exists in U2.

/** Fixed Arabic text for a refused request, by stable code (never server text). */
const COMMAND_ERRORS: Record<string, string> = {
  PHASE_NOT_ALLOWED: "مرحلة الاستيراد الحالية لا تسمح بهذا الإجراء. حدّث الصفحة.",
  REQUEST_ALREADY_ACTIVE: "يوجد طلب تنفيذ آخر في الانتظار أو قيد التشغيل.",
  SOURCE_NOT_READY: "المصدر غير متاح أو ملف البيان غير صالح.",
  IDEMPOTENCY_KEY_REUSED: "تعذّر تسجيل الطلب؛ أعد المحاولة.",
  REQUEST_NOT_CANCELLABLE: "لا يمكن إلغاء هذا الطلب في حالته الحالية.",
  REQUEST_NOT_FOUND: "العنصر المطلوب غير موجود.",
  NOT_ALLOWED: "لا تملك صلاحية تنفيذ هذا الإجراء.",
  MONITOR_NOT_CONFIGURED: "لم تُضبط متابعة استيراد السجل المدني على هذا الخادم بعد.",
  MONITOR_UNAVAILABLE: "متابعة استيراد السجل المدني غير متاحة مؤقتًا.",
};

export function CommandError({ error }: { error: unknown }) {
  if (!error) return null;
  const code = civilImportErrorCode(error);
  const status = error instanceof ApiError ? error.status : null;
  const text = (code && COMMAND_ERRORS[code]) ?? (status === 403 ? COMMAND_ERRORS.NOT_ALLOWED : "تعذّر تسجيل الطلب.");

  return (
    <p className="text-sm text-danger" role="alert" data-command-error={code ?? status ?? "unknown"}>
      {text}
    </p>
  );
}

function commandFor(action: CivilImportAction, run: CivilImportRunDetail, reason: CivilImportAbortReason): CivilImportCommand | null {
  switch (action) {
    case "STAGE":
      return run.source ? { kind: "STAGE", sourceId: run.source.id } : null;
    case "ABORT":
      return { kind: "ABORT", runId: run.id, reasonCode: reason };
    case "ASSESS":
      return { kind: "ASSESS", runId: run.id };
    case "PREPARE":
      return { kind: "PREPARE", runId: run.id };
    case "VERIFY":
      return run.release ? { kind: "VERIFY", releaseId: run.release.id } : null;
  }
}

function ConfirmAction({ action, run, onClose }: { action: CivilImportAction; run: CivilImportRunDetail; onClose: () => void }) {
  const queue = useQueueCivilImportRequest();
  // One key per confirmation: a retried submission never queues a second request.
  const [key] = useState(newIdempotencyKey);
  const [reason, setReason] = useState<CivilImportAbortReason | null>(null);
  const [acknowledged, setAcknowledged] = useState(false);
  const abort = action === "ABORT";
  const command = commandFor(action, run, reason ?? "OPERATOR_DECISION");
  const ready = command !== null && (!abort || (reason !== null && acknowledged));

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-w-lg" data-action-confirm={action}>
        <DialogHeader>
          <DialogTitle>{ACTION_LABELS[action]}</DialogTitle>
          <DialogDescription>{ACTION_CONFIRMATIONS[action]}</DialogDescription>
        </DialogHeader>
        {abort && (
          <div className="flex flex-col gap-3">
            <fieldset className="flex flex-col gap-1.5">
              <legend className="mb-1 text-sm font-medium">سبب الإلغاء</legend>
              {(Object.keys(ABORT_REASON_LABELS) as CivilImportAbortReason[]).map((code) => (
                <label key={code} className="flex items-center gap-2 text-sm">
                  <input
                    type="radio"
                    name="abort-reason"
                    className="size-4 accent-brand-600"
                    checked={reason === code}
                    onChange={() => setReason(code)}
                  />
                  {ABORT_REASON_LABELS[code]}
                </label>
              ))}
            </fieldset>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4 accent-brand-600"
                checked={acknowledged}
                onChange={(e) => setAcknowledged(e.target.checked)}
              />
              أفهم أن إلغاء العملية نهائي ولا يمكن التراجع عنه.
            </label>
          </div>
        )}
        <p className="text-[13px] text-muted-foreground">يُسجَّل الطلب وينفّذه عامل الاستيراد المخصص؛ تابع حالته في هذه الصفحة.</p>
        <CommandError error={queue.error} />
        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose}>
            رجوع
          </Button>
          <Button
            variant={abort ? "destructive" : "default"}
            disabled={!ready || queue.isPending}
            onClick={() => command && queue.mutate({ command, idempotencyKey: key }, { onSuccess: onClose })}
          >
            {queue.isPending && <Loader2 className="size-4 animate-spin" />}
            تأكيد
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** The actions allowed now, each behind a confirmation. Renders nothing when none is allowed. */
export function RunActions({ run }: { run: CivilImportRunDetail }) {
  const [confirming, setConfirming] = useState<CivilImportAction | null>(null);
  const actions = run.allowed_actions.filter((a) => commandFor(a, run, "OPERATOR_DECISION") !== null);
  if (actions.length === 0) return null;

  return (
    <div className="flex flex-wrap items-center gap-2" data-run-actions>
      {actions.map((action) => (
        <Button
          key={action}
          variant={action === "ABORT" ? "destructive" : "outline"}
          size="sm"
          onClick={() => setConfirming(action)}
          data-action={action}
        >
          {ACTION_LABELS[action]}
        </Button>
      ))}
      {confirming && <ConfirmAction action={confirming} run={run} onClose={() => setConfirming(null)} />}
    </div>
  );
}
