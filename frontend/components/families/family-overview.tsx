"use client";

import { ChevronLeft, ClipboardCheck, HeartHandshake, HeartPulse, Home, MapPin, StickyNote, Tent } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { EditPersonDialog } from "@/components/people/edit-person-dialog";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import type { FamilySnapshot } from "@/components/families/family-snapshot";
import { AppCard } from "@/components/shared/app-card";
import { ActivityItem } from "@/components/shared/activity-item";
import { EmptyState } from "@/components/shared/empty-state";
import { Initials } from "@/components/shared/initials";
import { Code, DetailItem, DetailList, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { Skeleton } from "@/components/ui/skeleton";
import { AssessmentRatingBadge, AssessmentStatusBadge } from "@/components/assessments/assessment-badges";
import { activitySubject } from "@/components/families/family-activity-tab";
import { usePerson } from "@/lib/api/people";
import type { FamilyDetail } from "@/lib/types/api/family";
import { familyActivityPresentation, formatActivityTime } from "@/lib/utils/activity";
import { birthDateLabel, formatDateTime } from "@/lib/utils/date";
import { displacementStatusLabel } from "@/lib/utils/displacement";
import { lifeStatusLabel } from "@/lib/utils/life-status";
import { registrationSourceLabels } from "@/lib/utils/registration-source";
import { cn } from "@/lib/utils";

// Established wording: "غير مسجّل" = not recorded (optional fields are not
// errors); "غير محدد" / "غير معروف" keep their domain meanings.
const NOT_RECORDED = "غير مسجّل";
const nf = new Intl.NumberFormat("ar");

/** Label/value row, also used elsewhere in the profile. */
export function InfoRow({ label, value, ltr }: { label: string; value: string; ltr?: boolean }) {
  return (
    <div className="flex items-baseline justify-between gap-4 border-b border-border/70 py-2 text-sm last:border-0">
      <span className="text-[13px] font-medium text-muted-foreground">{label}</span>
      <span className="text-end font-medium" dir={ltr ? "ltr" : undefined}>
        {value}
      </span>
    </div>
  );
}

function Missing({ children = NOT_RECORDED }: { children?: string }) {
  return <span className="font-normal text-subtle-foreground">{children}</span>;
}

// ------------------------------------------------------------------ residence

function ResidenceBlock({
  icon: Icon,
  title,
  tone,
  action,
  children,
}: {
  icon: LucideIcon;
  title: string;
  tone: string;
  action?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <AppCard variant="subtle" padded={false} className="flex flex-col gap-2.5 p-4">
      <div className="flex items-center justify-between gap-2">
        <span className="flex items-center gap-2 text-[13px] font-semibold text-foreground">
          <Icon className={cn("size-4", tone)} strokeWidth={1.75} aria-hidden />
          {title}
        </span>
        {action}
      </div>
      <div className="flex flex-col gap-1.5 text-sm">{children}</div>
    </AppCard>
  );
}

/**
 * Original residence, displacement and current residence as three distinct
 * facts. Governorate/city are optional in the Pilot; free text may be the
 * authoritative value.
 */
export function ResidenceSummary({
  residence,
  displacementAction,
  residenceAction,
}: {
  residence: NonNullable<FamilyDetail["residence"]>;
  displacementAction?: React.ReactNode;
  residenceAction?: React.ReactNode;
}) {
  const displaced = residence.displacement_status === "DISPLACED";
  const currentParts = [
    residence.neighborhood,
    residence.area,
    residence.city,
    residence.governorate,
  ].filter(Boolean) as string[];

  return (
    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
      <ResidenceBlock icon={Home} tone="text-muted-foreground" title="السكن الأصلي (قبل النزوح)">
        <span className="font-medium">{residence.original_residence_text ?? <Missing />}</span>
      </ResidenceBlock>

      <ResidenceBlock icon={Tent} tone={displaced ? "text-warning" : "text-muted-foreground"} title="النزوح" action={displacementAction}>
        <span>
          {displaced ? (
            <StatusBadge tone="warning">نازحة</StatusBadge>
          ) : residence.displacement_status === "NOT_DISPLACED" ? (
            <StatusBadge tone="neutral">{displacementStatusLabel(residence.displacement_status)}</StatusBadge>
          ) : (
            <Missing>{displacementStatusLabel(null)}</Missing>
          )}
        </span>
        {displaced && (
          <span className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">مكان النزوح الحالي</span>
            <span className="font-medium">{residence.displacement_location_text ?? <Missing />}</span>
          </span>
        )}
      </ResidenceBlock>

      <ResidenceBlock icon={MapPin} tone="text-brand-700" title="السكن الحالي" action={residenceAction}>
        {currentParts.length > 0 ? (
          <span className="font-medium">{currentParts.join("، ")}</span>
        ) : (
          <span className="text-subtle-foreground">المحافظة والمدينة غير مسجّلتين (اختياريتان)</span>
        )}
        {residence.address_text && <span className="text-muted-foreground">{residence.address_text}</span>}
        {residence.started_at && (
          <span className="text-xs text-subtle-foreground">
            منذ <bdi dir="ltr">{residence.started_at}</bdi>
          </span>
        )}
      </ResidenceBlock>
    </div>
  );
}

// ------------------------------------------------------------------ operational summaries

function SummaryCard({
  icon,
  tone,
  title,
  onOpen,
  openLabel,
  children,
}: {
  icon: LucideIcon;
  tone: "brand" | "info" | "danger" | "neutral";
  title: string;
  onOpen?: () => void;
  openLabel?: string;
  children: React.ReactNode;
}) {
  return (
    <AppCard className="flex h-full flex-col">
      <SectionHeader
        icon={icon}
        tone={tone}
        title={title}
        action={
          onOpen && (
            <button
              type="button"
              onClick={onOpen}
              className="flex h-8 items-center gap-0.5 rounded-control px-2 text-xs font-medium text-brand-700 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
            >
              {openLabel}
              <ChevronLeft className="size-3.5" />
            </button>
          )
        }
      />
      <div className="mt-3 flex flex-1 flex-col gap-3">{children}</div>
    </AppCard>
  );
}

function MetricRow({ label, value, emphasis }: { label: string; value: number; emphasis?: string }) {
  return (
    <div className="flex items-center justify-between gap-3 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <bdi className={cn("font-semibold tabular-nums", emphasis ?? "text-foreground")}>{nf.format(value)}</bdi>
    </div>
  );
}

function Loading() {
  return (
    <div className="flex flex-col gap-2">
      <Skeleton className="h-4 w-full" />
      <Skeleton className="h-4 w-2/3" />
    </div>
  );
}

function OperationalSummaries({ snapshot, onOpenTab }: { snapshot: FamilySnapshot; onOpenTab: (tab: string) => void }) {
  const { health, needs, assessment } = snapshot;
  if (!health && !needs && !assessment) return null;

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      {health && (
        <SummaryCard icon={HeartPulse} tone="info" title="المؤشرات الصحية" onOpen={() => onOpenTab("health")} openLabel="الحالة الصحية">
          {health.loading ? (
            <Loading />
          ) : !health.summary ? (
            <p className="text-sm text-muted-foreground">تعذّر تحميل المؤشرات.</p>
          ) : (
            <div className="flex flex-col gap-2">
              <MetricRow label="ذوو إعاقة" value={health.summary.disability_persons} />
              <MetricRow label="أمراض مزمنة" value={health.summary.chronic_disease_persons} />
              <MetricRow label="حمل نشط" value={health.summary.pregnant} />
              <MetricRow label="رضاعة نشطة" value={health.summary.breastfeeding} />
              <MetricRow label="أطفال دون سنتين" value={health.summary.under_two} />
            </div>
          )}
        </SummaryCard>
      )}

      {needs && (
        <SummaryCard icon={HeartHandshake} tone="danger" title="الاحتياجات" onOpen={() => onOpenTab("needs")} openLabel="كل الاحتياجات">
          {needs.loading ? (
            <Loading />
          ) : !needs.summary ? (
            <p className="text-sm text-muted-foreground">تعذّر تحميل الاحتياجات.</p>
          ) : (
            <>
              <div className="flex items-end gap-2">
                <span className="text-[28px] leading-none font-bold tabular-nums">{nf.format(needs.summary.open)}</span>
                <span className="pb-0.5 text-[13px] text-muted-foreground">احتياج مفتوح</span>
                {needs.summary.urgent_open > 0 && (
                  <StatusBadge tone="danger" className="ms-auto">
                    {nf.format(needs.summary.urgent_open)} عاجلة
                  </StatusBadge>
                )}
              </div>
              {needs.open.length > 0 ? (
                <ul className="flex flex-col divide-y divide-stroke-subtle text-sm">
                  {needs.open.slice(0, 3).map((n) => (
                    <li key={n.id} className="flex items-center justify-between gap-3 py-1.5">
                      <span className="min-w-0 truncate">{n.title}</span>
                      <span className="shrink-0 text-xs text-subtle-foreground">{n.category.name}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted-foreground">لا توجد احتياجات مفتوحة.</p>
              )}
              <p className="mt-auto text-xs text-subtle-foreground">
                تمت تلبيتها <bdi>{nf.format(needs.summary.fulfilled)}</bdi> · مغلقة <bdi>{nf.format(needs.summary.closed)}</bdi>
              </p>
            </>
          )}
        </SummaryCard>
      )}

      {assessment && (
        <SummaryCard icon={ClipboardCheck} tone="brand" title="آخر تقييم" onOpen={() => onOpenTab("assessments")} openLabel="كل التقييمات">
          {assessment.loading ? (
            <Loading />
          ) : !assessment.latest ? (
            <p className="text-sm text-muted-foreground">لا توجد تقييمات مسجّلة لهذه الأسرة بعد.</p>
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-2">
                <AssessmentStatusBadge status={assessment.latest.status} />
                <span className="text-sm font-medium">
                  بتاريخ <bdi dir="ltr">{assessment.latest.assessment_date}</bdi>
                </span>
              </div>
              <p className="text-xs text-muted-foreground">
                {nf.format(assessment.latest.assessed_domain_count)} من المجالات مُقيَّمة
              </p>
              {assessment.latest.status === "COMPLETED" && assessment.latest.ratings.length > 0 && (
                <ul className="flex flex-col divide-y divide-stroke-subtle text-sm">
                  {assessment.latest.ratings.map(({ domain, rating }) => (
                    <li key={domain.code} className="flex items-center justify-between gap-3 py-1.5">
                      <span className="min-w-0 truncate">{domain.name}</span>
                      <AssessmentRatingBadge rating={rating} />
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </SummaryCard>
      )}
    </div>
  );
}

// ------------------------------------------------------------------ overview

export function FamilyOverview({
  family,
  snapshot,
  onOpenTab,
}: {
  family: FamilyDetail;
  snapshot: FamilySnapshot;
  onOpenTab: (tab: string) => void;
}) {
  const { can } = useAuth();
  const head = family.members.find((m) => m.is_household_head);
  // The head is a Person: contact details, life status and the edit
  // dialog come from the existing Person endpoint.
  const { data: headPersonData } = usePerson(head?.person_code ?? "");
  const headPerson = headPersonData?.data;
  const group = family.branch?.group;

  return (
    <div className="flex flex-col gap-5">
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
        {/* Registration record */}
        <AppCard className="xl:col-span-7">
          <SectionHeader title="معلومات الأسرة" description="بيانات تسجيل الأسرة في السجل" />
          <DetailList className="mt-3">
            <DetailItem label="رقم الأسرة">
              <Code>{family.family_code}</Code>
            </DetailItem>
            <DetailItem label="الحالة">
              <FamilyStatusBadge status={family.status} />
            </DetailItem>
            <DetailItem label="العشيرة / العائلة">
              {family.clan ? family.clan.name + (family.clan.is_active ? "" : " (غير مفعّلة)") : <Missing />}
            </DetailItem>
            <DetailItem label="الفرع">
              {family.branch ? family.branch.name + (family.branch.is_active ? "" : " (غير مفعّل)") : <Missing>غير محدد</Missing>}
            </DetailItem>
            {(group?.name || group?.display_name) && (
              <DetailItem label="مجموعة الفروع">{group?.name ?? group?.display_name}</DetailItem>
            )}
            <DetailItem label="رقم النموذج الورقي" ltr={!!family.paper_form_no}>
              {family.paper_form_no ?? <Missing />}
            </DetailItem>
            <DetailItem label="تاريخ التسجيل" ltr={!!family.registration_date}>
              {family.registration_date ?? <Missing />}
            </DetailItem>
            <DetailItem label="مصدر التسجيل">
              {family.registration_source
                ? (registrationSourceLabels[family.registration_source] ?? family.registration_source)
                : <Missing />}
            </DetailItem>
            {family.updated_at && (
              <DetailItem label="آخر تحديث">
                <span className="font-normal text-muted-foreground">{formatDateTime(family.updated_at)}</span>
              </DetailItem>
            )}
          </DetailList>
        </AppCard>

        {/* Household head: a Person */}
        <AppCard className="xl:col-span-5">
          <SectionHeader
            title="رب الأسرة"
            description="الشخص المسؤول عن الأسرة حالياً"
            action={
              headPerson && can("person.update") ? (
                <EditPersonDialog
                  person={headPerson}
                  title="تعديل بيانات رب الأسرة"
                  description="تصحيح البيانات الأساسية لرب الأسرة الحالي. لا يغيّر من هو رب الأسرة ولا عضوية الأسرة."
                />
              ) : undefined
            }
          />
          {head ? (
            <>
              <div className="mt-3 flex items-center gap-3 rounded-lg bg-surface-2 p-3">
                <Initials name={head.full_name} className="size-10 text-sm" />
                <div className="flex min-w-0 flex-col">
                  <span className="truncate font-semibold">{head.full_name}</span>
                  <Code className="text-xs font-normal text-subtle-foreground">{head.person_code}</Code>
                </div>
              </div>
              <DetailList className="mt-2 sm:grid-cols-1">
                <DetailItem label="الجنس">{head.gender === "MALE" ? "ذكر" : "أنثى"}</DetailItem>
                <DetailItem label="تاريخ الميلاد" ltr={!!head.birth_date}>
                  {head.birth_date ? birthDateLabel(head.birth_date) : <Missing>{birthDateLabel(null)}</Missing>}
                </DetailItem>
                {headPerson && (
                  <>
                    <DetailItem label="الحالة">{lifeStatusLabel(headPerson.life_status)}</DetailItem>
                    <DetailItem label="الجوال الأساسي" ltr={!!headPerson.mobile}>
                      {headPerson.mobile ?? <Missing />}
                    </DetailItem>
                    {headPerson.alternate_mobile && (
                      <DetailItem label="الجوال البديل" ltr>
                        {headPerson.alternate_mobile}
                      </DetailItem>
                    )}
                    {headPerson.alternate_mobile && headPerson.alternate_mobile_owner_relation && (
                      <DetailItem label="صاحب الرقم البديل / صلته">{headPerson.alternate_mobile_owner_relation}</DetailItem>
                    )}
                  </>
                )}
              </DetailList>
            </>
          ) : (
            <p className="mt-3 text-sm text-muted-foreground">لم يتم تحديد رب الأسرة بعد</p>
          )}
        </AppCard>
      </div>

      {/* Residence & displacement */}
      <AppCard>
        <SectionHeader
          title="السكن والنزوح"
          description="التعديل من تبويب «السكن»"
          action={
            <button
              type="button"
              onClick={() => onOpenTab("residence")}
              className="flex h-8 items-center gap-0.5 rounded-control px-2 text-xs font-medium text-brand-700 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
            >
              السكن
              <ChevronLeft className="size-3.5" />
            </button>
          }
        />
        <div className="mt-3">
          {family.residence ? (
            <ResidenceSummary residence={family.residence} />
          ) : (
            <p className="text-sm text-muted-foreground">لا يوجد سكن حالي مسجّل</p>
          )}
        </div>
      </AppCard>

      <OperationalSummaries snapshot={snapshot} onOpenTab={onOpenTab} />

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
        {snapshot.activity && (
          <AppCard className={family.notes ? "xl:col-span-7" : "xl:col-span-12"}>
            <SectionHeader
              title="آخر النشاطات"
              description="أحدث العمليات على الأسرة وأفرادها"
              action={
                <button
                  type="button"
                  onClick={() => onOpenTab("history")}
                  className="flex h-8 items-center gap-0.5 rounded-control px-2 text-xs font-medium text-brand-700 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
                >
                  السجل الكامل
                  <ChevronLeft className="size-3.5" />
                </button>
              }
            />
            <div className="mt-3">
              {snapshot.activity.loading ? (
                <Loading />
              ) : snapshot.activity.recent.length === 0 ? (
                <EmptyState icon={StickyNote} title="لا توجد أنشطة مسجّلة لهذه الأسرة بعد" className="py-6" />
              ) : (
                <ul className="flex flex-col">
                  {snapshot.activity.recent.map((a, i) => (
                    <ActivityItem
                      key={a.id}
                      icon={familyActivityPresentation[a.event_type].icon}
                      title={familyActivityPresentation[a.event_type].label}
                      entity={activitySubject(a) ?? undefined}
                      meta={
                        <>
                          {a.actor?.name ?? "النظام"}
                          <span className="mx-1.5">·</span>
                          <time dateTime={a.occurred_at}>{formatActivityTime(a.occurred_at)}</time>
                        </>
                      }
                      last={i === snapshot.activity!.recent.length - 1}
                    />
                  ))}
                </ul>
              )}
            </div>
          </AppCard>
        )}

        {family.notes && (
          <AppCard variant="subtle" className={snapshot.activity ? "xl:col-span-5" : "xl:col-span-12"}>
            <SectionHeader title="ملاحظات" />
            <p className="mt-2 text-sm leading-relaxed whitespace-pre-line text-muted-foreground">{family.notes}</p>
          </AppCard>
        )}
      </div>
    </div>
  );
}
