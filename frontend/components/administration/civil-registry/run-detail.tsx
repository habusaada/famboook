"use client";

import { useState } from "react";
import { Check, CircleDot, Eye, FileWarning, Loader2, MapPin } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { EmptyState } from "@/components/shared/empty-state";
import { ProgressBar } from "@/components/shared/meter";
import { PageHeader, Panel, SectionHeader, DetailList, DetailItem } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatusBadge } from "@/components/shared/status-badge";
import { useAuth } from "@/components/auth/auth-context";
import { cn } from "@/lib/utils";
import {
  ACTIVE_PHASES,
  useCivilImportIssueLocations,
  useCivilImportIssues,
  useCivilImportRelease,
  useCivilImportRun,
  useRevealFileLocations,
} from "@/lib/api/civil-registry";
import type { CivilImportPhase, CivilImportRunDetail } from "@/lib/types/api/civil-registry";
import { AuditTimeline } from "@/components/administration/civil-registry/audit-history";
import { ComparisonCounts, ReleaseSummary } from "@/components/administration/civil-registry/releases";
import {
  CivilRegistryNav,
  LoadingBlock,
  MonitorError,
  Num,
  PhaseBadge,
  ReadOnlyNote,
} from "@/components/administration/civil-registry/parts";
import {
  FILE_STATUS_LABELS,
  FILE_STATUS_TONES,
  ISSUE_LABELS,
  PHASE_LABELS,
  PHASE_STEPS,
  RUN_STATUS_LABELS,
  dateLabel,
  label,
} from "@/components/administration/civil-registry/labels";

type StepState = "done" | "active" | "current" | "waiting";

function stepStates(phase: CivilImportPhase): StepState[] | null {
  if (phase === "NOTHING_TO_DO") return PHASE_STEPS.map((_, i) => (i <= 1 ? "done" : "waiting"));
  const at = PHASE_STEPS.indexOf(phase);
  if (at < 0) return null;
  return PHASE_STEPS.map((_, i) =>
    i < at ? "done" : i > at ? "waiting" : ACTIVE_PHASES.has(phase) ? "active" : phase === "PUBLISHED" ? "done" : "current",
  );
}

/** Stage progression; an active stage is indeterminate (never a fake percentage). */
export function PhaseStepper({ phase }: { phase: CivilImportPhase }) {
  const states = stepStates(phase);

  return (
    <div className="flex flex-col gap-2">
      <ol className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" aria-label="مراحل الاستيراد" data-phase-stepper={phase}>
        {PHASE_STEPS.map((step, i) => {
          const state = states?.[i] ?? "waiting";
          return (
            <li
              key={step}
              data-step-state={state}
              aria-current={state === "current" || state === "active" ? "step" : undefined}
              className={cn(
                "flex items-center gap-2 rounded-md border px-3 py-2 text-sm",
                (state === "current" || state === "active") && "border-brand-600 bg-brand-50 font-semibold text-brand-700",
                state === "done" && "border-stroke-subtle text-foreground",
                state === "waiting" && "border-dashed text-subtle-foreground",
              )}
            >
              {state === "done" ? (
                <Check className="size-4 text-success" aria-hidden />
              ) : state === "active" ? (
                <Loader2 className="size-4 animate-spin" aria-hidden />
              ) : (
                <CircleDot className="size-4" aria-hidden />
              )}
              <span className="truncate">{PHASE_LABELS[step]}</span>
            </li>
          );
        })}
      </ol>
      {states === null && (
        <p className="text-[13px] text-muted-foreground">
          انتهت العملية دون نشر: <PhaseBadge phase={phase} />
        </p>
      )}
    </div>
  );
}

