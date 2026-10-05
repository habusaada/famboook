"use client";

import { ChevronLeft, ClipboardCheck, HeartHandshake, HeartPulse, Network, Tent, Users, UsersRound } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { AppCard } from "@/components/shared/app-card";
import { Code } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { Initials } from "@/components/shared/initials";
import { Skeleton } from "@/components/ui/skeleton";
import { AddMemberDialog } from "@/components/families/add-member-dialog";
import { EditFamilyRegistrationDialog } from "@/components/families/edit-family-registration-dialog";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import { RecordHouseholdDeclarationDialog } from "@/components/families/record-household-declaration-dialog";
import { AssessmentStatusBadge } from "@/components/assessments/assessment-badges";
import type { FamilySnapshot } from "@/components/families/family-snapshot";
import type { FamilyDetail } from "@/lib/types/api/family";
import { displacementStatusLabel } from "@/lib/utils/displacement";
import { healthRecordTypeLabels } from "@/lib/utils/health";
import { registrationSourceLabels } from "@/lib/utils/registration-source";
import { cn } from "@/lib/utils";

const nf = new Intl.NumberFormat("ar");
const fmt = (n: number) => nf.format(n);

/** Clan › Branch group › Branch, as far as it is recorded. */
function lineage(family: FamilyDetail): string[] {
  const group = family.branch?.group;
  const parts = [family.clan?.name, group?.name ?? group?.display_name ?? null, family.branch?.name].filter(
    (part): part is string => Boolean(part)
  );
  // An unnamed group displays its branches' names — never repeat the branch.
  return parts.filter((part, i) => parts.indexOf(part) === i);
}

// ------------------------------------------------------------------ quick facts

function Fact({
  icon: Icon,
  label,
  value,
  context,
  tone = "text-muted-foreground",
  loading,
}: {
  icon: LucideIcon;
  label: string;
  value?: React.ReactNode;
  context?: React.ReactNode;
  tone?: string;
  loading?: boolean;
}) {
  return (
    <div className="flex min-w-0 items-start gap-2.5 px-4 py-3 sm:px-5" data-fact={label}>
      <Icon className={cn("mt-0.5 size-4 shrink-0", tone)} strokeWidth={1.75} aria-hidden />
      <div className="flex min-w-0 flex-col gap-0.5">
        <span className="text-xs text-muted-foreground">{label}</span>
        {loading ? (
          <Skeleton className="h-5 w-16" />
        ) : (
          <span className="text-[15px] leading-snug font-semibold text-foreground">{value}</span>
        )}
        {!loading && context && <span className="truncate text-xs text-subtle-foreground">{context}</span>}
      </div>
    </div>
  );
}

