"use client";

import { useState } from "react";
import { ListChecks, Loader2, OctagonX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyState } from "@/components/shared/empty-state";
import { DetailItem, DetailList, Panel, SectionHeader } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatusBadge } from "@/components/shared/status-badge";
import { useCancelCivilImportRequest, useCivilImportRequests } from "@/lib/api/civil-registry";
import type { CivilImportRequest, CivilImportRequestResult } from "@/lib/types/api/civil-registry";
import { LoadingBlock, MonitorError, Num, ShortId } from "@/components/administration/civil-registry/parts";
import { CommandError } from "@/components/administration/civil-registry/run-actions";
import {
  COUNT_LABELS,
  FATAL_LABELS,
  ISSUE_LABELS,
  REQUEST_STATUS_LABELS,
  REQUEST_STATUS_TONES,
  REQUEST_TYPE_LABELS,
  SOURCE_PROBLEM_LABELS,
  dateLabel,
  label,
} from "@/components/administration/civil-registry/labels";

// FB-CIVIL-01A-UI U2 — execution requests: status, sanitized results (counts
// and codes only), cancellation. Nothing here executes anything: a request is
// executed by the dedicated worker.

export function RequestStatusBadge({ request }: { request: Pick<CivilImportRequest, "status"> }) {
  return (
    <span className="inline-flex" data-request-status={request.status}>
      <StatusBadge tone={REQUEST_STATUS_TONES[request.status]}>{REQUEST_STATUS_LABELS[request.status]}</StatusBadge>
    </span>
  );
}

function CodeCounts({
  title,
  codes,
  labels,
  empty,
}: {
  title: string;
  codes?: Record<string, number>;
  labels: Record<string, string>;
  empty: string;
}) {
  const entries = Object.entries(codes ?? {});
  return (
    <div className="flex flex-col gap-1.5">
      <h4 className="text-sm font-semibold">{title}</h4>
      {entries.length === 0 ? (
        <p className="text-[13px] text-muted-foreground">{empty}</p>
      ) : (
        <DetailList>
          {entries.map(([code, n]) => (
            <DetailItem key={code} label={label(labels, code)}>
              <Num value={n} />
            </DetailItem>
          ))}
        </DetailList>
      )}
    </div>
  );
}

function CodeList({ title, codes }: { title: string; codes?: string[] }) {
  if (!codes || codes.length === 0) return null;
  return (
    <div className="flex flex-col gap-1">
      <h4 className="text-sm font-semibold">{title}</h4>
      <ul className="list-inside list-disc text-[13px]">
        {codes.map((code) => (
          <li key={code}>{label(FATAL_LABELS, code)}</li>
        ))}
      </ul>
    </div>
  );
}

/** A request's result: what the worker reported, as counts and codes. */
export function RequestResultView({ request }: { request: CivilImportRequest }) {
  const r: CivilImportRequestResult = request.result ?? {};
  const counts = r.counts ?? {};
  const hasCounts = Object.keys(counts).length > 0;

  return (
    <div className="flex flex-col gap-4" data-request-result={request.type}>
      {request.message && (
        <p className={request.status === "FAILED" ? "text-sm text-danger" : "text-sm text-muted-foreground"} data-request-message>
          {request.message}
        </p>
      )}
      {(r.files != null || r.total_rows != null || r.rows_staged != null || r.release_id != null) && (
        <DetailList>
          {r.files != null && (
            <DetailItem label="الملفات">
              <Num value={r.files} />
            </DetailItem>
          )}
          {r.total_rows != null && (
            <DetailItem label="إجمالي السطور">
              <Num value={r.total_rows} />
            </DetailItem>
          )}
          {r.rows_staged != null && (
            <DetailItem label="السطور المحمّلة">
              <Num value={r.rows_staged} />
            </DetailItem>
          )}
          {r.resumed != null && <DetailItem label="استئناف">{r.resumed ? "نعم" : "لا"}</DetailItem>}
          {r.release_id != null && (
            <DetailItem label="الإصدار">
              <Num value={r.release_id} />
            </DetailItem>
          )}
          {r.publishable != null && <DetailItem label="قابل للتحضير">{r.publishable ? "نعم" : "لا"}</DetailItem>}
        </DetailList>
      )}
      {request.type === "VALIDATE_SOURCE" && (
        <CodeCounts title="نتيجة التحقق من الملفات" codes={r.problems} labels={SOURCE_PROBLEM_LABELS} empty="لا توجد ملاحظات." />
      )}
      {request.type !== "VALIDATE_SOURCE" && r.problems && Object.keys(r.problems).length > 0 && (
        <CodeCounts title="الملاحظات" codes={r.problems} labels={{ ...SOURCE_PROBLEM_LABELS, ...FATAL_LABELS }} empty="" />
      )}
      {request.type === "STAGE" && r.issues && (
        <CodeCounts title="ملاحظات المفاتيح" codes={r.issues} labels={ISSUE_LABELS} empty="لا توجد ملاحظات." />
      )}
      <CodeList title="مانع على مستوى الإصدار" codes={r.fatal} />
      <CodeList title="محظور بالسياسة" codes={r.blocked} />
      {hasCounts && (
        <div className="flex flex-col gap-1.5" data-comparison-preview>
          <h4 className="text-sm font-semibold">المقارنة بالإصدار النشط</h4>
          <DetailList>
            {Object.entries(COUNT_LABELS)
              .filter(([key]) => counts[key as keyof typeof counts] != null)
              .map(([key, text]) => (
                <DetailItem key={key} label={text}>
                  <Num value={counts[key as keyof typeof counts]} />
                </DetailItem>
              ))}
          </DetailList>
        </div>
      )}
      {(request.type === "ASSESS" || r.conflicts) && (
        <CodeCounts title="التعارضات والتكرارات" codes={r.conflicts} labels={ISSUE_LABELS} empty="لا توجد تعارضات." />
      )}
      {(request.type === "ASSESS" || request.type === "PREPARE" || request.type === "VERIFY") && (
        <CodeCounts title="التحذيرات" codes={r.warnings} labels={ISSUE_LABELS} empty="لا توجد تحذيرات." />
      )}
    </div>
  );
}

