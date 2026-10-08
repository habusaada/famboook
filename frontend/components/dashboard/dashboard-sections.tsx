"use client";

import Link from "next/link";
import {
  Accessibility,
  Activity,
  Baby,
  ChevronLeft,
  ClipboardCheck,
  HandHeart,
  HeartHandshake,
  HeartPulse,
  Lock,
  Milk,
  Tent,
  Users,
  type LucideIcon,
} from "lucide-react";
import { AppCard } from "@/components/shared/app-card";
import { SectionHeader, Code } from "@/components/shared/page-layout";
import { StatusBadge, type StatusTone } from "@/components/shared/status-badge";
import { IconBox, type Tone } from "@/components/shared/icon-box";
import { LegendItem, ProgressBar, SegmentedBar, share } from "@/components/shared/meter";
import { ActivityItem } from "@/components/shared/activity-item";
import { activitySubject } from "@/components/families/family-activity-tab";
import type { DashboardData, AgeBand } from "@/lib/types/api/dashboard";
import type { AssessmentRating } from "@/lib/types/api/assessment";
import type { NeedPriority } from "@/lib/types/api/need";
import { activityPresentation, formatActivityTime } from "@/lib/utils/activity";
import { ASSESSMENT_RATINGS, assessmentRatingLabels } from "@/lib/utils/assessment";
import { needPriorityLabels } from "@/lib/utils/need";
import { cn } from "cn";

const numberFormat = new Intl.NumberFormat("ar");
export const fmt = (n: number) => numberFormat.format(n);

export const ageBandLabels: Record<AgeBand, string> = {
  UNDER_2: "أقل من سنتين",
  AGE_2_5: "2–5 سنوات",
  AGE_6_17: "6–17 سنة",
  AGE_18_59: "18–59 سنة",
  AGE_60_PLUS: "60 سنة فأكثر",
  UNKNOWN: "العمر غير معروف",
};

// ================================================================ report helpers
// Shared with the Reports screens (unchanged look there).

export function EmptyNote({ children }: { children: React.ReactNode }) {
  return <p className="py-2 text-sm text-muted-foreground">{children}</p>;
}

