"use client";

import { useState } from "react";
import { AlertCircle, Filter, HeartHandshake, Lock, SlidersHorizontal, Tent, UserRound, Users, UsersRound } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import {
  DashboardScopeFilters,
  type DashboardScopeValue,
} from "@/components/dashboard/dashboard-scope-filters";
import {
  AssessmentsSection,
  AssistanceSection,
  DemographicsSection,
  DisplacementSection,
  HealthSection,
  NeedsSection,
  RecentActivitySection,
  UnavailableSection,
  fmt,
  needPriorityTone,
} from "@/components/dashboard/dashboard-sections";
import { PageHeader } from "@/components/shared/page-layout";
import { AppCard } from "@/components/shared/app-card";
import { KpiCard } from "@/components/shared/kpi-card";
import { IconBox, type Tone } from "@/components/shared/icon-box";
import { ProgressBar, SegmentedBar, share } from "@/components/shared/meter";
import { EmptyState } from "@/components/shared/empty-state";
import { ApiError } from "@/lib/api/client";
import { useDashboard, useDashboardScopeOptions } from "@/lib/api/dashboard";
import type { DashboardData } from "@/lib/types/api/dashboard";
import { needPriorityLabels } from "@/lib/utils/need";

function errorMessage(error: unknown): string {
  if (error instanceof ApiError && error.status === 403) return "لا تملك صلاحية عرض لوحة التحكم التشغيلية.";
  if (error instanceof ApiError && error.status === 422) return error.message422 ?? "النطاق المحدد غير صالح.";
  return "تعذّر تحميل بيانات لوحة التحكم. الرجاء المحاولة مرة أخرى.";
}

function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-5" aria-busy="true">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-40 w-full rounded-widget" />
        ))}
      </div>
      <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
        <Skeleton className="h-80 w-full rounded-widget xl:col-span-8" />
        <Skeleton className="h-80 w-full rounded-widget xl:col-span-4" />
      </div>
    </div>
  );
}

function Section<T>({ title, data, render }: { title: string; data: T | null; render: (data: T) => React.ReactNode }) {
  return data === null ? <UnavailableSection title={title} /> : <>{render(data)}</>;
}

const locked = <Lock className="size-6 text-subtle-foreground" aria-label="غير متاح" />;
const unavailable = "غير متاح لصلاحياتك";

