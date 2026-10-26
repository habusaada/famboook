"use client";

import { useState } from "react";
import Link from "next/link";
import { History } from "lucide-react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader, Panel } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { cn } from "@/lib/utils";
import { useCivilImportRuns } from "@/lib/api/civil-registry";
import type { CivilImportRunStatus } from "@/lib/types/api/civil-registry";
import {
  CivilRegistryNav,
  LoadingBlock,
  MonitorError,
  Num,
  PhaseBadge,
  ReadOnlyNote,
  ShortId,
} from "@/components/administration/civil-registry/parts";
import { RUN_STATUS_LABELS, dateLabel } from "@/components/administration/civil-registry/labels";

const FILTERS: (CivilImportRunStatus | null)[] = [null, "RUNNING", "SUCCEEDED", "FAILED", "REJECTED", "NOTHING_TO_DO"];

/** /administration/civil-registry/imports — every import run, newest first (U1, read-only). */
export function ImportHistory() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<CivilImportRunStatus | null>(null);
  const runs = useCivilImportRuns(page, status);

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="سجل الاستيراد" description="عمليات استيراد مرجع الهوية المدنية: المرحلة والأعداد والملاحظات." />
      <CivilRegistryNav />
      <ReadOnlyNote />

      <div className="flex flex-wrap gap-1.5" role="group" aria-label="تصفية حسب الحالة">
        {FILTERS.map((f) => (
          <button
            key={f ?? "all"}
            type="button"
            aria-pressed={status === f}
            onClick={() => {
              setStatus(f);
              setPage(1);
            }}
            className={cn(
              "rounded-control border px-2.5 py-1 text-[13px] transition-colors",
              status === f ? "border-brand-600 bg-brand-50 font-semibold text-brand-700" : "border-stroke-subtle hover:bg-surface-hover",
            )}
          >
            {f ? RUN_STATUS_LABELS[f] : "الكل"}
          </button>
        ))}
      </div>

      <Panel flush>
        {runs.isPending ? (
          <div className="p-4">
            <LoadingBlock />
          </div>
        ) : runs.isError ? (
          <MonitorError error={runs.error} onRetry={() => runs.refetch()} />
        ) : runs.data.data.length === 0 ? (
          <EmptyState
            icon={History}
            title="لا توجد عمليات استيراد"
            description={status ? "لا توجد عمليات بهذه الحالة." : "لم يُنفَّذ أي استيراد للسجل المدني بعد."}
          />
        ) : (
          <>
            <Table data-runs-table>
              <TableHeader>
                <TableRow>
                  <TableHead>المعرّف</TableHead>
                  <TableHead>المرحلة</TableHead>
                  <TableHead>الملفات</TableHead>
                  <TableHead>السطور المحمّلة</TableHead>
                  <TableHead>الملاحظات</TableHead>
                  <TableHead>الإصدار</TableHead>
                  <TableHead>البدء</TableHead>
                  <TableHead>الانتهاء</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {runs.data.data.map((run) => (
                  <TableRow key={run.id} data-run={run.id}>
                    <TableCell>
                      <Link href={`/administration/civil-registry/imports/${run.id}`} className="text-brand-700 hover:underline">
                        <ShortId id={run.id} />
                      </Link>
                    </TableCell>
                    <TableCell>
                      <PhaseBadge phase={run.phase} />
                    </TableCell>
                    <TableCell>
                      <Num value={run.file_count} />
                    </TableCell>
                    <TableCell>
                      <Num value={run.rows_loaded} /> / <Num value={run.expected_rows} />
                    </TableCell>
                    <TableCell>
                      <Num value={run.issues} />
                    </TableCell>
                    <TableCell>{run.release_id ? <Num value={run.release_id} /> : "—"}</TableCell>
                    <TableCell>{dateLabel(run.started_at)}</TableCell>
                    <TableCell>{dateLabel(run.finished_at)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <RegistryPagination meta={runs.data.meta} onPage={setPage} unit="عملية" />
          </>
        )}
      </Panel>
    </div>
  );
}