function OverviewTab({ run }: { run: CivilImportRunDetail }) {
  return (
    <div className="flex flex-col gap-4">
      <PhaseStepper phase={run.phase} />
      <DetailList>
        <DetailItem label="حالة العملية">{RUN_STATUS_LABELS[run.run_status]}</DetailItem>
        <DetailItem label="رمز الخطأ">{run.error_code ? <bdi dir="ltr">{run.error_code}</bdi> : "—"}</DetailItem>
        <DetailItem label="الجدول المصدر" ltr>
          {run.source_table}
        </DetailItem>
        <DetailItem label="عدد الملفات">
          <Num value={run.file_count} />
        </DetailItem>
        <DetailItem label="السطور المتوقعة">
          <Num value={run.expected_rows} />
        </DetailItem>
        <DetailItem label="السطور المحمّلة">
          <Num value={run.rows_loaded} />
        </DetailItem>
        <DetailItem label="الملاحظات">
          <Num value={run.issues} />
        </DetailItem>
        <DetailItem label="طلبها">{run.requested_by?.name ?? "—"}</DetailItem>
        <DetailItem label="البدء">{dateLabel(run.started_at)}</DetailItem>
        <DetailItem label="الانتهاء">{dateLabel(run.finished_at)}</DetailItem>
      </DetailList>
      <SectionHeader title="نتيجة التحميل" />
      <DetailList>
        <DetailItem label="سجلات جديدة">
          <Num value={run.counters.new_records} />
        </DetailItem>
        <DetailItem label="نسخ جديدة">
          <Num value={run.counters.new_versions} />
        </DetailItem>
        <DetailItem label="دون تغيير">
          <Num value={run.counters.unchanged} />
        </DetailItem>
        <DetailItem label="مرحّلة">
          <Num value={run.counters.carried_forward} />
        </DetailItem>
      </DetailList>
    </div>
  );
}

