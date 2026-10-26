"use client";

import { useState } from "react";
import Link from "next/link";
import { Archive } from "lucide-react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader, Panel, SectionHeader, DetailList, DetailItem } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatusBadge } from "@/components/shared/status-badge";
import { useCivilImportRelease, useCivilImportReleases } from "@/lib/api/civil-registry";
import type { CivilImportCounts, CivilImportRelease } from "@/lib/types/api/civil-registry";
import { CivilRegistryNav, LoadingBlock, MonitorError, Num, ReadOnlyNote, ShortId } from "@/components/administration/civil-registry/parts";
import {
  COUNT_LABELS,
  ISSUE_LABELS,
  POLICY_LABELS,
  PREPARATION_STATUS_LABELS,
  RELEASE_STATUS_LABELS,
  RELEASE_STATUS_TONES,
  dateLabel,
  label,
} from "@/components/administration/civil-registry/labels";

function ReleaseBadges({ release }: { release: Pick<CivilImportRelease, "status" | "is_active"> }) {
  return (
    <span className="inline-flex flex-wrap gap-1.5">
      <StatusBadge tone={RELEASE_STATUS_TONES[release.status]}>{RELEASE_STATUS_LABELS[release.status]}</StatusBadge>
      {release.is_active && <StatusBadge tone="brand">نشط</StatusBadge>}
    </span>
  );
}

/** Comparison of the prepared release with its base (counts only). */
export function ComparisonCounts({ counts, warnings }: { counts: CivilImportCounts; warnings: Record<string, number> }) {
  const warningEntries = Object.entries(warnings);

  return (
    <div className="flex flex-col gap-4">
      <DetailList data-comparison-counts>
        {(Object.keys(COUNT_LABELS) as (keyof CivilImportCounts)[]).map((key) => (
          <DetailItem key={key} label={COUNT_LABELS[key]}>
            <Num value={counts[key]} />
          </DetailItem>
        ))}
      </DetailList>
      <div>
        <h3 className="mb-1.5 text-sm font-semibold">التحذيرات</h3>
        {warningEntries.length === 0 ? (
          <p className="text-[13px] text-muted-foreground">لا توجد تحذيرات.</p>
        ) : (
          <DetailList>
            {warningEntries.map(([code, n]) => (
              <DetailItem key={code} label={label(ISSUE_LABELS, code)}>
                <Num value={n} />
              </DetailItem>
            ))}
          </DetailList>
        )}
      </div>
    </div>
  );
}

/** One release: state, policy and preparation counts. */
export function ReleaseSummary({ release }: { release: CivilImportRelease }) {
  const p = release.preparation;

  return (
    <div className="flex flex-col gap-4">
      <DetailList>
        <DetailItem label="رقم الإصدار">
          <Num value={release.id} />
        </DetailItem>
        <DetailItem label="الحالة">
          <ReleaseBadges release={release} />
        </DetailItem>
        <DetailItem label="عملية الاستيراد">
          <Link href={`/administration/civil-registry/imports/${release.run_id}`} className="text-brand-700 hover:underline">
            <ShortId id={release.run_id} />
          </Link>
        </DetailItem>
        <DetailItem label="الإصدار الأساس">{release.base_release_id ? <Num value={release.base_release_id} /> : "—"}</DetailItem>
        <DetailItem label="عدد السجلات">
          <Num value={release.record_count} />
        </DetailItem>
        <DetailItem label="الإنشاء">{dateLabel(release.created_at)}</DetailItem>
        <DetailItem label="النشر">{dateLabel(release.published_at)}</DetailItem>
        {release.abandoned_at && <DetailItem label="الترك">{dateLabel(release.abandoned_at)}</DetailItem>}
      </DetailList>

      {p === null ? (
        <p className="text-[13px] text-muted-foreground">لم يبدأ تحضير هذا الإصدار بعد.</p>
      ) : (
        <>
          <SectionHeader title="التحضير والسياسة" />
          <DetailList>
            <DetailItem label="حالة التحضير">{label(PREPARATION_STATUS_LABELS, p.status)}</DetailItem>
            <DetailItem label="السياسة" ltr>
              {p.policy_id}
            </DetailItem>
            <DetailItem label="المقارنة">{label(POLICY_LABELS, p.comparison_policy)}</DetailItem>
            <DetailItem label="الحقول الفارغة">{label(POLICY_LABELS, p.blank_field_policy)}</DetailItem>
            <DetailItem label="الحجر">{label(POLICY_LABELS, p.quarantine)}</DetailItem>
            <DetailItem label="اكتمل التحضير">{dateLabel(p.prepared_at)}</DetailItem>
            <DetailItem label="اكتمل التحقق">{dateLabel(p.verified_at)}</DetailItem>
          </DetailList>
          <SectionHeader title="المقارنة بالإصدار الأساس" />
          <ComparisonCounts counts={p.counts} warnings={p.warnings} />
        </>
      )}
    </div>
  );
}

