"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Eye, Search, X } from "lucide-react";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import {
  getFamilyMemberCount,
  getHouseholdHead,
} from "@/lib/mock-data/families";
import { familyStatusLabels, type Family, type FamilyStatus } from "@/lib/types/family";

const statusFilterOptions: { value: FamilyStatus | "ALL"; label: string }[] = [
  { value: "ALL", label: "كل الحالات" },
  { value: "APPROVED", label: familyStatusLabels.APPROVED },
  { value: "PENDING_REVIEW", label: familyStatusLabels.PENDING_REVIEW },
  { value: "NEEDS_COMPLETION", label: familyStatusLabels.NEEDS_COMPLETION },
  { value: "INACTIVE", label: familyStatusLabels.INACTIVE },
];

export function FamiliesRegistry({ families }: { families: Family[] }) {
  const router = useRouter();
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<FamilyStatus | "ALL">("ALL");

  const summary = useMemo(
    () => ({
      total: families.length,
      approved: families.filter((f) => f.status === "APPROVED").length,
      pendingReview: families.filter((f) => f.status === "PENDING_REVIEW")
        .length,
      needsCompletion: families.filter((f) => f.status === "NEEDS_COMPLETION")
        .length,
    }),
    [families]
  );

  const filteredFamilies = useMemo(() => {
    const query = search.trim().toLowerCase();

    return families.filter((family) => {
      const matchesStatus =
        statusFilter === "ALL" || family.status === statusFilter;

      if (!matchesStatus) return false;
      if (!query) return true;

      const head = getHouseholdHead(family);
      return (
        family.familyCode.toLowerCase().includes(query) ||
        (head?.fullName.toLowerCase().includes(query) ?? false)
      );
    });
  }, [families, search, statusFilter]);

  const hasActiveFilters = search.trim() !== "" || statusFilter !== "ALL";

  function resetFilters() {
    setSearch("");
    setStatusFilter("ALL");
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h2 className="text-xl font-semibold tracking-tight">سجل العائلات</h2>
          <p className="text-sm text-muted-foreground">
            إدارة الأسر المسجلة ومتابعة حالة كل سجل
          </p>
        </div>
        <Button onClick={() => router.push("/families/new")}>
          إضافة أسرة
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>إجمالي الأسر</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {summary.total}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>الأسر المعتمدة</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {summary.approved}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>بانتظار المراجعة</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {summary.pendingReview}
            </CardTitle>
          </CardHeader>
        </Card>
        <Card size="sm">
          <CardHeader className="pb-1">
            <CardDescription>تحتاج استكمال</CardDescription>
            <CardTitle className="text-2xl font-semibold tabular-nums">
              {summary.needsCompletion}
            </CardTitle>
          </CardHeader>
        </Card>
      </div>

      <Card size="sm">
        <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="ابحث برقم الأسرة أو اسم رب الأسرة..."
              className="ps-8"
            />
          </div>

          <Select
            value={statusFilter}
            onValueChange={(value) => setStatusFilter(value as FamilyStatus | "ALL")}
          >
            <SelectTrigger className="sm:w-52">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {statusFilterOptions.map((option) => (
                <SelectItem key={option.value} value={option.value}>
                  {option.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          {hasActiveFilters && (
            <Button variant="ghost" onClick={resetFilters} className="sm:w-auto">
              <X className="size-4" />
              إعادة تعيين
            </Button>
          )}
        </CardContent>
      </Card>

      <Card size="sm">
        <CardContent className="overflow-x-auto p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>رقم الأسرة</TableHead>
                <TableHead>رب الأسرة</TableHead>
                <TableHead>عدد الأفراد</TableHead>
                <TableHead>الحالة</TableHead>
                <TableHead>آخر تحديث</TableHead>
                <TableHead className="text-end">إجراءات</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredFamilies.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={6}
                    className="py-10 text-center text-sm text-muted-foreground"
                  >
                    لا توجد نتائج مطابقة لبحثك
                  </TableCell>
                </TableRow>
              ) : (
                filteredFamilies.map((family) => {
                  const head = getHouseholdHead(family);

                  return (
                    <TableRow
                      key={family.familyCode}
                      className="cursor-pointer"
                      onClick={() =>
                        router.push(`/families/${family.familyCode}`)
                      }
                    >
                      <TableCell className="font-medium">
                        <span dir="ltr">{family.familyCode}</span>
                      </TableCell>
                      <TableCell>{head?.fullName ?? "—"}</TableCell>
                      <TableCell className="tabular-nums">
                        {getFamilyMemberCount(family)}
                      </TableCell>
                      <TableCell>
                        <FamilyStatusBadge status={family.status} />
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        {family.updatedAt}
                      </TableCell>
                      <TableCell className="text-end">
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          onClick={(event) => {
                            event.stopPropagation();
                            router.push(`/families/${family.familyCode}`);
                          }}
                          aria-label="عرض الأسرة"
                        >
                          <Eye className="size-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
