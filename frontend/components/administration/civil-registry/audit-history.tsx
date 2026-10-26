"use client";

import { useState } from "react";
import Link from "next/link";
import { ScrollText } from "lucide-react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader, Panel } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { useCivilImportAudit } from "@/lib/api/civil-registry";
import type { CivilImportAuditEvent } from "@/lib/types/api/civil-registry";
import { CivilRegistryNav, LoadingBlock, MonitorError, Num, ShortId } from "@/components/administration/civil-registry/parts";
import {
  DETAIL_LABELS,
  EVENT_LABELS,
  ISSUE_LABELS,
  SCOPE_LABELS,
  dateLabel,
  label,
} from "@/components/administration/civil-registry/labels";

function Details({ details }: { details: CivilImportAuditEvent["details"] }) {
  const entries = Object.entries(details);
  if (entries.length === 0) return <>—</>;

  return (
    <ul className="flex flex-wrap gap-x-3 gap-y-0.5 text-[13px]">
      {entries.map(([key, value]) => (
        <li key={key}>
          <span className="text-muted-foreground">{label(DETAIL_LABELS, key)}:</span>{" "}
          {typeof value === "number" ? <Num value={value} /> : key === "scope" ? label(SCOPE_LABELS, value) : label(ISSUE_LABELS, value)}
        </li>
      ))}
    </ul>
  );
}

/** Civil import audit events, newest first; filtered to one run when `run` is set. Requires civil-import.audit.view. */
export function AuditTimeline({ run = null }: { run?: string | null }) {
  const [page, setPage] = useState(1);
  const audit = useCivilImportAudit(run, page);

  if (audit.isPending) {
    return (
      <div className="p-4">
        <LoadingBlock />
      </div>
    );
  }
  if (audit.isError) return <MonitorError error={audit.error} onRetry={() => audit.refetch()} />;
  if (audit.data.data.length === 0) {
    return <EmptyState icon={ScrollText} title="لا توجد أحداث تدقيق" description="لم يُسجَّل أي حدث يخص استيراد السجل المدني بعد." />;
  }

  return (
    <>
      <Table data-audit-table>
        <TableHeader>
          <TableRow>
            <TableHead>الوقت</TableHead>
            <TableHead>الحدث</TableHead>
            <TableHead>المنفّذ</TableHead>
            {!run && <TableHead>العملية</TableHead>}
            <TableHead>النتيجة</TableHead>
            <TableHead>التفاصيل</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {audit.data.data.map((event, i) => (
            <TableRow key={`${event.occurred_at}-${i}`} data-audit-event={event.event_type}>
              <TableCell>{dateLabel(event.occurred_at)}</TableCell>
              <TableCell>{label(EVENT_LABELS, event.event_type)}</TableCell>
              <TableCell>{event.actor.side === "SYSTEM" ? "النظام" : (event.actor.name ?? "—")}</TableCell>
              {!run && (
                <TableCell>
                  {event.run_id ? (
                    <Link href={`/administration/civil-registry/imports/${event.run_id}`} className="text-brand-700 hover:underline">
                      <ShortId id={event.run_id} />
                    </Link>
                  ) : (
                    "—"
                  )}
                </TableCell>
              )}
              <TableCell>
                {event.outcome_code ? (
                  <bdi dir="ltr" className="text-xs">
                    {event.outcome_code}
                  </bdi>
                ) : (
                  "—"
                )}
              </TableCell>
              <TableCell>
                <Details details={event.details} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <RegistryPagination meta={audit.data.meta} onPage={setPage} unit="حدث" />
    </>
  );
}

/** /administration/civil-registry/audit (U1, read-only). */
export function AuditHistory() {
  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="سجل التدقيق" description="أحداث استيراد السجل المدني، ومنها كل اطلاع على أسماء الملفات ومواقع الملاحظات." />
      <CivilRegistryNav />
      <Panel flush>
        <AuditTimeline />
      </Panel>
    </div>
  );
}
