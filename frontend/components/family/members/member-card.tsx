import { CircleQuestionMark, EyeOff, Ribbon } from "lucide-react";
import type { FamilyMember } from "@/lib/api/family-household";
import { calculateAge } from "@/lib/utils/date";
import { relationshipLabel } from "@/lib/utils/relationship";

export const UNAVAILABLE_MEMBER = "بيانات هذا الفرد غير متاحة حاليًا";

const card = "rounded-2xl border border-border bg-surface-1 p-4";

/** A status shown as icon + text, never by colour alone. */
function Status({ icon: Icon, children, kind }: { icon: typeof Ribbon; children: string; kind: string }) {
  return (
    <span
      className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
      data-member-status={kind}
    >
      <Icon className="size-3.5" aria-hidden />
      {children}
    </span>
  );
}

function ageText(birthDate: string): string {
  const age = calculateAge(birthDate);
  return age < 1 ? "أقل من سنة" : String(age);
}

/**
 * One household member. The relationship and the head marker come from the
 * membership; the Person's details only when they are available. A deceased
 * member has no current age; a member whose data is unavailable shows no
 * Person detail at all.
 */
export function MemberCard({ member }: { member: FamilyMember }) {
  const relationship = relationshipLabel(member.relationship, member.gender);

  if (!member.available) {
    return (
      <li className={card} data-member-row="unavailable">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-subtle-foreground" aria-hidden>
            <EyeOff className="size-4" />
          </span>
          <div className="min-w-0">
            <p className="text-sm font-medium text-muted-foreground">{UNAVAILABLE_MEMBER}</p>
            <p className="mt-0.5 text-[13px] text-subtle-foreground" data-member-relationship>
              {relationship}
            </p>
          </div>
        </div>
      </li>
    );
  }

  const deceased = member.life_status === "DECEASED";

  return (
    <li className={card} data-member-row={member.is_household_head ? "head" : "member"}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-base leading-snug font-semibold text-foreground">
            <bdi>{member.full_name}</bdi>
          </p>
          {member.is_household_head ? (
            // The Family Portal user is the household head (V1 eligibility).
            <p className="mt-1 flex items-center gap-1.5" data-member-head>
              <span className="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-800">رب الأسرة</span>
              <span className="rounded-md border border-border px-1.5 py-0.5 text-[11px] font-medium text-subtle-foreground">أنت</span>
            </p>
          ) : (
            <p className="mt-0.5 text-sm text-muted-foreground" data-member-relationship>
              {relationship}
            </p>
          )}
        </div>
        {deceased && (
          <Status icon={Ribbon} kind="deceased">
            {member.gender === "FEMALE" ? "متوفاة" : "متوفى"}
          </Status>
        )}
        {member.life_status === "UNKNOWN" && (
          <Status icon={CircleQuestionMark} kind="unknown">
            الحالة غير مؤكدة
          </Status>
        )}
      </div>

      <dl className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[13px] text-muted-foreground">
        {member.life_status === "ALIVE" && member.birth_date && (
          <div className="flex gap-1" data-member-age>
            <dt>العمر:</dt>
            <dd className="font-medium text-foreground tabular-nums">{ageText(member.birth_date)}</dd>
          </div>
        )}
        <div className="flex gap-1" data-member-birth-date>
          <dt>تاريخ الميلاد:</dt>
          <dd className="font-medium text-foreground">
            {member.birth_date ? (
              <span dir="ltr" className="tabular-nums">
                {member.birth_date}
              </span>
            ) : (
              "غير معروف"
            )}
          </dd>
        </div>
      </dl>
    </li>
  );
}
