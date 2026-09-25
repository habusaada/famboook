"use client";

import { useState } from "react";
import { AlertCircle, HeartHandshake, Tent, User, Users } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Card, CardContent } from "@/components/ui/card";
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
  KpiCard,
  NeedsSection,
  RecentActivitySection,
  UnavailableSection,
} from "@/components/dashboard/dashboard-sections";
import { ApiError } from "@/lib/api/client";
import { useDashboard, useDashboardScopeOptions } from "@/lib/api/dashboard";
import type { DashboardData } from "@/lib/types/api/dashboard";

function errorMessage(error: unknown): string {
  if (error instanceof ApiError && error.status === 403) return "لا تملك صلاحية عرض لوحة التحكم التشغيلية.";
  if (error instanceof ApiError && error.status === 422) return error.message422 ?? "النطاق المحدد غير صالح.";
  return "تعذّر تحميل بيانات لوحة التحكم. الرجاء المحاولة مرة أخرى.";
}

function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-4" aria-busy="true">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-28 w-full rounded-xl" />
        ))}
      </div>
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-64 w-full rounded-xl" />
        ))}
      </div>
    </div>
  );
}

function Section<T>({
  title,
  data,
  render,
}: {
  title: string;
  data: T | null;
  render: (data: T) => React.ReactNode;
}) {
  return data === null ? <UnavailableSection title={title} /> : <>{render(data)}</>;
}

function DashboardBody({ data }: { data: DashboardData }) {
  const { kpis } = data;
  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard label="الأسر النشطة" value={kpis.active_families} icon={Users} href="/families" hint="أسر بحالة نشطة ضمن النطاق" />
        <KpiCard label="الأفراد الحاليون" value={kpis.current_people} icon={User} href="/people" hint="أحياء بعضوية نشطة في هذه الأسر" />
        <KpiCard label="الأسر النازحة" value={kpis.displaced_families} icon={Tent} href="/families" hint="حسب السكن الحالي" />
        <KpiCard label="الاحتياجات المفتوحة" value={kpis.open_needs} icon={HeartHandshake} href="/needs" hint="احتياجات بحالة مفتوحة" />
      </div>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <Section title="التركيبة السكانية" data={data.demographics} render={(d) => <DemographicsSection data={d} />} />
        <div className="flex flex-col gap-3">
          <Section title="النزوح" data={data.displacement} render={(d) => <DisplacementSection data={d} />} />
          <Section title="المؤشرات الصحية" data={data.health} render={(d) => <HealthSection data={d} />} />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <Section title="الاحتياجات المفتوحة" data={data.needs} render={(d) => <NeedsSection data={d} />} />
        <Section title="نتائج التقييمات" data={data.assessments} render={(d) => <AssessmentsSection data={d} />} />
      </div>

      <Section title="المساعدات الجارية" data={data.assistance} render={(d) => <AssistanceSection data={d} />} />
      <Section title="آخر النشاطات" data={data.recent_activity} render={(d) => <RecentActivitySection data={d} />} />
    </div>
  );
}

/**
 * Operational Dashboard V1: derived aggregates for the selected
 * organizational scope (Clan → Branch Group → Branch).
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
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 className="text-2xl font-semibold tracking-tight">لوحة التحكم</h2>
          <p className="text-sm text-muted-foreground">
            مؤشرات تشغيلية محسوبة مباشرة من السجل الحالي ضمن النطاق المحدد
          </p>
        </div>
        {data && (
          <p className="text-xs text-muted-foreground" data-generated-at={data.generated_at}>
            آخر تحديث:{" "}
            {new Date(data.generated_at).toLocaleTimeString("ar", { hour: "2-digit", minute: "2-digit" })}
            {dashboard.isFetching && " — جارٍ التحديث…"}
          </p>
        )}
      </div>

      <Card size="sm">
        <CardContent>
          {options.isLoading ? (
            <Skeleton className="h-14 w-full" />
          ) : options.isError ? (
            <p className="text-sm text-destructive">{errorMessage(options.error)}</p>
          ) : (
            <DashboardScopeFilters clans={clans} value={scope} onChange={setPicked} />
          )}
        </CardContent>
      </Card>

      {!query ? (
        !options.isLoading &&
        !options.isError && (
          <div className="rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground">
            اختر العشيرة / العائلة لعرض المؤشرات.
          </div>
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
