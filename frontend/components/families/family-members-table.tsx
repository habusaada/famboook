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
import { calculateAge } from "@/lib/mock-data/families";
import {
  memberStatusLabels,
  relationshipLabels,
  type Family,
} from "@/lib/types/family";

export function FamilyMembersTable({ family }: { family: Family }) {
  return (
    <Card size="sm">
      <CardHeader className="flex flex-row items-center justify-between gap-4 space-y-0">
        <div>
          <CardTitle>أفراد الأسرة</CardTitle>
          <CardDescription>
            {family.members.length} فرد مسجّل ضمن الأسرة
          </CardDescription>
        </div>
        <AddMemberDialog />
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
              <TableRow key={member.personCode}>
                <TableCell className="font-medium">
                  <div className="flex items-center gap-2">
                    {member.isHouseholdHead && (
                      <Crown className="size-4 shrink-0 text-primary" />
                    )}
                    <span>{member.fullName}</span>
                  </div>
                </TableCell>
                <TableCell>{relationshipLabels[member.relationship]}</TableCell>
                <TableCell>
                  {member.gender === "MALE" ? "ذكر" : "أنثى"}
                </TableCell>
                <TableCell className="tabular-nums" dir="ltr">
                  {member.birthDate}
                </TableCell>
                <TableCell className="tabular-nums">
                  {calculateAge(member.birthDate)}
                </TableCell>
                <TableCell>
                  <Badge
                    variant={
                      member.status === "ACTIVE" ? "default" : "outline"
                    }
                  >
                    {memberStatusLabels[member.status]}
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
