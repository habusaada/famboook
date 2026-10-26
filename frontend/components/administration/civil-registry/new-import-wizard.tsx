"use client";

import { useState } from "react";
import Link from "next/link";
import { Check, FolderSearch, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { DetailItem, DetailList, PageHeader, Panel, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { cn } from "@/lib/utils";
import { newIdempotencyKey, useCivilImportRequest, useCivilImportSources, useQueueCivilImportRequest } from "@/lib/api/civil-registry";
import type { CivilImportSource } from "@/lib/types/api/civil-registry";
import { CivilRegistryNav, LoadingBlock, MonitorError, Num, ReadOnlyNote } from "@/components/administration/civil-registry/parts";
import { CancelRequestButton, RequestResultView, RequestStatusBadge } from "@/components/administration/civil-registry/request-panel";
import { CommandError } from "@/components/administration/civil-registry/run-actions";
import { SOURCE_PROBLEM_LABELS, dateLabel, label } from "@/components/administration/civil-registry/labels";

// FB-CIVIL-01A-UI U2 — the new-import wizard (API-and-screens §5): choose a
// catalogued server-side source (D-UI-3: no upload, no path), validate its
// manifest and files, confirm, and follow the staging. Each step RECORDS a
// request that the dedicated worker executes.

const STEPS = ["اختيار المصدر", "التحقق من البيان والملفات", "التأكيد والتحميل", "المتابعة"];

function Stepper({ step }: { step: number }) {
  return (
    <ol className="grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="خطوات الاستيراد الجديد" data-wizard-step={step}>
      {STEPS.map((title, i) => (
        <li
          key={title}
          aria-current={i === step ? "step" : undefined}
          className={cn(
            "flex items-center gap-2 rounded-md border px-3 py-2 text-sm",
            i === step && "border-brand-600 bg-brand-50 font-semibold text-brand-700",
            i < step && "border-stroke-subtle text-foreground",
            i > step && "border-dashed text-subtle-foreground",
          )}
        >
          <span
            className={cn(
              "flex size-6 shrink-0 items-center justify-center rounded-full text-xs",
              i < step ? "bg-success text-white" : "bg-surface-2",
            )}
          >
            {i < step ? <Check className="size-3.5" /> : (i + 1).toLocaleString("ar")}
          </span>
          <span className="truncate">{title}</span>
        </li>
      ))}
    </ol>
  );
}

function SourceChoice({ source, selected, onSelect }: { source: CivilImportSource; selected: boolean; onSelect: () => void }) {
  const ready = source.available && source.manifest_status === "VALID";
  const problems = Object.entries(source.manifest_problems ?? {});

  return (
    <label
      className={cn(
        "flex cursor-pointer flex-col gap-2 rounded-lg border p-3",
        selected ? "border-brand-600 bg-brand-50" : "border-stroke-subtle",
        !ready && "cursor-not-allowed opacity-70",
      )}
      data-source={source.id}
    >
      <span className="flex items-center gap-2">
        <input type="radio" name="source" className="size-4 accent-brand-600" disabled={!ready} checked={selected} onChange={onSelect} />
        <bdi dir="ltr" className="font-mono text-sm font-semibold">
          {source.label}
        </bdi>
        <span className="ms-auto">
          {!source.available ? (
            <StatusBadge tone="neutral">غير متاح</StatusBadge>
          ) : ready ? (
            <StatusBadge tone="success">ملف البيان صالح</StatusBadge>
          ) : (
            <StatusBadge tone="danger">ملف البيان غير صالح</StatusBadge>
          )}
        </span>
      </span>
      <span className="text-[13px] text-muted-foreground">
        {source.files != null ? (
          <>
            <Num value={source.files} /> ملف · <Num value={source.total_rows} /> سطر · آخر فهرسة {dateLabel(source.last_seen_at)}
          </>
        ) : (
          <>آخر فهرسة {dateLabel(source.last_seen_at)}</>
        )}
        {source.staged_run_id && " · سبق تحميله"}
      </span>
      {problems.length > 0 && (
        <ul className="list-inside list-disc text-[13px] text-danger">
          {problems.map(([code, n]) => (
            <li key={code}>
              {label(SOURCE_PROBLEM_LABELS, code)} (<Num value={n} />)
            </li>
          ))}
        </ul>
      )}
    </label>
  );
}

/** /administration/civil-registry/imports/new (civil-import.stage). */
export function NewImportWizard() {
  const [step, setStep] = useState(0);
  const [sourceId, setSourceId] = useState<string | null>(null);
  const [validationId, setValidationId] = useState<string | null>(null);
  const [stageId, setStageId] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [checked, setChecked] = useState(false);
  // One key per wizard: retried submissions never queue twice.
  const [keys] = useState(() => ({ validate: newIdempotencyKey(), stage: newIdempotencyKey() }));

  const sources = useCivilImportSources();
  const queue = useQueueCivilImportRequest();
  const validation = useCivilImportRequest(validationId);
  const staging = useCivilImportRequest(stageId);
  const source = sources.data?.find((s) => s.id === sourceId) ?? null;
  const validated = validation.data?.status === "SUCCEEDED" || (validationId === null && source?.last_validation?.status === "SUCCEEDED");

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="استيراد جديد للسجل المدني" description="من مصدر وضعه مشغّل مخوّل على الخادم؛ لا يُرفع أي ملف من المتصفح." />
      <CivilRegistryNav />
      <ReadOnlyNote />
      <Stepper step={step} />

      {step === 0 && (
        <Panel>
          <SectionHeader title="اختيار المصدر" description="المصادر التي فهرسها عامل الاستيراد في مجلد المصادر على الخادم." />
          <div className="mt-3">
            {sources.isPending ? (
              <LoadingBlock />
            ) : sources.isError ? (
              <MonitorError error={sources.error} onRetry={() => sources.refetch()} />
            ) : sources.data.length === 0 ? (
              <EmptyState
                icon={FolderSearch}
                title="لا توجد مصادر مفهرسة"
                description="يضع المشغّل مجلد التصدير في مجلد المصادر على الخادم، ثم يفهرسه عامل الاستيراد تلقائيًا."
              />
            ) : (
              <div className="flex flex-col gap-2" role="radiogroup" aria-label="المصادر">
                {sources.data.map((s) => (
                  <SourceChoice key={s.id} source={s} selected={s.id === sourceId} onSelect={() => setSourceId(s.id)} />
                ))}
              </div>
            )}
          </div>
          <div className="mt-4 flex justify-end">
            <Button disabled={source === null} onClick={() => setStep(1)}>
              التالي
            </Button>
          </div>
        </Panel>
      )}

      {step === 1 && source && (
        <Panel>
          <SectionHeader
            title="التحقق من البيان والملفات"
            description="يتحقق عامل الاستيراد من الترميز والترويسة والبنية وعدد السطور وبصمة كل ملف، دون كتابة أي شيء."
          />
          <DetailList className="mt-3">
            <DetailItem label="المصدر" ltr>
              {source.label}
            </DetailItem>
            <DetailItem label="الجدول" ltr>
              {source.table ?? "—"}
            </DetailItem>
            <DetailItem label="الملفات">
              <Num value={source.files} />
            </DetailItem>
            <DetailItem label="إجمالي السطور">
              <Num value={source.total_rows} />
            </DetailItem>
          </DetailList>
          <div className="mt-4 flex flex-col gap-3">
            {validation.data ? (
              <div className="flex flex-col gap-3" data-validation-request>
                <div className="flex items-center gap-2">
                  <RequestStatusBadge request={validation.data} />
                  <CancelRequestButton request={validation.data} />
                </div>
                <RequestResultView request={validation.data} />
              </div>
            ) : (
              source.last_validation?.status === "SUCCEEDED" && (
                <p className="text-sm text-success" data-previous-validation>
                  سبق التحقق من هذا المصدر بنجاح ({dateLabel(source.last_validation.finished_at)}).
                </p>
              )
            )}
            <CommandError error={queue.error} />
            <div className="flex flex-wrap justify-between gap-2">
              <Button variant="outline" onClick={() => setStep(0)}>
                السابق
              </Button>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  disabled={queue.isPending || validation.data?.status === "QUEUED" || validation.data?.status === "RUNNING"}
                  onClick={() =>
                    queue.mutate(
                      { command: { kind: "VALIDATE_SOURCE", sourceId: source.id }, idempotencyKey: keys.validate },
                      { onSuccess: (request) => setValidationId(request.id) },
                    )
                  }
                >
                  {queue.isPending && <Loader2 className="size-4 animate-spin" />}
                  التحقق من الملفات
                </Button>
                <Button disabled={!validated} onClick={() => setStep(2)}>
                  التالي
                </Button>
              </div>
            </div>
          </div>
        </Panel>
      )}

      {step === 2 && source && (
        <Panel>
          <SectionHeader
            title="التأكيد والتحميل"
            description="يحمّل عامل الاستيراد الملفات إلى منطقة التحميل على دفعات قابلة للاستئناف. لا يتغير الإصدار النشط ولا يُنشر شيء."
          />
          <label className="mt-3 flex items-center gap-2 text-sm">
            <input type="checkbox" className="size-4 accent-brand-600" checked={checked} onChange={(e) => setChecked(e.target.checked)} />
            تحققت من أن هذا هو المصدر المعتمد للتحميل.
          </label>
          <div className="mt-4 flex justify-between gap-2">
            <Button variant="outline" onClick={() => setStep(1)}>
              السابق
            </Button>
            <Button disabled={!checked} onClick={() => setConfirming(true)}>
              بدء التحميل
            </Button>
          </div>
          {confirming && (
            <Dialog open onOpenChange={(next) => !next && setConfirming(false)}>
              <DialogContent className="max-w-md" data-stage-confirm>
                <DialogHeader>
                  <DialogTitle>بدء التحميل</DialogTitle>
                  <DialogDescription>
                    سيُسجَّل طلب تحميل المصدر <bdi dir="ltr">{source.label}</bdi> (<Num value={source.files} /> ملف،{" "}
                    <Num value={source.total_rows} /> سطر)، وينفّذه عامل الاستيراد.
                  </DialogDescription>
                </DialogHeader>
                <CommandError error={queue.error} />
                <DialogFooter className="gap-2">
                  <Button variant="outline" onClick={() => setConfirming(false)}>
                    رجوع
                  </Button>
                  <Button
                    disabled={queue.isPending}
                    onClick={() =>
                      queue.mutate(
                        { command: { kind: "STAGE", sourceId: source.id }, idempotencyKey: keys.stage },
                        {
                          onSuccess: (request) => {
                            setStageId(request.id);
                            setConfirming(false);
                            setStep(3);
                          },
                        },
                      )
                    }
                  >
                    {queue.isPending && <Loader2 className="size-4 animate-spin" />}
                    تأكيد
                  </Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>
          )}
        </Panel>
      )}

      {step === 3 && (
        <Panel>
          <SectionHeader title="المتابعة" description="تُحدَّث الحالة تلقائيًا كل خمس ثوانٍ ما دام الطلب نشطًا والصفحة ظاهرة." />
          <div className="mt-3">
            {staging.isPending ? (
              <LoadingBlock />
            ) : staging.isError ? (
              <MonitorError error={staging.error} onRetry={() => staging.refetch()} />
            ) : (
              <div className="flex flex-col gap-3" data-stage-request>
                <div className="flex flex-wrap items-center gap-2">
                  <RequestStatusBadge request={staging.data} />
                  <CancelRequestButton request={staging.data} />
                  {staging.data.run_id && (
                    <Link
                      href={`/administration/civil-registry/imports/${staging.data.run_id}`}
                      className="ms-auto text-sm font-medium text-brand-700 hover:underline"
                    >
                      عرض العملية
                    </Link>
                  )}
                </div>
                <RequestResultView request={staging.data} />
              </div>
            )}
          </div>
        </Panel>
      )}
    </div>
  );
}
