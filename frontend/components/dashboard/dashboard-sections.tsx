"use client";

import Link from "next/link";
import { ChevronLeft, History, Lock, type LucideIcon } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { activitySubject } from "@/components/families/family-activity-tab";
import type { DashboardData, AgeBand } from "@/lib/types/api/dashboard";
import type { AssessmentRating } from "@/lib/types/api/assessment";
import { familyActivityPresentation, formatActivityTime } from "@/lib/utils/activity";
import { ASSESSMENT_RATINGS, assessmentRatingLabels } from "@/lib/utils/assessment";
import { needPriorityLabels, needPriorityStyles } from "@/lib/utils/need";
import { cn } from "cn";

const numberFormat = new Intl.NumberFormat("ar");
export const fmt = (n: number) => numberFormat.format(n);
const pct = (n: number, total: number) => (total > 0 ? Math.round((n / total) * 100) : 0);

export const ageBandLabels: Record<AgeBand, string> = {
  UNDER_2: "أقل من سنتين",
  AGE_2_5: "2–5 سنوات",
  AGE_6_17: "6–17 سنة",
  AGE_18_59: "18–59 سنة",
  AGE_60_PLUS: "60 سنة فأكثر",
  UNKNOWN: "العمر غير معروف",
};

// Solid segment colors for the assessment distribution bars.
const ratingBarColors: Record<AssessmentRating, string> = {
  NONE: "bg-emerald-500",
  LOW: "bg-sky-500",
  MEDIUM: "bg-amber-500",
  HIGH: "bg-orange-500",
  CRITICAL: "bg-red-600",
};

// ---------------------------------------------------------------- building blocks

function SectionLink({ href, label }: { href: string; label: string }) {
  return (
    <CardAction>
      <Link href={href} className="flex items-center gap-0.5 text-xs text-muted-foreground hover:text-foreground">
        {label}
        <ChevronLeft className="size-3.5" />
      </Link>
    </CardAction>
  );
}

/** A section the user's permissions do not cover (the API returned null). */
export function UnavailableSection({ title }: { title: string }) {
  return (
    <Card size="sm" data-section-unavailable={title}>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="flex items-center gap-2 text-sm text-muted-foreground">
        <Lock className="size-4" />
        لا تملك صلاحية عرض هذا القسم.
      </CardContent>
    </Card>
  );
}

function EmptyNote({ children }: { children: React.ReactNode }) {
  return <p className="py-2 text-sm text-muted-foreground">{children}</p>;
}

/** Label, count and a proportional bar. */
function BarRow({
  label,
  count,
  total,
  barClassName = "bg-primary",
  labelNode,
}: {
  label: string;
  count: number;
  total: number;
  barClassName?: string;
  labelNode?: React.ReactNode;
}) {
  const share = pct(count, total);
  return (
    <div className="flex flex-col gap-1" data-row={label}>
      <div className="flex items-center justify-between gap-3 text-sm">
        <span>{labelNode ?? label}</span>
        <span className="tabular-nums text-muted-foreground">
          <span className="font-medium text-foreground">{fmt(count)}</span>
          {total > 0 && <span className="ms-1.5 text-xs">({fmt(share)}٪)</span>}
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-muted">
        <div className={cn("h-full rounded-full", barClassName)} style={{ width: `${share}%` }} />
      </div>
    </div>
  );
}

