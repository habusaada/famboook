"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { AlertCircle, ArrowRight } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader, Panel, SectionHeader } from "@/components/shared/page-layout";
import { useAuth } from "@/components/auth/auth-context";
import { useImportBatch, useImportBatches } from "@/lib/api/imports";
import { StepClan } from "@/components/administration/import-wizard/step-clan";
import { StepFile } from "@/components/administration/import-wizard/step-file";
import { StepMapping } from "@/components/administration/import-wizard/step-mapping";
import { StepReview } from "@/components/administration/import-wizard/step-review";
import { StepDryRun } from "@/components/administration/import-wizard/step-dry-run";
import { StepFamilyKeys } from "@/components/administration/import-wizard/step-family-keys";
import { STEP_TITLES, WizardStepper, type StepState } from "@/components/administration/import-wizard/wizard-parts";
import { BATCH_STATUS_LABELS, MODE_LABELS, fmt, isApplyStarted } from "@/components/administration/import-wizard/labels";
import type { ImportBatchDetail, ImportMode } from "@/lib/types/api/imports";

/**
 * Import Wizard (docs/03 §96a/§96b): 1 Clan + mode → 2 workbook → 3 column
 * mapping (confirming stages the rows) → 4 family keys → 5 review → 6 Dry Run,
 * then (only when the backend allows it) explicit confirmation and Apply.
 * The batch id lives in the URL (?batch=) so a refresh or reopen restores the
 * context from the backend; a step opens only when its prerequisites hold.
 * Once Apply has started, Step 6 is the persistent Apply status screen (it
 * stays reachable although Apply's own writes make reconciliation stale),
 * Steps 4–5 are read only and Steps 1–3 are closed.
 */
function reachable(step: number, batch: ImportBatchDetail | null, clanCode: string, mode: string): boolean {
  if (isApplyStarted(batch?.status)) return step >= 4;
  switch (step) {
    case 1:
      return true;
    case 2:
      return batch !== null || (clanCode !== "" && mode !== "");
    case 3:
      return batch?.worksheet_name != null;
    case 4:
      return batch?.staged === true;
    case 5:
      // Every distinct family key explicitly resolved (backend-authoritative).
      return batch?.staged === true && batch.summary.key_resolution.complete;
    default:
      // Step 6 additionally needs a CURRENT reconciliation (run and not stale).
      return batch?.staged === true && batch.summary.key_resolution.complete && batch.summary.reconciliation.state === "CURRENT";
  }
}

function stepStates(batch: ImportBatchDetail | null, clanCode: string, mode: string): StepState[] {
  return STEP_TITLES.map((_, i) => {
    const step = i + 1;
    if (!reachable(step, batch, clanCode, mode)) return "locked";
    const s = batch?.summary;
    switch (step) {
      case 1:
        return reachable(2, batch, clanCode, mode) ? "completed" : "available";
      case 2:
        return batch?.worksheet_name ? "completed" : batch ? "warning" : "available";
      case 3:
        return batch?.staged ? "completed" : "available";
      case 4:
        return s && s.key_resolution.unresolved_keys > 0 ? "warning" : "completed";
      case 5:
        if (!s) return "available";
        if (isApplyStarted(batch?.status)) return "completed";
        if (s.counts.rejected > 0 || (s.reconciliation.counts?.CONFLICT ?? 0) > 0) return "error";
        return s.reconciliation.state !== "CURRENT" || (s.reconciliation.requires_review ?? 0) > 0 || s.counts.needs_review > 0 ? "warning" : "completed";
      default:
        return batch?.status === "APPLIED" ? "completed" : batch?.status === "PARTIALLY_APPLIED" ? "error" : batch?.status === "APPLYING" ? "warning" : "available";
    }
  });
}