/** Cancel a request after an explicit confirmation. */
export function CancelRequestButton({ request }: { request: CivilImportRequest }) {
  const [open, setOpen] = useState(false);
  const cancel = useCancelCivilImportRequest();
  if (!request.can_cancel) return null;
  const running = request.status === "RUNNING";

  return (
    <>
      <Button variant="outline" size="sm" className="gap-1.5" onClick={() => setOpen(true)}>
        <OctagonX className="size-4" />
        {running ? "إيقاف التنفيذ" : "إلغاء الطلب"}
      </Button>
      {open && (
        <Dialog open onOpenChange={(next) => !next && setOpen(false)}>
          <DialogContent className="max-w-md" data-cancel-confirm>
            <DialogHeader>
              <DialogTitle>{running ? "إيقاف التنفيذ" : "إلغاء الطلب"}</DialogTitle>
              <DialogDescription>
                {running
                  ? "سيتوقف عامل الاستيراد عند آخر نقطة محفوظة. تبقى العملية مفتوحة ويمكن استئنافها أو إلغاؤها لاحقًا."
                  : "لن ينفّذ عامل الاستيراد هذا الطلب."}
              </DialogDescription>
            </DialogHeader>
            <CommandError error={cancel.error} />
            <DialogFooter className="gap-2">
              <Button variant="outline" onClick={() => setOpen(false)}>
                رجوع
              </Button>
              <Button
                variant="destructive"
                disabled={cancel.isPending}
                onClick={() => cancel.mutate(request.id, { onSuccess: () => setOpen(false) })}
              >
                {cancel.isPending && <Loader2 className="size-4 animate-spin" />}
                تأكيد
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </>
  );
}

/** The request the worker is running or about to run. */
export function ActiveRequestCard({ request }: { request: CivilImportRequest }) {
  return (
    <Panel data-active-request={request.id}>
      <SectionHeader
        title={`طلب جارٍ: ${REQUEST_TYPE_LABELS[request.type]}`}
        description="تُحدَّث الحالة تلقائيًا كل خمس ثوانٍ ما دامت الصفحة ظاهرة."
        action={<CancelRequestButton request={request} />}
      />
      <DetailList className="mt-3">
        <DetailItem label="الحالة">
          <RequestStatusBadge request={request} />
        </DetailItem>
        <DetailItem label="طلبه">{request.requested_by?.name ?? "—"}</DetailItem>
        <DetailItem label="في الانتظار منذ">{dateLabel(request.queued_at)}</DetailItem>
        <DetailItem label="بدأ">{dateLabel(request.started_at)}</DetailItem>
        <DetailItem label="آخر نبضة من العامل">{dateLabel(request.heartbeat_at)}</DetailItem>
        {request.source?.label && (
          <DetailItem label="المصدر" ltr>
            {request.source.label}
          </DetailItem>
        )}
      </DetailList>
      {request.cancel_requested && (
        <p className="mt-2 text-[13px] text-warning" data-cancel-requested>
          طُلب الإيقاف؛ سيتوقف العامل عند آخر نقطة محفوظة.
        </p>
      )}
    </Panel>
  );
}

/** Requests of a run (or all), newest first, with their results. */
export function RequestHistory({ runId = null }: { runId?: string | null }) {
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<string | null>(null);
  const requests = useCivilImportRequests(runId, page);

  if (requests.isPending) return <LoadingBlock />;
  if (requests.isError) return <MonitorError error={requests.error} onRetry={() => requests.refetch()} />;
  if (requests.data.data.length === 0) {
    return <EmptyState icon={ListChecks} title="لا توجد طلبات" description="لم يُسجَّل أي طلب تنفيذ لهذه العملية." />;
  }

  return (
    <div className="flex flex-col gap-3">
      <Table data-requests-table>
        <TableHeader>
          <TableRow>
            <TableHead>الطلب</TableHead>
            <TableHead>الحالة</TableHead>
            <TableHead>طلبه</TableHead>
            <TableHead>في الانتظار منذ</TableHead>
            <TableHead>الانتهاء</TableHead>
            <TableHead>
              <span className="sr-only">النتيجة</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {requests.data.data.map((r) => (
            <TableRow key={r.id} data-request={r.id}>
              <TableCell>
                {REQUEST_TYPE_LABELS[r.type]} <ShortId id={r.id} />
              </TableCell>
              <TableCell>
                <RequestStatusBadge request={r} />
              </TableCell>
              <TableCell>{r.requested_by?.name ?? "—"}</TableCell>
              <TableCell>{dateLabel(r.queued_at)}</TableCell>
              <TableCell>{dateLabel(r.finished_at)}</TableCell>
              <TableCell>
                <Button variant="ghost" size="sm" onClick={() => setOpen(open === r.id ? null : r.id)} aria-expanded={open === r.id}>
                  النتيجة
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {open && requests.data.data.find((r) => r.id === open) && (
        <Panel data-request-detail={open}>
          <RequestResultView request={requests.data.data.find((r) => r.id === open)!} />
        </Panel>
      )}
      <RegistryPagination meta={requests.data.meta} onPage={setPage} unit="طلب" />
    </div>
  );
}