/** Label, count and a proportional bar (Reports). */
export function BarRow({
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
  const pct = share(count, total);
  return (
    <div className="flex flex-col gap-1" data-row={label}>
      <div className="flex items-center justify-between gap-3 text-sm">
        <span>{labelNode ?? label}</span>
        <span className="tabular-nums text-muted-foreground">
          <span className="font-medium text-foreground">{fmt(count)}</span>
          {total > 0 && <span className="ms-1.5 text-xs">({fmt(pct)}٪)</span>}
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-muted">
        <div className={cn("h-full rounded-full", barClassName)} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

export function StatTile({ label, value, hint }: { label: string; value: number; hint?: string }) {
  return (
    <div className="flex flex-col gap-0.5 rounded-lg border p-3" data-stat={label}>
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="text-2xl font-semibold tabular-nums">{fmt(value)}</span>
      {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
    </div>
  );
}

// ================================================================ widget frame

function WidgetLink({ href, label }: { href: string; label: string }) {
  return (
    <Link
      href={href}
      className="flex h-8 items-center gap-0.5 rounded-control px-2 text-xs font-medium text-brand-700 transition-colors hover:bg-surface-hover hover:text-brand-900 focus-visible:outline-2 focus-visible:outline-ring"
    >
      {label}
      <ChevronLeft className="size-3.5" />
    </Link>
  );
}

function Widget({
  icon,
  tone,
  title,
  description,
  action,
  children,
  className,
}: {
  icon: LucideIcon;
  tone?: Tone;
  title: string;
  description?: React.ReactNode;
  action?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <AppCard className={cn("flex h-full flex-col", className)} aria-label={title}>
      <SectionHeader icon={icon} tone={tone} title={title} description={description} action={action} />
      <div className="mt-4 flex flex-1 flex-col gap-4">{children}</div>
    </AppCard>
  );
}

/** A section the user's permissions do not cover (the API returned null). */
export function UnavailableSection({ title }: { title: string }) {
  return (
    <AppCard className="flex h-full flex-col gap-2" data-section-unavailable={title}>
      <p className="text-base font-semibold">{title}</p>
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <Lock className="size-4" />
        لا تملك صلاحية عرض هذا القسم.
      </p>
    </AppCard>
  );
}

function SubHeading({ children }: { children: React.ReactNode }) {
  return <p className="text-xs font-semibold text-muted-foreground">{children}</p>;
}

// ================================================================ population

export function DemographicsSection({ data }: { data: NonNullable<DashboardData["demographics"]> }) {
  const { total, gender } = data;
  const maxBand = Math.max(1, ...data.age_bands.map((b) => b.count));
  const malePct = share(gender.male, total);
  const femalePct = share(gender.female, total);

  return (
    <Widget
      icon={Users}
      title="التركيبة السكانية"
      description="الأفراد الحاليون الأحياء في الأسر النشطة — العمر محسوب حتى اليوم"
      action={<WidgetLink href="/people" label="الأشخاص" />}
    >
      {total === 0 ? (
        <EmptyNote>لا يوجد أفراد حاليون ضمن النطاق المحدد.</EmptyNote>
      ) : (
        <div className="grid grid-cols-1 gap-6 md:grid-cols-[minmax(0,13.5rem)_minmax(0,1fr)] md:gap-8">
          {/* Gender: slim ring + aligned legend */}
          <div className="flex items-center gap-5 md:flex-col md:items-stretch md:gap-4">
            <div
              role="img"
              aria-label={`الجنس: ذكور ${fmt(gender.male)}، إناث ${fmt(gender.female)}${gender.unknown ? `، غير محدد ${fmt(gender.unknown)}` : ""}`}
              className="relative mx-auto size-[136px] shrink-0 rounded-full"
              style={{
                background: `conic-gradient(var(--brand-700) 0 ${malePct}%, var(--brand-500) ${malePct}% ${malePct + femalePct}%, var(--surface-pressed) ${malePct + femalePct}% 100%)`,
              }}
            >
              <div className="absolute inset-[11px] flex flex-col items-center justify-center rounded-full bg-surface-1 shadow-[inset_0_0_0_1px_var(--stroke-subtle)]">
                <span className="text-[26px] leading-none font-bold tabular-nums text-foreground">{fmt(total)}</span>
                <span className="mt-1 text-[11px] text-muted-foreground">فرد</span>
              </div>
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-2.5 md:rounded-lg md:bg-surface-2 md:px-3 md:py-2.5" data-gender-legend>
              <LegendItem label="ذكور" value={gender.male} total={total} dotClassName="bg-brand-700" />
              <LegendItem label="إناث" value={gender.female} total={total} dotClassName="bg-brand-500" />
              {gender.unknown > 0 && <LegendItem label="غير محدد" value={gender.unknown} total={total} tone="neutral" />}
            </div>
          </div>

          {/* Age: columns, youngest at the reading start. Known bands in
              teal; the unknown band stays visible as an outlined column so
              it never outweighs known ages. */}
          <div className="flex min-w-0 flex-col gap-3">
            <SubHeading>الفئات العمرية</SubHeading>
            <div className="relative grid h-40 grid-cols-6 items-end gap-2.5 sm:gap-4" data-age-bands>
              <div aria-hidden className="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-stroke-subtle" />
              {data.age_bands.map((band) => {
                const unknown = band.code === "UNKNOWN";
                return (
                  <div key={band.code} className="relative flex h-full flex-col items-center justify-end gap-1.5" data-row={ageBandLabels[band.code]}>
                    <span className={cn("text-xs tabular-nums", unknown ? "font-medium text-muted-foreground" : "font-semibold text-foreground")}>
                      {fmt(band.count)}
                    </span>
                    <div
                      className={cn(
                        "w-full max-w-9 rounded-t-[6px]",
                        unknown ? "border border-b-0 border-dashed border-subtle-foreground/45 bg-surface-2" : "bg-brand-600"
                      )}
                      style={{ height: `${Math.max(band.count > 0 ? 4 : 0, (band.count / maxBand) * 100)}%` }}
                      title={`${ageBandLabels[band.code]}: ${fmt(band.count)}`}
                    />
                  </div>
                );
              })}
            </div>
            <div className="grid grid-cols-6 gap-2.5 border-t border-stroke-subtle pt-2 sm:gap-4">
              {data.age_bands.map((band) => (
                <span
                  key={band.code}
                  className={cn(
                    "text-center text-[11px] leading-tight",
                    band.code === "UNKNOWN" ? "text-subtle-foreground" : "text-muted-foreground"
                  )}
                >
                  {ageBandLabels[band.code]}
                </span>
              ))}
            </div>
          </div>
        </div>
      )}
    </Widget>
  );
}

// ================================================================ displacement

export function DisplacementSection({ data }: { data: NonNullable<DashboardData["displacement"]> }) {
  const total = data.total_families;
  const maxLocation = Math.max(1, ...data.top_locations.map((l) => l.families));

  return (
    <Widget icon={Tent} tone="warning" title="النزوح" description="حسب السكن الحالي للأسر النشطة">
      {total === 0 ? (
        <EmptyNote>لا توجد أسر نشطة ضمن النطاق المحدد.</EmptyNote>
      ) : (
        <>
          {/* Headline: the displaced share is the focal point. */}
          <div className="flex flex-col gap-1">
            <span className="text-[44px] leading-none font-bold tracking-tight tabular-nums text-foreground">
              <bdi>{fmt(share(data.displaced, total))}٪</bdi>
            </span>
            <span className="text-[13px] text-muted-foreground">
              من الأسر النشطة نازحة — <bdi className="font-semibold text-foreground">{fmt(data.displaced)}</bdi> من{" "}
              <bdi>{fmt(total)}</bdi> أسرة
            </span>
          </div>
          <div className="flex flex-col gap-2.5">
            <SegmentedBar
              size="lg"
              label="حالة النزوح"
              segments={[
                { key: "d", label: "نازحة", value: data.displaced, tone: "warning" },
                { key: "n", label: "غير نازحة", value: data.not_displaced, tone: "brand" },
                { key: "u", label: "غير معروف / غير مسجّل", value: data.unknown, tone: "neutral" },
              ]}
            />
            <div className="flex flex-col gap-1.5">
              <LegendItem label="نازحة" value={data.displaced} total={total} tone="warning" />
              <LegendItem label="غير نازحة" value={data.not_displaced} total={total} tone="brand" />
              <LegendItem label="غير معروف / غير مسجّل" value={data.unknown} total={total} tone="neutral" />
            </div>
          </div>
          {data.top_locations.length > 0 && (
            <div className="-mx-4 mt-auto -mb-4 flex flex-col gap-1.5 rounded-b-widget border-t border-stroke-subtle bg-surface-2 px-4 pt-3 pb-3 sm:-mx-5 sm:-mb-5 sm:px-5">
              <SubHeading>أكثر أماكن النزوح تكرارًا (كما هي مسجّلة)</SubHeading>
              <ul className="flex flex-col">
                {data.top_locations.map((l) => (
                  <li key={l.location} className="flex items-center gap-3 py-1.5 text-[13px]">
                    <span className="min-w-0 flex-1 truncate">{l.location}</span>
                    <span className="w-16 shrink-0">
                      <ProgressBar size="sm" tone="warning" value={l.families} total={maxLocation} label={l.location} />
                    </span>
                    <span className="w-12 shrink-0 text-end text-xs tabular-nums text-muted-foreground">
                      <bdi className="font-semibold text-foreground">{fmt(l.families)}</bdi> أسرة
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </>
      )}
    </Widget>
  );
}

// ================================================================ health

function HealthMetric({ icon, label, value }: { icon: LucideIcon; label: string; value: number }) {
  return (
    <AppCard variant="subtle" padded={false} className="flex items-center gap-3 p-3" data-stat={label}>
      <IconBox icon={icon} tone="info" size="sm" />
      <div className="flex min-w-0 flex-col">
        <span className="text-xl leading-tight font-bold tabular-nums">{fmt(value)}</span>
        <span className="truncate text-xs text-muted-foreground">{label}</span>
      </div>
    </AppCard>
  );
}

export function HealthSection({ data }: { data: NonNullable<DashboardData["health"]> }) {
  return (
    <Widget icon={HeartPulse} tone="info" title="المؤشرات الصحية" description="عدد الأفراد (وليس السجلات) ذوي الحالات النشطة">
      <div className="grid grid-cols-2 gap-2.5">
        <HealthMetric icon={Accessibility} label="ذوو إعاقة" value={data.people_with_disability} />
        <HealthMetric icon={Activity} label="أمراض مزمنة" value={data.people_with_chronic_disease} />
        <HealthMetric icon={Baby} label="حمل نشط" value={data.active_pregnancy} />
        <HealthMetric icon={Milk} label="رضاعة نشطة" value={data.active_breastfeeding} />
      </div>
      {data.disability_types.length > 0 && (
        <div className="flex flex-col gap-2">
          <SubHeading>الإعاقة حسب النوع</SubHeading>
          <div className="flex flex-wrap gap-1.5">
            {data.disability_types.map((t) => (
              <span
                key={t.code ?? "none"}
                className="inline-flex h-7 items-center gap-1.5 rounded-control bg-surface-2 px-2.5 text-xs text-foreground"
              >
                {t.name ?? "غير محدد"}
                <span className="font-semibold tabular-nums">{fmt(t.people)}</span>
              </span>
            ))}
          </div>
        </div>
      )}
    </Widget>
  );
}

// ================================================================ needs

export const needPriorityTone: Record<NeedPriority, StatusTone> = {
  URGENT: "danger",
  HIGH: "warning",
  MEDIUM: "info",
  LOW: "neutral",
};

export function NeedsSection({ data }: { data: NonNullable<DashboardData["needs"]> }) {
  const maxCategory = Math.max(1, ...data.top_categories.map((c) => c.count));
  return (
    <Widget
      icon={HeartHandshake}
      tone="danger"
      title="الاحتياجات المفتوحة"
      description={`${fmt(data.open)} احتياج مفتوح لدى ${fmt(data.families_with_open_needs)} أسرة`}
      action={<WidgetLink href="/needs" label="الاحتياجات" />}
    >
      {data.open === 0 ? (
        <EmptyNote>لا توجد احتياجات مفتوحة ضمن النطاق المحدد.</EmptyNote>
      ) : (
        <>
          <ul className="flex flex-col gap-3">
            {data.by_priority.map((p) => (
              <li key={p.priority} className="grid grid-cols-[5.5rem_minmax(0,1fr)_2rem] items-center gap-3" data-row={needPriorityLabels[p.priority]}>
                <StatusBadge tone={needPriorityTone[p.priority]} className="justify-self-start">
                  {needPriorityLabels[p.priority]}
                </StatusBadge>
                <ProgressBar value={p.count} total={data.open} tone={needPriorityTone[p.priority] as Tone} label={needPriorityLabels[p.priority]} />
                <span className="text-end text-sm font-semibold tabular-nums">{fmt(p.count)}</span>
              </li>
            ))}
          </ul>
          <div className="flex flex-col gap-2 border-t border-stroke-subtle pt-4">
            <SubHeading>أكثر الفئات</SubHeading>
            <ol className="flex flex-col gap-2">
              {data.top_categories.map((c, i) => (
                <li key={c.code} className="flex items-center gap-2.5 text-sm">
                  <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-surface-2 text-[11px] font-semibold tabular-nums text-muted-foreground">
                    {fmt(i + 1)}
                  </span>
                  <span className="min-w-0 flex-1 truncate">{c.name}</span>
                  <span className="w-16 shrink-0">
                    <ProgressBar size="sm" tone="neutral" value={c.count} total={maxCategory} label={c.name} />
                  </span>
                  <span className="w-6 shrink-0 text-end text-xs font-semibold tabular-nums">{fmt(c.count)}</span>
                </li>
              ))}
            </ol>
          </div>
        </>
      )}
    </Widget>
  );
}

// ================================================================ assessments

// Severity keeps semantic color (meaning, not decoration).
const ratingFill: Record<AssessmentRating, string> = {
  NONE: "bg-success/70",
  LOW: "bg-info/60",
  MEDIUM: "bg-warning/55",
  HIGH: "bg-warning",
  CRITICAL: "bg-danger",
};

export function AssessmentsSection({ data }: { data: NonNullable<DashboardData["assessments"]> }) {
  const total = data.total_families;
  return (
    <Widget
      icon={ClipboardCheck}
      title="نتائج التقييمات"
      description="لكل أسرة ومجال: آخر تقييم مكتمل قيّم المجال. المسودات لا تُحتسب، والمجال غير المقيّم لا يُعد «لا يوجد احتياج»."
      action={<WidgetLink href="/assessments" label="التقييمات" />}
    >
      <div className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
        {ASSESSMENT_RATINGS.map((r) => (
          <span key={r} className="flex items-center gap-1.5">
            <span aria-hidden className={cn("inline-block size-2 rounded-full", ratingFill[r])} />
            {assessmentRatingLabels[r]}
          </span>
        ))}
        <span className="flex items-center gap-1.5">
          <span aria-hidden className="inline-block size-2 rounded-full bg-surface-pressed" />
          لم يُقيَّم
        </span>
      </div>
      {total === 0 ? (
        <EmptyNote>لا توجد أسر نشطة ضمن النطاق المحدد.</EmptyNote>
      ) : (
        <ul className="flex flex-col divide-y divide-stroke-subtle">
          {data.domains.map((domain) => (
            <li
              key={domain.code}
              className="grid grid-cols-1 gap-1.5 py-2.5 first:pt-0 last:pb-0 sm:grid-cols-[10rem_minmax(0,1fr)_auto] sm:items-center sm:gap-4"
              data-domain={domain.code}
            >
              <span className="text-sm font-medium">{domain.name}</span>
              <SegmentedBar
                label={domain.name}
                segments={[
                  ...ASSESSMENT_RATINGS.map((r) => ({ key: r, label: assessmentRatingLabels[r], value: domain.ratings[r], className: ratingFill[r] })),
                  { key: "none", label: "لم يُقيَّم", value: Math.max(0, total - domain.assessed_families), className: "bg-transparent" },
                ]}
              />
              <span className="text-xs text-muted-foreground tabular-nums sm:text-end">
                {fmt(domain.assessed_families)} / {fmt(total)} مُقيَّمة
              </span>
            </li>
          ))}
        </ul>
      )}
    </Widget>
  );
}

// ================================================================ assistance

function Figure({ label, value, emphasis }: { label: string; value: number; emphasis?: boolean }) {
  return (
    <div className="flex flex-col gap-0.5" data-figure={label}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={cn("tabular-nums", emphasis ? "text-xl font-bold text-success" : "text-lg font-semibold text-foreground")}>
        {fmt(value)}
      </dd>
    </div>
  );
}

function ModeGroup({
  title,
  programs,
  children,
  ...props
}: { title: string; programs: number; children: React.ReactNode } & React.ComponentProps<"section">) {
  return (
    <AppCard variant="subtle" padded={false} className="flex flex-col gap-3 p-4" {...props}>
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-semibold">{title}</p>
        <StatusBadge tone="neutral">{fmt(programs)} برنامج مفتوح</StatusBadge>
      </div>
      {children}
    </AppCard>
  );
}

export function AssistanceSection({ data }: { data: NonNullable<DashboardData["assistance"]> }) {
  const { internal, external, open_programs } = data;
  return (
    <Widget
      icon={HandHeart}
      tone="success"
      title="المساعدات الجارية"
      description="برامج مفتوحة لها مستفيدون ضمن النطاق، حسب أسرة المستفيد"
      action={<WidgetLink href="/assistances" label="المساعدات" />}
    >
      <ModeGroup title="تنفيذ داخلي" programs={open_programs.internal} data-mode="INTERNAL">
        <dl className="grid grid-cols-3 gap-x-3 gap-y-3">
          <Figure label="مرشحون" value={internal.nominated} />
          <Figure label="معتمدون" value={internal.approved} />
          <Figure label="بانتظار التسليم" value={internal.awaiting_delivery} />
          <Figure label="تم التسليم" value={internal.delivered} emphasis />
          <Figure label="لم يتم التسليم" value={internal.not_delivered} />
        </dl>
      </ModeGroup>
      <ModeGroup title="تنفيذ خارجي" programs={open_programs.external} data-mode="EXTERNAL">
        <dl className="grid grid-cols-2 gap-x-3 gap-y-3">
          <Figure label="مرشحون" value={external.nominated} />
          <Figure label="معتمدون" value={external.approved} />
          <Figure label="معتمدون لم يُصدروا في كشف" value={external.approved_not_listed} />
          <Figure label="تم إصدارهم في كشوف" value={external.listed_unique} emphasis />
        </dl>
        <p className="text-xs text-subtle-foreground">الإصدار في كشف لجهة خارجية لا يعني التسليم؛ التسليم الفعلي يتم خارج النظام.</p>
      </ModeGroup>
    </Widget>
  );
}

// ================================================================ activity

export function RecentActivitySection({ data }: { data: NonNullable<DashboardData["recent_activity"]> }) {
  return (
    <Widget icon={Activity} tone="neutral" title="آخر النشاطات" description={`أحدث ${fmt(data.length)} أحداث في سجلات الأسر ضمن النطاق`}>
      {data.length === 0 ? (
        <EmptyNote>لا توجد نشاطات ضمن النطاق المحدد.</EmptyNote>
      ) : (
        <ul className="flex flex-col">
          {data.map((activity, index) => {
            const { label, icon } = activityPresentation(activity.event_type);
            const subject = activitySubject(activity);
            const code = activity.family.family_code;
            return (
              <ActivityItem
                key={activity.id}
                data-activity-id={activity.id}
                icon={icon}
                title={label}
                last={index === data.length - 1}
                entity={
                  <>
                    {subject && <span>{subject}</span>}
                    {subject && code && <span className="mx-1.5 text-subtle-foreground">·</span>}
                    {code && (
                      <Link
                        href={`/families/${code}?tab=history`}
                        className="rounded-sm hover:text-brand-700 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                      >
                        <Code className="font-normal">{code}</Code>
                      </Link>
                    )}
                  </>
                }
                meta={
                  <>
                    {activity.actor?.name ?? "النظام"}
                    <span className="mx-1.5">·</span>
                    <time dateTime={activity.occurred_at}>{formatActivityTime(activity.occurred_at)}</time>
                  </>
                }
              />
            );
          })}
        </ul>
      )}
    </Widget>
  );
}
