"use client";

import Link from "next/link";
import { Activity, Archive, Cpu, ShieldCheck } from "lucide-react";
import { KpiCard } from "@/components/shared/kpi-card";
import { PageHeader, Panel, SectionHeader, DetailList, DetailItem } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { useCivilImportOverview } from "@/lib/api/civil-registry";
import type { CivilImportOverview } from "@/lib/types/api/civil-registry";
import {
  CivilRegistryNav,
  LoadingBlock,
  MonitorError,
  NewImportLink,
  Num,
  PhaseBadge,
  ReadOnlyNote,
  ShortId,
} from "@/components/administration/civil-registry/parts";
import { WORKER_LABELS, dateLabel, label } from "@/components/administration/civil-registry/labels";
import { ActiveRequestCard } from "@/components/administration/civil-registry/request-panel";

/** /administration/civil-registry — the state of civil identity imports at a glance (U1; U2: the active request and the new-import entry). */
export function CivilRegistryOverview() {
  const overview = useCivilImportOverview();

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="استيراد السجل المدني" description="متابعة استيراد مرجع الهوية المدنية وإصداراته." actions={<NewImportLink />} />
      <CivilRegistryNav />
      <ReadOnlyNote />

      {overview.isPending ? (
        <LoadingBlock rows={4} />
      ) : overview.isError ? (
        <MonitorError error={overview.error} onRetry={() => overview.refetch()} />
      ) : (
        <OverviewBody data={overview.data} />
      )}
    </div>
  );
}

function OverviewBody({ data }: { data: CivilImportOverview }) {
  return (
    <>
      {data.active_request && <ActiveRequestCard request={data.active_request} />}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard
          icon={Archive}
          tone="brand"
          label="الإصدار النشط"
          value={data.active_release ? <Num value={data.active_release.id} /> : "—"}
          context={
            data.active_release ? (
              <>
                <Num value={data.active_release.record_count} /> سجل · نُشر {dateLabel(data.active_release.published_at)}
              </>
            ) : (
              "لا يوجد إصدار منشور بعد."
            )
          }
        />
        <KpiCard
          icon={Activity}
          tone="info"
          label="الاستيراد الجاري"
          value={data.current_run ? <PhaseBadge phase={data.current_run.phase} /> : "لا يوجد"}
          context={data.current_run ? <>بدأ {dateLabel(data.current_run.started_at)}</> : "لا توجد عملية قيد التشغيل."}
        />
        <KpiCard
          icon={Cpu}
          tone="neutral"
          label="عامل الاستيراد"
          value={<span className="text-lg">{label(WORKER_LABELS, data.worker.state)}</span>}
        />
        <KpiCard
          icon={ShieldCheck}
          tone={data.publication_enabled ? "warning" : "success"}
          label="بوابة النشر"
          value={
            <span className="text-lg" data-publication-gate={data.publication_enabled ? "open" : "closed"}>
              {data.publication_enabled ? "مفتوحة" : "مغلقة"}
            </span>
          }
          context={
            <>
              طلبات نشر معلّقة: <Num value={data.pending_publication_requests} />
            </>
          }
        />
      </div>

      {data.current_run && (
        <Panel>
          <SectionHeader
            title="الاستيراد الجاري"
            description="تُحدَّث هذه الصفحة تلقائيًا كل خمس ثوانٍ أثناء التحميل أو التحضير، وما دامت الصفحة ظاهرة."
            action={
              <Link
                href={`/administration/civil-registry/imports/${data.current_run.id}`}
                className="text-sm font-medium text-brand-700 hover:underline"
              >
                التفاصيل
              </Link>
            }
          />
          <DetailList className="mt-3">
            <DetailItem label="المعرّف">
              <ShortId id={data.current_run.id} />
            </DetailItem>
            <DetailItem label="المرحلة">
              <PhaseBadge phase={data.current_run.phase} />
            </DetailItem>
          </DetailList>
        </Panel>
      )}

      {data.active_release && (
        <Panel>
          <SectionHeader
            title="الإصدار النشط"
            action={
              <Link
                href={`/administration/civil-registry/releases/${data.active_release.id}`}
                className="text-sm font-medium text-brand-700 hover:underline"
              >
                التفاصيل
              </Link>
            }
          />
          <DetailList className="mt-3">
            <DetailItem label="رقم الإصدار">
              <Num value={data.active_release.id} />
            </DetailItem>
            <DetailItem label="الحالة">
              <StatusBadge tone="brand">نشط</StatusBadge>
            </DetailItem>
            <DetailItem label="عدد السجلات">
              <Num value={data.active_release.record_count} />
            </DetailItem>
            <DetailItem label="تاريخ النشر">{dateLabel(data.active_release.published_at)}</DetailItem>
          </DetailList>
        </Panel>
      )}
    </>
  );
}
