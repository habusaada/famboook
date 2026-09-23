import { ClipboardCheck } from "lucide-react";
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
import {
  summaryCards,
  recentFamilies,
  reviewTasks,
  type RecentFamily,
} from "@/lib/mock-data/dashboard";

function statusVariant(status: RecentFamily["status"]) {
  switch (status) {
    case "نشطة":
      return "default" as const;
    case "قيد المراجعة":
      return "secondary" as const;
    case "بحاجة لتحديث":
      return "destructive" as const;
  }
}

export default function DashboardPage() {
  return (
    <div className="flex flex-col gap-6">
      <div>
        <h2 className="text-2xl font-semibold tracking-tight">لوحة التحكم</h2>
        <p className="text-sm text-muted-foreground">
          نظرة عامة على سجل العائلات وإدارة البيانات
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {summaryCards.map((card) => (
          <Card key={card.label}>
            <CardHeader className="pb-2">
              <CardDescription>{card.label}</CardDescription>
              <CardTitle className="text-3xl font-semibold tabular-nums">
                {card.value}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xs text-muted-foreground">{card.hint}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader>
            <CardTitle>أحدث الأسر المسجلة</CardTitle>
            <CardDescription>آخر التحديثات على سجلات الأسر</CardDescription>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>رقم الأسرة</TableHead>
                  <TableHead>رب الأسرة</TableHead>
                  <TableHead>عدد الأفراد</TableHead>
                  <TableHead>الحالة</TableHead>
                  <TableHead>آخر تحديث</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {recentFamilies.map((family) => (
                  <TableRow key={family.familyCode}>
                    <TableCell className="font-medium">
                      {family.familyCode}
                    </TableCell>
                    <TableCell>{family.householdHead}</TableCell>
                    <TableCell className="tabular-nums">
                      {family.memberCount}
                    </TableCell>
                    <TableCell>
                      <Badge variant={statusVariant(family.status)}>
                        {family.status}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {family.updatedAt}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>مهام تحتاج إلى مراجعة</CardTitle>
            <CardDescription>عناصر بانتظار إجراء من الفريق</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {reviewTasks.map((task) => (
              <div
                key={task.title + task.context}
                className="flex items-start gap-3 rounded-lg border p-3"
              >
                <ClipboardCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div className="flex flex-col gap-0.5">
                  <span className="text-sm font-medium">{task.title}</span>
                  <span className="text-xs text-muted-foreground">
                    {task.context}
                  </span>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