/** KPI cards. Secondary lines are derived only from data already on this response. */
function Kpis({ data }: { data: DashboardData }) {
  const { kpis, demographics, displacement, needs } = data;
  const value = (n: number | null) => (n === null ? locked : fmt(n));
  const urgent = needs?.by_priority.find((p) => p.priority === "URGENT")?.count ?? 0;

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
      <KpiCard
        icon={Users}
        label="الأسر النشطة"
        value={value(kpis.active_families)}
        href="/families"
        context={kpis.active_families === null ? unavailable : "أسر بحالة نشطة ضمن النطاق"}
        secondary={
          needs && kpis.active_families ? (
            <div className="flex flex-col gap-1.5">
              <ProgressBar size="sm" tone="danger" value={needs.families_with_open_needs} total={kpis.active_families} label="أسر لديها احتياجات مفتوحة" />
              <p className="text-xs text-muted-foreground">
                <span className="font-semibold text-foreground tabular-nums">{fmt(needs.families_with_open_needs)}</span> منها لديها احتياجات مفتوحة
              </p>
            </div>
          ) : undefined
        }
      />
      {/* Declared ≠ registered: two separate figures, never summed or reconciled. */}
      <KpiCard
        icon={UsersRound}
        label="أفراد الأسر (المعلن)"
        value={value(kpis.declared_household_population)}
        href="/families"
        context={kpis.declared_household_population === null ? unavailable : "مجموع أحجام الأسر المعلنة حاليًا"}
      />
      <KpiCard
        icon={UserRound}
        tone="info"
        label="المسجلون تفصيليًا"
        value={value(kpis.current_people)}
        href="/people"
        context={kpis.current_people === null ? unavailable : "أحياء بعضوية نشطة في هذه الأسر"}
        secondary={
          demographics && demographics.total > 0 ? (
            <div className="flex flex-col gap-1.5">
              <SegmentedBar
                size="sm"
                label="الجنس"
                segments={[
                  { key: "m", label: "ذكور", value: demographics.gender.male, className: "bg-brand-700" },
                  { key: "f", label: "إناث", value: demographics.gender.female, className: "bg-brand-500" },
                  { key: "u", label: "غير محدد", value: demographics.gender.unknown, tone: "neutral" },
                ]}
              />
              <p className="text-xs text-muted-foreground tabular-nums">
                <span className="font-semibold text-foreground">{fmt(demographics.gender.male)}</span> ذكور ·{" "}
                <span className="font-semibold text-foreground">{fmt(demographics.gender.female)}</span> إناث
              </p>
            </div>
          ) : undefined
        }
      />
      <KpiCard
        icon={Tent}
        tone="warning"
        label="الأسر النازحة"
        value={value(kpis.displaced_families)}
        href="/families"
        context={kpis.displaced_families === null ? unavailable : "حسب السكن الحالي"}
        secondary={
          displacement && displacement.total_families > 0 ? (
            <div className="flex flex-col gap-1.5">
              <ProgressBar size="sm" tone="warning" value={displacement.displaced} total={displacement.total_families} label="نسبة الأسر النازحة" />
              <p className="text-xs text-muted-foreground">
                <span className="font-semibold text-foreground tabular-nums">{fmt(share(displacement.displaced, displacement.total_families))}٪</span> من الأسر النشطة
              </p>
            </div>
          ) : undefined
        }
      />
      <KpiCard
        icon={HeartHandshake}
        tone="danger"
        label="الاحتياجات المفتوحة"
        value={value(kpis.open_needs)}
        href="/needs"
        context={kpis.open_needs === null ? unavailable : "احتياجات بحالة مفتوحة"}
        secondary={
          needs && needs.open > 0 ? (
            <div className="flex flex-col gap-1.5">
              <SegmentedBar
                size="sm"
                label="الأولوية"
                segments={needs.by_priority.map((p) => ({
                  key: p.priority,
                  label: needPriorityLabels[p.priority],
                  value: p.count,
                  tone: needPriorityTone[p.priority] as Tone,
                }))}
              />
              <p className="text-xs text-muted-foreground">
                <span className="font-semibold text-danger tabular-nums">{fmt(urgent)}</span> {needPriorityLabels.URGENT}
              </p>
            </div>
          ) : undefined
        }
      />
    </div>
  );
}

function DashboardBody({ data }: { data: DashboardData }) {
  return (
    <div className="flex flex-col gap-5">
      <Kpis data={data} />

      {/* Primary analytics: population dominant, displacement beside it. */}
      <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
        <div className="xl:col-span-8">
          <Section title="التركيبة السكانية" data={data.demographics} render={(d) => <DemographicsSection data={d} />} />
        </div>
        <div className="xl:col-span-4">
          <Section title="النزوح" data={data.displacement} render={(d) => <DisplacementSection data={d} />} />
        </div>
      </div>

      {/* Secondary operational widgets: three different internal patterns. */}
      <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-12">
        <div className="xl:col-span-4">
          <Section title="المؤشرات الصحية" data={data.health} render={(d) => <HealthSection data={d} />} />
        </div>
        <div className="xl:col-span-4">
          <Section title="الاحتياجات المفتوحة" data={data.needs} render={(d) => <NeedsSection data={d} />} />
        </div>
        <div className="md:col-span-2 xl:col-span-4">
          <Section title="المساعدات الجارية" data={data.assistance} render={(d) => <AssistanceSection data={d} />} />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
        <div className="xl:col-span-7">
          <Section title="نتائج التقييمات" data={data.assessments} render={(d) => <AssessmentsSection data={d} />} />
        </div>
        <div className="xl:col-span-5">
          <Section title="آخر النشاطات" data={data.recent_activity} render={(d) => <RecentActivitySection data={d} />} />
        </div>
      </div>
    </div>
  );
}

