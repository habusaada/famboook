"use client";

import { useAuth } from "@/components/auth/auth-context";
import { useRouter } from "next/navigation";
import { ChevronLeft } from "lucide-react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { AddMemberDialog } from "@/components/families/add-member-dialog";
import { MemberCorrectionMenu } from "@/components/families/member-correction-dialogs";
import { AppCard } from "@/components/shared/app-card";
import { Initials } from "@/components/shared/initials";
import { Code, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { ageLabel, birthDateLabel } from "@/lib/utils/date";
import { relationshipLabel } from "@/lib/utils/relationship";
import type { FamilyDetail, FamilyMemberDetail } from "@/lib/types/api/family";
import { cn } from "@/lib/utils";

const head = "h-10 text-xs font-medium text-muted-foreground";

/** Person identity: initials, name, bidi-safe Person code. */
function MemberIdentity({ member }: { member: FamilyMemberDetail }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <Initials
        name={member.full_name}
        className={cn("size-9", member.is_household_head ? "bg-brand-700 text-white" : "bg-surface-2 text-muted-foreground")}
      />
      <div className="flex min-w-0 flex-col">
        <span className="truncate font-medium text-foreground">{member.full_name}</span>
        <Code className="text-xs font-normal text-subtle-foreground">{member.person_code}</Code>
      </div>
    </div>
  );
}

function Relationship({ member }: { member: FamilyMemberDetail }) {
  const label = relationshipLabel(member.relationship_type, member.gender);
  return member.is_household_head ? (
    <StatusBadge tone="brand">{label}</StatusBadge>
  ) : (
    <span className={member.relationship_type ? "text-foreground" : "text-subtle-foreground"}>{label}</span>
  );
}

export function FamilyMembersTable({ family }: { family: FamilyDetail }) {
  const { can } = useAuth();
  const router = useRouter();
  const canCorrect = can("family-membership.update") || can("family-membership.end");
  const open = (member: FamilyMemberDetail) => router.push(`/people/${member.person_code}`);
  // Household head first, then the stored order.
  const members = [...family.members].sort((a, b) => Number(b.is_household_head) - Number(a.is_household_head));

  return (
    <AppCard padded={false} className="overflow-hidden">
      <SectionHeader
        className="p-4 sm:p-5"
        title="أفراد الأسرة"
        description={`${family.members.length.toLocaleString("ar")} فرد حالي · ${family.male_count.toLocaleString("ar")} ذكور · ${family.female_count.toLocaleString("ar")} إناث`}
        action={can("person.create") ? <AddMemberDialog familyCode={family.family_code} /> : undefined}
      />

      {/* Desktop / tablet: enterprise table */}
      <div className="hidden border-t border-stroke-subtle md:block">
        <Table>
          <TableHeader className="bg-surface-2">
            <TableRow className="hover:bg-transparent">
              <TableHead className={`${head} ps-5`}>الفرد</TableHead>
              <TableHead className={head}>صلة القرابة</TableHead>
              <TableHead className={head}>الجنس</TableHead>
              <TableHead className={head}>تاريخ الميلاد</TableHead>
              <TableHead className={head}>العمر</TableHead>
              <TableHead className={head}>الحالة</TableHead>
              <TableHead className={`${head} w-14 pe-5`}>
                <span className="sr-only">إجراءات</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {members.map((member) => (
              <TableRow
                key={member.person_code}
                className="group h-16 cursor-pointer border-stroke-subtle transition-colors hover:bg-surface-hover"
                onClick={() => open(member)}
              >
                <TableCell className="ps-5">
                  <MemberIdentity member={member} />
                </TableCell>
                <TableCell>
                  <Relationship member={member} />
                </TableCell>
                <TableCell className="text-muted-foreground">{member.gender === "MALE" ? "ذكر" : "أنثى"}</TableCell>
                <TableCell className={member.birth_date ? "tabular-nums" : "text-subtle-foreground"}>
                  {member.birth_date ? <bdi dir="ltr">{birthDateLabel(member.birth_date)}</bdi> : birthDateLabel(null)}
                </TableCell>
                <TableCell className={member.birth_date ? "tabular-nums" : "text-subtle-foreground"}>{ageLabel(member.birth_date)}</TableCell>
                <TableCell>
                  <StatusBadge tone={member.is_active ? "success" : "neutral"}>{member.is_active ? "نشط" : "غير نشط"}</StatusBadge>
                </TableCell>
                <TableCell className="w-14 p-1 pe-4">
                  <div className="flex items-center justify-end gap-0.5">
                    {canCorrect && <MemberCorrectionMenu familyCode={family.family_code} member={member} />}
                    <ChevronLeft className="size-4 text-subtle-foreground transition-colors group-hover:text-brand-700" aria-hidden />
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {/* Phone: stacked member rows, no horizontal scrolling */}
      <ul className="divide-y divide-stroke-subtle border-t border-stroke-subtle md:hidden" aria-label="أفراد الأسرة">
        {members.map((member) => (
          <li key={member.person_code} className="flex items-center gap-2 px-4 py-3">
            <button
              type="button"
              onClick={() => open(member)}
              className="flex min-w-0 flex-1 flex-col gap-1.5 rounded-lg text-start focus-visible:outline-2 focus-visible:outline-ring"
            >
              <MemberIdentity member={member} />
              <span className="flex flex-wrap items-center gap-x-2 gap-y-1 ps-12 text-xs text-muted-foreground">
                <Relationship member={member} />
                <span aria-hidden>·</span>
                <span>{member.gender === "MALE" ? "ذكر" : "أنثى"}</span>
                <span aria-hidden>·</span>
                <span className={member.birth_date ? "tabular-nums" : "text-subtle-foreground"}>
                  {member.birth_date ? ageLabel(member.birth_date) : "العمر غير معروف"}
                </span>
              </span>
            </button>
            {canCorrect && <MemberCorrectionMenu familyCode={family.family_code} member={member} />}
          </li>
        ))}
      </ul>
    </AppCard>
  );
}