function RecentBatches({ onOpen }: { onOpen: (id: string) => void }) {
  const batches = useImportBatches();
  const list = batches.data?.data ?? [];
  if (list.length === 0) return null;

  return (
    <Panel className="flex flex-col gap-3">
      <SectionHeader title="دفعات سابقة" description="كل ملف مرفوع دفعة مستقلة تُحفظ في السجل التاريخي ولا تستبدل غيرها." />
      <ul className="flex flex-col divide-y divide-stroke-subtle text-sm">
        {list.slice(0, 10).map((b) => (
          <li key={b.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
            <Button variant="link" className="h-auto p-0 font-medium" onClick={() => onOpen(b.id)}>
              {b.source_filename}
            </Button>
            <span className="text-muted-foreground">{b.clan?.name}</span>
            <Badge variant="outline">{MODE_LABELS[b.import_mode].title}</Badge>
            <Badge variant="secondary">{BATCH_STATUS_LABELS[b.status]}</Badge>
            <span className="tabular-nums text-muted-foreground">{fmt(b.row_count)} صف</span>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

export function ImportWizard() {
  const { can } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const batchId = params.get("batch");
  const batchQuery = useImportBatch(batchId);
  const batch = batchQuery.data?.data ?? null;

  const [clanCode, setClanCode] = useState("");
  const [mode, setMode] = useState<ImportMode | "">("");
  const [step, setStep] = useState(1);
  const [openedFor, setOpenedFor] = useState<string | null>(null);

  // Reopening a batch restores its context at the furthest valid step
  // (adjusted during render when the opened batch changes — no effect). A
  // batch whose Apply has started always opens on Step 6.
  if (batch && openedFor !== batch.id) {
    setOpenedFor(batch.id);
    setStep(isApplyStarted(batch.status) ? 6 : batch.staged ? (batch.summary.key_resolution.complete ? 5 : 4) : batch.worksheet_name ? 3 : 2);
  }

  function openBatch(id: string) {
    setOpenedFor(null);
    router.replace(`${pathname}?batch=${id}`);
  }

  function newImport() {
    setClanCode("");
    setMode("");
    setOpenedFor(null);
    setStep(1);
    router.replace(pathname);
  }

  const states = stepStates(batch, clanCode, mode);
  const canGo = (s: number) => s >= 1 && s <= 6 && reachable(s, batch, clanCode, mode);
  // A step whose prerequisite was lost (e.g. mapping invalidated) falls back.
  const current = canGo(step) ? step : [6, 5, 4, 3, 2, 1].find((s) => canGo(s))!;

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-2">
        <Button asChild variant="ghost" className="w-fit gap-1.5 ps-2 text-muted-foreground">
          <Link href="/administration">
            <ArrowRight className="size-4" />
            العودة إلى الإدارة
          </Link>
        </Button>
        <PageHeader
          title="الاستيراد الأولي وتحديث بيانات الأسر"
          description="تجهيز ملفات أرباب الأسر ومراجعتها للعشيرة المختارة. لا يُطبَّق شيء على السجل إلا بعد المعاينة وتأكيد صريح."
          actions={batch ? <Button variant="outline" onClick={newImport}>بدء عملية استيراد جديدة</Button> : undefined}
        />
      </div>

      <WizardStepper current={current} states={states} onSelect={(s) => canGo(s) && setStep(s)} />

      {batchId && batchQuery.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : batchId && (batchQuery.error || !batch) ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر فتح الدفعة</AlertTitle>
          <AlertDescription className="flex flex-wrap items-center gap-2">
            قد لا تكون موجودة أو لا تملك صلاحية مراجعتها.
            <Button variant="link" className="h-auto p-0" onClick={newImport}>بدء عملية جديدة</Button>
          </AlertDescription>
        </Alert>
      ) : (
        <>
          {current === 1 && (
            <StepClan batch={batch} clanCode={clanCode} mode={mode} onClanChange={setClanCode} onModeChange={setMode} onNewImport={newImport} />
          )}
          {current === 2 && <StepFile batch={batch} clanCode={clanCode} mode={mode} onBatch={openBatch} />}
          {current === 3 && batch && <StepMapping batch={batch} />}
          {current === 4 && batch && <StepFamilyKeys batch={batch} />}
          {current === 5 && batch && <StepReview batch={batch} />}
          {current === 6 && batch && <StepDryRun batch={batch} />}

          <div className="flex items-center justify-between gap-2">
            <Button variant="outline" disabled={!canGo(current - 1)} onClick={() => setStep(current - 1)}>
              السابق
            </Button>
            {current < 6 && (
              <Button disabled={!canGo(current + 1)} onClick={() => setStep(current + 1)}>
                التالي
              </Button>
            )}
          </div>
        </>
      )}

      {can("import.review") && <RecentBatches onOpen={openBatch} />}
    </div>
  );
}
