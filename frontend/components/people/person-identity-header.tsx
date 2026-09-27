"use client";

import Link from "next/link";
import { CalendarDays, Home, Info, UserRound, UsersRound } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { AppCard } from "@/components/shared/app-card";
import { Code } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { Initials } from "@/components/shared/initials";
import { EditPersonDialog } from "@/components/people/edit-person-dialog";
import type { PersonDetail } from "@/lib/types/api/person";
import { UNKNOWN_LABEL, calculateAge } from "@/lib/utils/date";
import { maritalStatusLabels } from "@/lib/utils/marital-status";
import { relationshipLabel } from "@/lib/utils/relationship";
import { cn } from "@/lib/utils";

export const genderLabel = (gender: PersonDetail["gender"] | null) =>
  gender === "MALE" ? "ذكر" : gender === "FEMALE" ? "أنثى" : "غير محدد";

/** life_status as recorded — UNKNOWN is never read as alive. */
export const lifeStatusLabels: Record<PersonDetail["life_status"], string> = {
  ALIVE: "على قيد الحياة",
  DECEASED: "متوفى",
  UNKNOWN: "غير معروفة",
};

// ------------------------------------------------------------------ quick facts

function Fact({
  icon: Icon,
  label,
  value,
  context,
  muted,
}: {
  icon: LucideIcon;
  label: string;
  value: React.ReactNode;
  context?: React.ReactNode;
  muted?: boolean;
}) {
  return (
    <div className="flex min-w-0 items-start gap-2.5 px-4 py-3 sm:px-5" data-fact={label}>
      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" strokeWidth={1.75} aria-hidden />
      <div className="flex min-w-0 flex-col gap-0.5">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className={cn("text-[15px] leading-snug", muted ? "font-normal text-muted-foreground" : "font-semibold text-foreground")}>
          {value}
        </span>
        {context && <span className="truncate text-xs text-muted-foreground">{context}</span>}
      </div>
    </div>
  );
}

/** Identity context from the Person record only: age, gender, marital and life status. */
function QuickFacts({ person }: { person: PersonDetail }) {
  return (
    <div
      className={cn(
        "grid grid-cols-2 border-t border-stroke-subtle bg-surface-2/60 md:grid-cols-4",
        // Hairline separators, RTL-safe (logical borders).
        "[&>*]:border-stroke-subtle [&>*:not(:last-child)]:border-e max-md:[&>*:nth-child(2n)]:border-e-0 max-md:[&>*:nth-child(n+3)]:border-t"
      )}
      aria-label="حقائق سريعة"
    >
      <Fact
        icon={CalendarDays}
        label="العمر"
        muted={!person.birth_date}
        value={person.birth_date ? <span className="tabular-nums">{calculateAge(person.birth_date)} سنة</span> : UNKNOWN_LABEL}
        context={person.birth_date ? <bdi dir="ltr" className="tabular-nums">{person.birth_date}</bdi> : "تاريخ الميلاد غير مسجّل"}
      />
      <Fact icon={UserRound} label="الجنس" value={genderLabel(person.gender)} muted={!person.gender} />
      <Fact
        icon={UsersRound}
        label="الحالة الاجتماعية"
        value={maritalStatusLabels[person.marital_status ?? "UNKNOWN"]}
        muted={!person.marital_status || person.marital_status === "UNKNOWN"}
      />
      <Fact
        icon={Info}
        label="الحالة الحياتية"
        value={lifeStatusLabels[person.life_status] ?? lifeStatusLabels.UNKNOWN}
        muted={person.life_status !== "ALIVE"}
      />
    </div>
  );
}

// ------------------------------------------------------------------ header

/** Current family in one line: head badge or relationship, then the Family code. */
function FamilyLine({ person }: { person: PersonDetail }) {
  const { can } = useAuth();
  const membership = person.family_membership;

  if (!membership) {
    return (
      <span className="flex items-center gap-1.5" data-no-current-family>
        <Home className="size-3.5 shrink-0" aria-hidden />
        لا توجد أسرة حالية
      </span>
    );
  }

  const code = <Code className="text-brand-800">{membership.family_code}</Code>;
  return (
    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
      {membership.is_household_head ? (
        <StatusBadge tone="brand">رب الأسرة</StatusBadge>
      ) : (
        <span className="font-medium text-foreground">{relationshipLabel(membership.relationship_type, person.gender)}</span>
      )}
      <span className="flex items-center gap-1.5">
        <Home className="size-3.5 shrink-0" aria-hidden />
        في الأسرة
        {can("family.view") ? (
          <Link
            href={`/families/${membership.family_code}`}
            className="rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring"
            data-family-link
          >
            {code}
          </Link>
        ) : (
          code
        )}
      </span>
    </span>
  );
}

/**
 * Person identity (existing data only): initials, name (the page title),
 * Person code, life and record status, the current family in one line,
 * the routine edit action, and the Quick Facts. Controlled corrections
 * (National ID) live with the sensitive data, not here.
 */
export function PersonIdentityHeader({ person }: { person: PersonDetail }) {
  const { can } = useAuth();
  const deceased = person.life_status === "DECEASED";

  return (
    <AppCard padded={false} className="overflow-hidden" aria-label="هوية الفرد">
      <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
        <div className="flex min-w-0 items-start gap-4">
          <Initials
            name={person.full_name}
            className={cn("size-14 text-lg", deceased ? "bg-surface-2 text-muted-foreground" : "bg-brand-50 text-brand-800")}
          />
          <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex flex-wrap items-center gap-2">
              <Code className="text-[13px] text-brand-800">{person.person_code}</Code>
              {deceased && <StatusBadge tone="neutral">متوفى</StatusBadge>}
              {!person.is_active && <StatusBadge tone="neutral">سجل غير نشط</StatusBadge>}
            </div>
            <h1 className="text-2xl leading-tight font-bold break-words text-foreground">{person.full_name}</h1>
            <div className="text-[13px] text-muted-foreground">
              <FamilyLine person={person} />
            </div>
          </div>
        </div>

        {can("person.update") && (
          <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
            <EditPersonDialog person={person} triggerLabel="تعديل البيانات" />
          </div>
        )}
      </div>

      <QuickFacts person={person} />
    </AppCard>
  );
}
