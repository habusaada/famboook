"use client";

import { useAuth } from "@/components/auth/auth-context";
import { useRouter } from "next/navigation";
import { Crown } from "lucide-react";
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
import { Code, Panel, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { ageLabel, birthDateLabel } from "@/lib/utils/date";
import { relationshipLabel } from "@/lib/utils/relationship";
import type { FamilyDetail } from "@/lib/types/api/family";

const head = "h-10 text-xs font-medium text-muted-foreground";

export function FamilyMembersTable({ family }: { family: FamilyDetail }) {
  const { can } = useAuth();
  const router = useRouter();
  const canCorrect = can("family-membership.update") || can("family-membership.end");

  return (
    <Panel flush>
      <SectionHeader
        className="p-5"
        title="أفراد الأسرة"
        description={`${family.members.length.toLocaleString("ar")} فرد حالي ضمن الأسرة`}
        action={can("person.create") ? <AddMemberDialog familyCode={family.family_code} /> : undefined}
      />
      <div className="overflow-x-auto border-t">
        <Table>
          <TableHeader className="bg-secondary/60">
            <TableRow className="hover:bg-transparent">
              <TableHead className={`${head} ps-5`}>الاسم الكامل</TableHead>
              <TableHead className={head}>صلة القرابة</TableHead>
              <TableHead className={head}>الجنس</TableHead>
              <TableHead className={head}>تاريخ الميلاد</TableHead>
              <TableHead className={head}>العمر</TableHead>
              <TableHead className={head}>الحالة</TableHead>
              {canCorrect && (
                <TableHead className={`${head} w-12 pe-5`}>
                  <span className="sr-only">إجراءات التصحيح</span>
                </TableHead>
              )}
            </TableRow>
          </TableHeader>
          <TableBody>
            {family.members.map((member) => (
              <TableRow
                key={member.person_code}
                className="h-14 cursor-pointer hover:bg-brand-50/50"
                onClick={() => router.push(`/people/${member.person_code}`)}
              >
                <TableCell className="ps-5">
                  <div className="flex items-center gap-2">
                    {member.is_household_head && (
                      <Crown className="size-3.5 shrink-0 text-brand-700" aria-label="رب الأسرة" />
                    )}
                    <div className="flex min-w-0 flex-col">
                      <span className="font-medium text-foreground">{member.full_name}</span>
                      <Code className="text-xs font-normal text-subtle-foreground">{member.person_code}</Code>
                    </div>
                  </div>
                </TableCell>
                <TableCell className={member.is_household_head ? "font-medium text-brand-800" : "text-muted-foreground"}>
                  {relationshipLabel(member.relationship_type, member.gender)}
                </TableCell>
                <TableCell>{member.gender === "MALE" ? "ذكر" : "أنثى"}</TableCell>
                <TableCell className={member.birth_date ? "tabular-nums" : "text-subtle-foreground"}>
                  {member.birth_date ? <bdi dir="ltr">{birthDateLabel(member.birth_date)}</bdi> : birthDateLabel(null)}
                </TableCell>
                <TableCell className={member.birth_date ? "tabular-nums" : "text-subtle-foreground"}>
                  {ageLabel(member.birth_date)}
                </TableCell>
                <TableCell>
                  <StatusBadge tone={member.is_active ? "success" : "neutral"}>
                    {member.is_active ? "نشط" : "غير نشط"}
                  </StatusBadge>
                </TableCell>
                {canCorrect && (
                  <TableCell className="w-12 p-1 pe-4">
                    <MemberCorrectionMenu familyCode={family.family_code} member={member} />
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </Panel>
  );
}
