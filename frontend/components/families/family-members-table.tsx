"use client";

import { useAuth } from "@/components/auth/auth-context";
import { useRouter } from "next/navigation";
import { Crown } from "lucide-react";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { AddMemberDialog } from "@/components/families/add-member-dialog";
import { ageLabel, birthDateLabel } from "@/lib/utils/date";
import { relationshipLabel } from "@/lib/utils/relationship";
import type { FamilyDetail } from "@/lib/types/api/family";

export function FamilyMembersTable({ family }: { family: FamilyDetail }) {
  const { can } = useAuth();
  const router = useRouter();

  return (
    <Card size="sm">
      <CardHeader className="flex flex-row items-center justify-between gap-4 space-y-0">
        <div>
          <CardTitle>أفراد الأسرة</CardTitle>
          <CardDescription>
            {family.members.length} فرد مسجّل ضمن الأسرة
          </CardDescription>
        </div>
        {can("person.create") && <AddMemberDialog familyCode={family.family_code} />}
      </CardHeader>
      <CardContent className="overflow-x-auto p-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>الاسم الكامل</TableHead>
              <TableHead>صلة القرابة</TableHead>
              <TableHead>الجنس</TableHead>
              <TableHead>تاريخ الميلاد</TableHead>
              <TableHead>العمر</TableHead>
              <TableHead>الحالة</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {family.members.map((member) => (
              <TableRow
                key={member.person_code}
                className="cursor-pointer"
                onClick={() => router.push(`/people/${member.person_code}`)}
              >
                <TableCell className="font-medium">
                  <div className="flex items-center gap-2">
                    {member.is_household_head && (
                      <Crown className="size-4 shrink-0 text-primary" />
                    )}
                    <span>{member.full_name}</span>
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  {relationshipLabel(member.relationship_type, member.gender)}
                </TableCell>
                <TableCell>
                  {member.gender === "MALE" ? "ذكر" : "أنثى"}
                </TableCell>
                <TableCell className="tabular-nums" dir={member.birth_date ? "ltr" : undefined}>
                  {birthDateLabel(member.birth_date)}
                </TableCell>
                <TableCell className="tabular-nums">{ageLabel(member.birth_date)}</TableCell>
                <TableCell>
                  <Badge variant={member.is_active ? "default" : "outline"}>
                    {member.is_active ? "نشط" : "غير نشط"}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
}