function StatTile({ label, value, hint }: { label: string; value: number; hint?: string }) {
  return (
    <div className="flex flex-col gap-0.5 rounded-lg border p-3" data-stat={label}>
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="text-2xl font-semibold tabular-nums">{fmt(value)}</span>
      {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
    </div>
  );
}

// --------------------------------------------------------------------- KPI cards

export function KpiCard({
  label,
  value,
  icon: Icon,
  href,
  hint,
}: {
  label: string;
  value: number | null;
  icon: LucideIcon;
  href: string;
  hint: string;
}) {
  return (
    <Link href={href} className="group" data-kpi={label}>
      <Card size="sm" className="h-full transition-colors group-hover:bg-muted/40">
        <CardHeader>
          <CardDescription className="flex items-center gap-2">
            <Icon className="size-4" />
            {label}
          </CardDescription>
          <CardTitle className="text-3xl font-semibold tabular-nums">
            {value === null ? <Lock className="size-5 text-muted-foreground" aria-label="غير متاح" /> : fmt(value)}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-xs text-muted-foreground">{value === null ? "غير متاح لصلاحياتك" : hint}</p>
        </CardContent>
      </Card>
    </Link>
  );
}

// ------------------------------------------------------------------ demographics

export function DemographicsSection({ data }: { data: NonNullable<DashboardData["demographics"]> }) {
  const { total, gender } = data;
  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>التركيبة السكانية</CardTitle>
        <CardDescription>الأفراد الحاليون الأحياء في الأسر النشطة — العمر محسوب حتى اليوم</CardDescription>
        <SectionLink href="/people" label="الأشخاص" />
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {total === 0 ? (
          <EmptyNote>لا يوجد أفراد حاليون ضمن النطاق المحدد.</EmptyNote>
        ) : (
          <>
            <div className="flex flex-col gap-1.5">
              <div className="flex h-2.5 overflow-hidden rounded-full bg-muted">
                <div className="bg-sky-500" style={{ width: `${pct(gender.male, total)}%` }} />
                <div className="bg-rose-400" style={{ width: `${pct(gender.female, total)}%` }} />
              </div>
              <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <span data-gender="male">
                  <span className="me-1 inline-block size-2 rounded-full bg-sky-500" />
                  ذكور: <span className="font-medium text-foreground tabular-nums">{fmt(gender.male)}</span>
                </span>
                <span data-gender="female">
                  <span className="me-1 inline-block size-2 rounded-full bg-rose-400" />
                  إناث: <span className="font-medium text-foreground tabular-nums">{fmt(gender.female)}</span>
                </span>
                {gender.unknown > 0 && (
                  <span data-gender="unknown">
                    غير محدد: <span className="font-medium text-foreground tabular-nums">{fmt(gender.unknown)}</span>
                  </span>
                )}
              </div>
            </div>
            <div className="flex flex-col gap-2.5">
              {data.age_bands.map((band) => (
                <BarRow
                  key={band.code}
                  label={ageBandLabels[band.code]}
                  count={band.count}
                  total={total}
                  barClassName={band.code === "UNKNOWN" ? "bg-muted-foreground/40" : "bg-primary"}
                />
              ))}
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}

// ------------------------------------------------------------------ displacement

export function DisplacementSection({ data }: { data: NonNullable<DashboardData["displacement"]> }) {
  const total = data.total_families;
  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>النزوح</CardTitle>
        <CardDescription>حسب السكن الحالي للأسر النشطة</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {total === 0 ? (
          <EmptyNote>لا توجد أسر نشطة ضمن النطاق المحدد.</EmptyNote>
        ) : (
          <>
            <div className="flex flex-col gap-2.5">
              <BarRow label="نازحة" count={data.displaced} total={total} barClassName="bg-amber-500" />
              <BarRow label="غير نازحة" count={data.not_displaced} total={total} barClassName="bg-emerald-500" />
              <BarRow label="غير معروف / غير مسجّل" count={data.unknown} total={total} barClassName="bg-muted-foreground/40" />
            </div>
            {data.top_locations.length > 0 && (
              <div className="flex flex-col gap-1.5">
                <p className="text-xs font-medium text-muted-foreground">أكثر أماكن النزوح تكرارًا (كما هي مسجّلة)</p>
                <ul className="divide-y rounded-lg border text-sm">
                  {data.top_locations.map((l) => (
                    <li key={l.location} className="flex items-center justify-between gap-3 px-3 py-1.5">
                      <span className="truncate">{l.location}</span>
                      <span className="shrink-0 tabular-nums text-muted-foreground">{fmt(l.families)} أسرة</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

// ------------------------------------------------------------------------ health

export function HealthSection({ data }: { data: NonNullable<DashboardData["health"]> }) {
  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>المؤشرات الصحية</CardTitle>
        <CardDescription>عدد الأفراد (وليس السجلات) ذوي الحالات النشطة</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-2">
          <StatTile label="ذوو إعاقة" value={data.people_with_disability} />
          <StatTile label="أمراض مزمنة" value={data.people_with_chronic_disease} />
          <StatTile label="حمل نشط" value={data.active_pregnancy} />
          <StatTile label="رضاعة نشطة" value={data.active_breastfeeding} />
        </div>
        {data.disability_types.length > 0 && (
          <div className="flex flex-col gap-1.5">
            <p className="text-xs font-medium text-muted-foreground">الإعاقة حسب النوع</p>
            <div className="flex flex-wrap gap-1.5">
              {data.disability_types.map((t) => (
                <Badge key={t.code ?? "none"} variant="secondary" className="gap-1">
                  {t.name ?? "غير محدد"}
                  <span className="tabular-nums">{fmt(t.people)}</span>
                </Badge>
              ))}
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

// ------------------------------------------------------------------------- needs

export function NeedsSection({ data }: { data: NonNullable<DashboardData["needs"]> }) {
  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>الاحتياجات المفتوحة</CardTitle>
        <CardDescription>
          {fmt(data.open)} احتياج مفتوح لدى {fmt(data.families_with_open_needs)} أسرة
        </CardDescription>
        <SectionLink href="/needs" label="الاحتياجات" />
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {data.open === 0 ? (
          <EmptyNote>لا توجد احتياجات مفتوحة ضمن النطاق المحدد.</EmptyNote>
        ) : (
          <>
            <div className="flex flex-col gap-2.5">
              {data.by_priority.map((p) => (
                <BarRow
                  key={p.priority}
                  label={needPriorityLabels[p.priority]}
                  labelNode={
                    <Badge variant="outline" className={needPriorityStyles[p.priority]}>
                      {needPriorityLabels[p.priority]}
                    </Badge>
                  }
                  count={p.count}
                  total={data.open}
                  barClassName={p.priority === "URGENT" ? "bg-red-600" : p.priority === "HIGH" ? "bg-orange-500" : "bg-primary"}
                />
              ))}
            </div>
            <div className="flex flex-col gap-1.5">
              <p className="text-xs font-medium text-muted-foreground">أكثر الفئات</p>
              <ul className="divide-y rounded-lg border text-sm">
                {data.top_categories.map((c) => (
                  <li key={c.code} className="flex items-center justify-between gap-3 px-3 py-1.5">
                    <span>{c.name}</span>
                    <span className="tabular-nums text-muted-foreground">{fmt(c.count)}</span>
                  </li>
                ))}
              </ul>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}

// ------------------------------------------------------------------- assessments

export function AssessmentsSection({ data }: { data: NonNullable<DashboardData["assessments"]> }) {
  const total = data.total_families;
  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>نتائج التقييمات</CardTitle>
        <CardDescription>
          لكل أسرة ومجال: آخر تقييم مكتمل قيّم المجال. المسودات لا تُحتسب، والمجال غير المقيّم لا يُعد «لا يوجد احتياج».
        </CardDescription>
        <SectionLink href="/assessments" label="التقييمات" />
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
          {ASSESSMENT_RATINGS.map((r) => (
            <span key={r} className="flex items-center gap-1">
              <span className={cn("inline-block size-2 rounded-full", ratingBarColors[r])} />
              {assessmentRatingLabels[r]}
            </span>
          ))}
          <span className="flex items-center gap-1">
            <span className="inline-block size-2 rounded-full bg-muted-foreground/25" />
            لم يُقيَّم
          </span>
        </div>
        {total === 0 ? (
          <EmptyNote>لا توجد أسر نشطة ضمن النطاق المحدد.</EmptyNote>
        ) : (
          <div className="flex flex-col gap-3">
            {data.domains.map((domain) => (
              <div key={domain.code} className="flex flex-col gap-1" data-domain={domain.code}>
                <div className="flex flex-wrap items-center justify-between gap-x-3 text-sm">
                  <span className="font-medium">{domain.name}</span>
                  <span className="text-xs text-muted-foreground tabular-nums">
                    {fmt(domain.assessed_families)} مُقيَّمة من {fmt(total)}
                  </span>
                </div>
                <div className="flex h-2.5 overflow-hidden rounded-full bg-muted-foreground/15">
                  {ASSESSMENT_RATINGS.map((r) =>
                    domain.ratings[r] > 0 ? (
                      <div
                        key={r}
                        className={ratingBarColors[r]}
                        style={{ width: `${(domain.ratings[r] / total) * 100}%` }}
                        title={`${assessmentRatingLabels[r]}: ${fmt(domain.ratings[r])}`}
                      />
                    ) : null
                  )}
                </div>
                <div className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                  {ASSESSMENT_RATINGS.filter((r) => domain.ratings[r] > 0).map((r) => (
                    <span key={r} data-rating={r}>
                      {assessmentRatingLabels[r]}: <span className="tabular-nums text-foreground">{fmt(domain.ratings[r])}</span>
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

// -------------------------------------------------------------------- assistance

export function AssistanceSection({ data }: { data: NonNullable<DashboardData["assistance"]> }) {
  const { internal, external, open_programs } = data;
  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>المساعدات الجارية</CardTitle>
        <CardDescription>برامج مفتوحة لها مستفيدون ضمن النطاق، حسب أسرة المستفيد</CardDescription>
        <SectionLink href="/assistances" label="المساعدات" />
      </CardHeader>
      <CardContent className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div className="flex flex-col gap-2 rounded-lg border p-3" data-mode="INTERNAL">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold">تنفيذ داخلي</p>
            <Badge variant="secondary">{fmt(open_programs.internal)} برنامج مفتوح</Badge>
          </div>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <Figure label="مرشحون" value={internal.nominated} />
            <Figure label="معتمدون" value={internal.approved} />
            <Figure label="بانتظار التسليم" value={internal.awaiting_delivery} />
            <Figure label="تم التسليم" value={internal.delivered} emphasis />
            <Figure label="لم يتم التسليم" value={internal.not_delivered} />
          </dl>
        </div>
        <div className="flex flex-col gap-2 rounded-lg border p-3" data-mode="EXTERNAL">
          <div className="flex items-center justify-between">
            <p className="text-sm font-semibold">تنفيذ خارجي</p>
            <Badge variant="secondary">{fmt(open_programs.external)} برنامج مفتوح</Badge>
          </div>
          <dl className="grid grid-cols-2 gap-2 text-sm">
            <Figure label="مرشحون" value={external.nominated} />
            <Figure label="معتمدون" value={external.approved} />
            <Figure label="معتمدون لم يُصدروا في كشف" value={external.approved_not_listed} />
            <Figure label="تم إصدارهم في كشوف" value={external.listed_unique} emphasis />
          </dl>
          <p className="text-xs text-muted-foreground">
            الإصدار في كشف لجهة خارجية لا يعني التسليم؛ التسليم الفعلي يتم خارج النظام.
          </p>
        </div>
      </CardContent>
    </Card>
  );
}

function Figure({ label, value, emphasis }: { label: string; value: number; emphasis?: boolean }) {
  return (
    <div className="flex flex-col" data-figure={label}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={cn("tabular-nums", emphasis ? "text-lg font-semibold" : "font-medium")}>{fmt(value)}</dd>
    </div>
  );
}

// ----------------------------------------------------------------------- activity

export function RecentActivitySection({ data }: { data: NonNullable<DashboardData["recent_activity"]> }) {
  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <History className="size-4 text-muted-foreground" />
          آخر النشاطات
        </CardTitle>
        <CardDescription>أحدث {fmt(data.length)} أحداث في سجلات الأسر ضمن النطاق</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        {data.length === 0 ? (
          <div className="px-4">
            <EmptyNote>لا توجد نشاطات ضمن النطاق المحدد.</EmptyNote>
          </div>
        ) : (
          <ul className="divide-y">
            {data.map((activity) => {
              const { label, icon: Icon } = familyActivityPresentation[activity.event_type];
              const subject = activitySubject(activity);
              return (
                <li key={activity.id} className="flex gap-3 px-4 py-2.5" data-activity-id={activity.id}>
                  <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Icon className="size-3.5" />
                  </span>
                  <div className="flex min-w-0 flex-col gap-0.5">
                    <p className="text-sm font-medium">{label}</p>
                    {subject && <p className="truncate text-xs text-muted-foreground">{subject}</p>}
                    <p className="text-xs text-muted-foreground">
                      {activity.family.family_code && (
                        <>
                          <Link
                            href={`/families/${activity.family.family_code}?tab=history`}
                            className="font-medium text-foreground hover:underline"
                            dir="ltr"
                          >
                            {activity.family.family_code}
                          </Link>
                          <span className="mx-1.5">·</span>
                        </>
                      )}
                      {activity.actor?.name ?? "النظام"}
                      <span className="mx-1.5">·</span>
                      <time dateTime={activity.occurred_at}>{formatActivityTime(activity.occurred_at)}</time>
                    </p>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