/** /administration/civil-registry/releases (U1, read-only). */
export function ReleaseHistory() {
  const [page, setPage] = useState(1);
  const releases = useCivilImportReleases(page);

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="الإصدارات" description="إصدارات مرجع الهوية المدنية: الحالة وعدد السجلات وسياسة التحضير." />
      <CivilRegistryNav />
      <ReadOnlyNote />
      <Panel flush>
        {releases.isPending ? (
          <div className="p-4">
            <LoadingBlock />
          </div>
        ) : releases.isError ? (
          <MonitorError error={releases.error} onRetry={() => releases.refetch()} />
        ) : releases.data.data.length === 0 ? (
          <EmptyState icon={Archive} title="لا توجد إصدارات" description="لم يُحضَّر أي إصدار لمرجع الهوية المدنية بعد." />
        ) : (
          <>
            <Table data-releases-table>
              <TableHeader>
                <TableRow>
                  <TableHead>الإصدار</TableHead>
                  <TableHead>الحالة</TableHead>
                  <TableHead>التحضير</TableHead>
                  <TableHead>عدد السجلات</TableHead>
                  <TableHead>الإصدار الأساس</TableHead>
                  <TableHead>النشر</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {releases.data.data.map((r) => (
                  <TableRow key={r.id} data-release={r.id}>
                    <TableCell>
                      <Link href={`/administration/civil-registry/releases/${r.id}`} className="text-brand-700 hover:underline">
                        <Num value={r.id} />
                      </Link>
                    </TableCell>
                    <TableCell>
                      <ReleaseBadges release={r} />
                    </TableCell>
                    <TableCell>{label(PREPARATION_STATUS_LABELS, r.preparation?.status)}</TableCell>
                    <TableCell>
                      <Num value={r.record_count} />
                    </TableCell>
                    <TableCell>{r.base_release_id ? <Num value={r.base_release_id} /> : "—"}</TableCell>
                    <TableCell>{dateLabel(r.published_at)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <RegistryPagination meta={releases.data.meta} onPage={setPage} unit="إصدار" />
          </>
        )}
      </Panel>
    </div>
  );
}

/** /administration/civil-registry/releases/[releaseId] (U1, read-only). */
export function ReleaseDetail({ releaseId }: { releaseId: string }) {
  const id = /^[1-9][0-9]{0,17}$/.test(releaseId) ? Number(releaseId) : null;
  const release = useCivilImportRelease(id);

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title={id ? `الإصدار ${id.toLocaleString("ar")}` : "الإصدار"} description="حالة الإصدار وسياسة تحضيره وأعداد المقارنة." />
      <CivilRegistryNav />
      <Panel>
        {id === null ? (
          <EmptyState icon={Archive} title="رقم الإصدار غير صالح" />
        ) : release.isPending ? (
          <LoadingBlock rows={6} />
        ) : release.isError ? (
          <MonitorError error={release.error} onRetry={() => release.refetch()} />
        ) : (
          <ReleaseSummary release={release.data} />
        )}
      </Panel>
    </div>
  );
}