/**
 * Operational Dashboard V1 data, Design System v1 presentation: derived
 * aggregates for the selected organizational scope (Clan → Branch Group →
 * Branch).
 */
export function OperationalDashboard() {
  const options = useDashboardScopeOptions();
  const clans = options.data?.data ?? [];
  const [picked, setPicked] = useState<DashboardScopeValue | null>(null);

  // With exactly one active Clan it is preselected (never hard-coded).
  const scope: DashboardScopeValue =
    picked ?? { clan: clans.length === 1 ? clans[0].code : "", group: "", branch: "" };
  const query = scope.clan
    ? { clan: scope.clan, branch_group: scope.group || undefined, branch: scope.branch || undefined }
    : null;
  const dashboard = useDashboard(query);
  const data = dashboard.data?.data;

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="لوحة العمليات"
        description="مؤشرات تشغيلية محسوبة مباشرة من السجل الحالي ضمن النطاق المحدد"
        actions={
          data && (
            <p className="flex items-center gap-2 text-xs text-muted-foreground" data-generated-at={data.generated_at}>
              <span aria-hidden className={`size-1.5 rounded-full ${dashboard.isFetching ? "bg-warning" : "bg-success"}`} />
              آخر تحديث:{" "}
              {new Date(data.generated_at).toLocaleTimeString("ar", { hour: "2-digit", minute: "2-digit" })}
              {dashboard.isFetching && " — جارٍ التحديث…"}
            </p>
          )
        }
      />

      {/* Filter bar: one control surface for the organizational scope. */}
      <AppCard padded={false} className="flex flex-col gap-2.5 p-2.5 lg:flex-row lg:items-center lg:gap-3" aria-label="نطاق العرض">
        <div className="flex shrink-0 items-center gap-2.5 px-1 lg:w-48">
          <IconBox icon={SlidersHorizontal} size="sm" />
          <div className="flex min-w-0 flex-col leading-tight">
            <span className="text-[13px] font-semibold">نطاق العرض</span>
            <span className="truncate text-[11px] text-subtle-foreground">
              {data
                ? [data.scope.clan.name, data.scope.branch_group?.name ?? data.scope.branch_group?.display_name, data.scope.branch?.name]
                    .filter(Boolean)
                    .join(" · ")
                : "العشيرة ثم الفرع (اختياري)"}
            </span>
          </div>
        </div>
        <div className="hidden h-7 w-px bg-stroke-subtle lg:block" aria-hidden />
        <div className="min-w-0 flex-1">
          {options.isLoading ? (
            <Skeleton className="h-9 w-full" />
          ) : options.isError ? (
            <p className="text-sm text-danger">{errorMessage(options.error)}</p>
          ) : (
            <DashboardScopeFilters clans={clans} value={scope} onChange={setPicked} />
          )}
        </div>
      </AppCard>

      {!query ? (
        !options.isLoading &&
        !options.isError && (
          <AppCard padded={false}>
            <EmptyState
              icon={Filter}
              title="اختر العشيرة / العائلة لعرض المؤشرات"
              description="تُحسب المؤشرات ضمن نطاق محدد. اختر العشيرة / العائلة أعلاه، ويمكنك تضييق النطاق بمجموعة فروع أو فرع."
            />
          </AppCard>
        )
      ) : dashboard.isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تحميل لوحة التحكم</AlertTitle>
          <AlertDescription>{errorMessage(dashboard.error)}</AlertDescription>
        </Alert>
      ) : !data ? (
        <DashboardSkeleton />
      ) : (
        <div className={dashboard.isPlaceholderData ? "opacity-60 transition-opacity" : "transition-opacity"}>
          <DashboardBody data={data} />
        </div>
      )}
    </div>
  );
}