/** Files by ordinal; names appear only after an explicit, audited request (civil-import.locations.view). */
export function FileProgressTable({ run, canLocate }: { run: CivilImportRunDetail; canLocate: boolean }) {
  const reveal = useRevealFileLocations(run.id);
  const names = new Map((reveal.data ?? []).map((f) => [f.file_no, f.file_name]));

  if (run.files.length === 0) {
    return <EmptyState icon={FileWarning} title="لا توجد ملفات" description="لم تُسجَّل ملفات لهذه العملية بعد." />;
  }

  return (
    <div className="flex flex-col gap-3">
      {canLocate && (
        <div className="flex flex-wrap items-center gap-2" data-locations-panel="files">
          <Button
            variant="outline"
            size="sm"
            className="gap-1.5"
            disabled={reveal.isPending || reveal.isSuccess}
            onClick={() => reveal.mutate()}
          >
            <Eye className="size-4" />
            عرض أسماء الملفات
          </Button>
          <span className="text-[13px] text-muted-foreground">يُسجَّل كل اطلاع على أسماء الملفات في سجل التدقيق.</span>
          {reveal.isError && <MonitorError error={reveal.error} />}
        </div>
      )}
      <Table data-files-table>
        <TableHeader>
          <TableRow>
            <TableHead>الملف</TableHead>
            {reveal.isSuccess && <TableHead>الاسم</TableHead>}
            <TableHead>الحالة</TableHead>
            <TableHead>التقدم</TableHead>
            <TableHead>الدفعات</TableHead>
            <TableHead>البصمة</TableHead>
            <TableHead>آخر دفعة</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {run.files.map((f) => (
            <TableRow key={f.file_no} data-file-no={f.file_no}>
              <TableCell>
                <Num value={f.file_no} />
              </TableCell>
              {reveal.isSuccess && (
                <TableCell>
                  <bdi dir="ltr" className="font-mono text-xs" data-file-name>
                    {names.get(f.file_no) ?? "—"}
                  </bdi>
                </TableCell>
              )}
              <TableCell>
                <StatusBadge tone={FILE_STATUS_TONES[f.status]}>{FILE_STATUS_LABELS[f.status]}</StatusBadge>
              </TableCell>
              <TableCell className="min-w-40">
                <div className="flex flex-col gap-1">
                  <ProgressBar value={f.loaded_rows} total={f.expected_rows} label={`الملف ${f.file_no.toLocaleString("ar")}`} size="sm" />
                  <span className="text-xs text-muted-foreground">
                    <Num value={f.loaded_rows} /> / <Num value={f.expected_rows} />
                  </span>
                </div>
              </TableCell>
              <TableCell>
                <Num value={f.chunks} />
              </TableCell>
              <TableCell>{f.checksum_verified ? "متحقَّق منها" : "لم يُتحقَّق"}</TableCell>
              <TableCell>{dateLabel(f.last_chunk_at)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/** Issue file names and line numbers for one code (civil-import.locations.view, audited per page). */
function IssueLocations({ runId, code, onClose }: { runId: string; code: string; onClose: () => void }) {
  const [page, setPage] = useState(1);
  const locations = useCivilImportIssueLocations(runId, code, page);

  return (
    <Panel data-locations-panel="issues">
      <SectionHeader
        title={`مواقع: ${label(ISSUE_LABELS, code)}`}
        description="يُسجَّل كل اطلاع على مواقع الملاحظات في سجل التدقيق."
        action={
          <Button variant="ghost" size="sm" onClick={onClose}>
            إغلاق
          </Button>
        }
      />
      <div className="mt-3">
        {locations.isPending ? (
          <LoadingBlock />
        ) : locations.isError ? (
          <MonitorError error={locations.error} onRetry={() => locations.refetch()} />
        ) : locations.data.data.length === 0 ? (
          <EmptyState icon={MapPin} title="لا توجد مواقع" />
        ) : (
          <>
            <Table data-issue-locations-table>
              <TableHeader>
                <TableRow>
                  <TableHead>الملف</TableHead>
                  <TableHead>السطر</TableHead>
                  <TableHead>العمود</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {locations.data.data.map((l, i) => (
                  <TableRow key={`${l.file_name}-${l.line_number}-${i}`}>
                    <TableCell>
                      <bdi dir="ltr" className="font-mono text-xs">
                        {l.file_name}
                      </bdi>
                    </TableCell>
                    <TableCell>
                      <Num value={l.line_number} />
                    </TableCell>
                    <TableCell>{l.column ? <bdi dir="ltr">{l.column}</bdi> : "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <RegistryPagination
              meta={{
                ...locations.data.meta,
                path: "",
                from: (locations.data.meta.current_page - 1) * locations.data.meta.per_page + 1,
                to: (locations.data.meta.current_page - 1) * locations.data.meta.per_page + locations.data.data.length,
              }}
              onPage={setPage}
              unit="موقع"
            />
          </>
        )}
      </div>
    </Panel>
  );
}

/** Validation results by code and column (counts only). */
export function IssueSummary({ runId, canLocate }: { runId: string; canLocate: boolean }) {
  const issues = useCivilImportIssues(runId);
  const [locate, setLocate] = useState<string | null>(null);

  if (issues.isPending) return <LoadingBlock />;
  if (issues.isError) return <MonitorError error={issues.error} onRetry={() => issues.refetch()} />;
  if (issues.data.length === 0) {
    return <EmptyState icon={Check} title="لا توجد ملاحظات" description="لم يُسجَّل التحقق أي ملاحظة على هذه العملية." />;
  }

  return (
    <div className="flex flex-col gap-4">
      <Table data-issues-table>
        <TableHeader>
          <TableRow>
            <TableHead>الملاحظة</TableHead>
            <TableHead>العمود</TableHead>
            <TableHead>عدد السطور</TableHead>
            {canLocate && (
              <TableHead>
                <span className="sr-only">المواقع</span>
              </TableHead>
            )}
          </TableRow>
        </TableHeader>
        <TableBody>
          {issues.data.map((issue) => (
            <TableRow key={`${issue.code}-${issue.column ?? ""}`} data-issue={issue.code}>
              <TableCell>{label(ISSUE_LABELS, issue.code)}</TableCell>
              <TableCell>{issue.column ? <bdi dir="ltr">{issue.column}</bdi> : "—"}</TableCell>
              <TableCell>
                <Num value={issue.rows} />
              </TableCell>
              {canLocate && (
                <TableCell>
                  <Button variant="outline" size="sm" className="gap-1.5" onClick={() => setLocate(issue.code)}>
                    <MapPin className="size-4" />
                    المواقع
                  </Button>
                </TableCell>
              )}
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {canLocate && locate && <IssueLocations key={locate} runId={runId} code={locate} onClose={() => setLocate(null)} />}
    </div>
  );
}

function ReleaseTabs({ releaseId, view }: { releaseId: number | null; view: "comparison" | "release" }) {
  const release = useCivilImportRelease(releaseId);

  if (releaseId === null)
    return <EmptyState icon={FileWarning} title="لا يوجد إصدار لهذه العملية" description="يُنشأ الإصدار عند تحضير البيانات المحمّلة." />;
  if (release.isPending) return <LoadingBlock rows={5} />;
  if (release.isError) return <MonitorError error={release.error} onRetry={() => release.refetch()} />;
  if (view === "release") return <ReleaseSummary release={release.data} />;
  if (!release.data.preparation) return <EmptyState icon={FileWarning} title="لم يبدأ التحضير بعد" />;

  return <ComparisonCounts counts={release.data.preparation.counts} warnings={release.data.preparation.warnings} />;
}

/** /administration/civil-registry/imports/[runId] (U1, read-only: allowed_actions is always empty). */
export function RunDetail({ runId }: { runId: string }) {
  const { can } = useAuth();
  const run = useCivilImportRun(runId);
  const canLocate = can("civil-import.locations.view");
  const canAudit = can("civil-import.audit.view");

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="تفاصيل الاستيراد"
        description={
          <bdi dir="ltr" className="font-mono text-xs">
            {runId}
          </bdi>
        }
        actions={run.data ? <PhaseBadge phase={run.data.phase} /> : undefined}
      />
      <CivilRegistryNav />
      <ReadOnlyNote />

      {run.isPending ? (
        <LoadingBlock rows={6} />
      ) : run.isError ? (
        <MonitorError error={run.error} onRetry={() => run.refetch()} />
      ) : (
        <Tabs defaultValue="overview" dir="rtl">
          <TabsList className="flex-wrap">
            <TabsTrigger value="overview">نظرة عامة</TabsTrigger>
            <TabsTrigger value="files">الملفات ونقاط الاستئناف</TabsTrigger>
            <TabsTrigger value="validation">التحقق</TabsTrigger>
            <TabsTrigger value="comparison">المقارنة</TabsTrigger>
            <TabsTrigger value="release">الإصدار</TabsTrigger>
            {canAudit && <TabsTrigger value="audit">التدقيق</TabsTrigger>}
          </TabsList>
          <Panel className="mt-3">
            <TabsContent value="overview">
              <OverviewTab run={run.data} />
            </TabsContent>
            <TabsContent value="files">
              <FileProgressTable run={run.data} canLocate={canLocate} />
            </TabsContent>
            <TabsContent value="validation">
              <IssueSummary runId={run.data.id} canLocate={canLocate} />
            </TabsContent>
            <TabsContent value="comparison">
              <ReleaseTabs releaseId={run.data.release_id} view="comparison" />
            </TabsContent>
            <TabsContent value="release">
              <ReleaseTabs releaseId={run.data.release_id} view="release" />
            </TabsContent>
            {canAudit && (
              <TabsContent value="audit">
                <AuditTimeline run={run.data.id} />
              </TabsContent>
            )}
          </Panel>
        </Tabs>
      )}
    </div>
  );
}