/** Compact entity summary: only facts the current data (and permissions) support. */
function QuickFacts({ family, snapshot }: { family: FamilyDetail; snapshot: FamilySnapshot }) {
  const residence = family.residence;
  const displaced = residence?.displacement_status === "DISPLACED";
  const { health, needs, assessment } = snapshot;

  // Declared figures are independent source facts: shown as stated, never
  // summed, reconciled or compared with the registered members.
  const declaredChildren = [
    family.declared_living_sons != null ? `الأبناء الذكور ${fmt(family.declared_living_sons)}` : null,
    family.declared_living_daughters != null ? `البنات ${fmt(family.declared_living_daughters)}` : null,
  ].filter((part): part is string => part !== null);
  // When and from which source the current declaration was made.
  const declaredBasis =
    family.current_declaration_id == null
      ? null
      : [
          family.declared_at ? `بتاريخ ${family.declared_at}` : "تاريخ الإقرار غير معروف",
          family.declaration_source ? registrationSourceLabels[family.declaration_source] : null,
        ]
          .filter((part): part is string => part !== null)
          .join(" · ");

  const facts: React.ReactNode[] = [
    <Fact
      key="declared"
      icon={UsersRound}
      tone="text-brand-700"
      label="عدد أفراد الأسرة (المعلن)"
      value={family.declared_household_size == null ? "غير معلن" : <span className="tabular-nums">{fmt(family.declared_household_size)}</span>}
      context={
        declaredChildren.length > 0 || declaredBasis ? (
          <span className="flex flex-col" data-declaration-context>
            {declaredChildren.length > 0 && <span className="truncate">{declaredChildren.join(" · ")}</span>}
            {declaredBasis && <span className="truncate">{declaredBasis}</span>}
          </span>
        ) : undefined
      }
    />,
    <Fact
      key="members"
      icon={Users}
      tone="text-brand-700"
      label="المسجلون تفصيليًا"
      value={<span className="tabular-nums">{fmt(family.member_count)}</span>}
      context={`${fmt(family.male_count)} ذكور · ${fmt(family.female_count)} إناث`}
    />,
    <Fact
      key="displacement"
      icon={Tent}
      tone={displaced ? "text-warning" : "text-muted-foreground"}
      label="النزوح"
      value={displacementStatusLabel(residence?.displacement_status)}
      context={displaced ? (residence?.displacement_location_text ?? "مكان النزوح غير مسجّل") : undefined}
    />,
  ];

  if (health) {
    const types = [...new Set(health.activeRecords.map((r) => healthRecordTypeLabels[r.type]))];
    facts.push(
      <Fact
        key="health"
        icon={HeartPulse}
        tone="text-info"
        label="حالات صحية نشطة"
        loading={health.loading}
        value={health.error ? "—" : <span className="tabular-nums">{fmt(health.activeRecords.length)}</span>}
        context={types.length > 0 ? types.join(" · ") : "لا توجد حالات نشطة"}
      />
    );
  }

  if (needs) {
    const open = needs.summary?.open ?? 0;
    const urgent = needs.summary?.urgent_open ?? 0;
    facts.push(
      <Fact
        key="needs"
        icon={HeartHandshake}
        tone={urgent > 0 ? "text-danger" : "text-muted-foreground"}
        label="احتياجات مفتوحة"
        loading={needs.loading}
        value={needs.error ? "—" : <span className="tabular-nums">{fmt(open)}</span>}
        context={
          urgent > 0 ? <span className="font-medium text-danger">{fmt(urgent)} عاجلة</span> : open > 0 ? "لا يوجد عاجل" : undefined
        }
      />
    );
  }

  if (assessment) {
    const latest = assessment.latest;
    facts.push(
      <Fact
        key="assessment"
        icon={ClipboardCheck}
        tone="text-brand-700"
        label="آخر تقييم"
        loading={assessment.loading}
        value={
          assessment.error ? "—" : latest ? <AssessmentStatusBadge status={latest.status} /> : <span className="font-normal text-subtle-foreground">لا يوجد</span>
        }
        context={latest ? <bdi dir="ltr">{latest.assessment_date}</bdi> : undefined}
      />
    );
  }

  return (
    <div
      className={cn(
        "grid grid-cols-2 border-t border-stroke-subtle bg-surface-2/60 md:grid-cols-3",
        facts.length >= 6 ? "xl:grid-cols-6" : facts.length === 5 ? "xl:grid-cols-5" : facts.length === 4 ? "xl:grid-cols-4" : "xl:grid-cols-3",
        // Hairline separators between cells, RTL-safe (logical borders).
        "[&>*]:border-stroke-subtle [&>*:not(:last-child)]:border-e max-md:[&>*:nth-child(2n)]:border-e-0 max-md:[&>*:nth-child(n+3)]:border-t max-md:[&>*:last-child:nth-child(odd)]:col-span-2 md:max-xl:[&>*:nth-child(3n)]:border-e-0 md:max-xl:[&>*:nth-child(n+4)]:border-t"
      )}
      aria-label="حقائق سريعة"
    >
      {facts}
    </div>
  );
}

// ------------------------------------------------------------------ header

/**
 * Household identity (existing data only): the household head as the human
 * identity, the Family code, status, displacement state and lineage, the
 * permitted family-level actions, and the Quick Facts.
 */
export function FamilyIdentityHeader({ family, snapshot }: { family: FamilyDetail; snapshot: FamilySnapshot }) {
  const { can } = useAuth();
  const head = family.members.find((m) => m.is_household_head);
  const displaced = family.residence?.displacement_status === "DISPLACED";
  const path = lineage(family);
  const canAddMember = can("person.create");
  const canEdit = can("family.update");

  return (
    <AppCard padded={false} className="overflow-hidden" aria-label="هوية الأسرة">
      <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
        <div className="flex min-w-0 items-start gap-4">
          <Initials name={head?.full_name ?? family.family_code} className="size-14 text-lg" />
          <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex flex-wrap items-center gap-2">
              <Code className="text-[13px] text-brand-800">{family.family_code}</Code>
              <FamilyStatusBadge status={family.status} />
              {displaced && <StatusBadge tone="warning">نازحة</StatusBadge>}
            </div>
            <h1 className="text-2xl leading-tight font-bold text-foreground">
              {head ? head.full_name : <span className="text-subtle-foreground">رب الأسرة غير محدد</span>}
            </h1>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-muted-foreground">
              {head && <span>رب الأسرة</span>}
              {path.length > 0 && (
                <span className="flex min-w-0 flex-wrap items-center gap-1" aria-label="الانتماء">
                  <Network className="size-3.5 shrink-0 text-subtle-foreground" aria-hidden />
                  {path.map((part, i) => (
                    <span key={`${i}-${part}`} className="flex items-center gap-1">
                      {i > 0 && <ChevronLeft className="size-3 text-subtle-foreground" aria-hidden />}
                      <span className={i === path.length - 1 ? "font-medium text-foreground" : undefined}>{part}</span>
                    </span>
                  ))}
                </span>
              )}
              {!family.branch && <span className="text-subtle-foreground">بدون فرع</span>}
            </div>
          </div>
        </div>

        {(canAddMember || canEdit) && (
          <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
            {canEdit && <EditFamilyRegistrationDialog family={family} triggerLabel="تعديل بيانات الأسرة" />}
            {canEdit && <RecordHouseholdDeclarationDialog family={family} />}
            {canAddMember && <AddMemberDialog familyCode={family.family_code} />}
          </div>
        )}
      </div>

      <QuickFacts family={family} snapshot={snapshot} />
    </AppCard>
  );
}
