"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { AlertCircle, Eye, Search, X } from "lucide-react";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
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
import { useFamilies } from "@/lib/api/families";
import type { FamilyLifecycleStatus } from "@/lib/types/api/family";

const statusFilterOptions: { value: FamilyLifecycleStatus | "ALL"; label: string }[] = [
  { value: "ALL", label: "كل الحالات" },
  { value: "ACTIVE", label: "نشطة" },
  { value: "INACTIVE", label: "غير نشطة" },
  { value: "ARCHIVED", label: "مؤرشفة" },
];

export function FamiliesRegistry() {
  const router = useRouter();
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<FamilyLifecycleStatus | "ALL">("ALL");

  const { data, isLoading, isError, error, refetch } = useFamilies();
  const families = useMemo(() => data?.data ?? [], [data]);

  const summary = useMemo(
    () => ({
      total: families.length,
      active: families.filter((f) => f.status === "ACTIVE").length,
      inactive: families.filter((f) => f.status === "INACTIVE").length,
      archived: families.filter((f) => f.status === "ARCHIVED").length,
    }),
    [families]
  );

  const filteredFamilies = useMemo(() => {
    const query = search.trim().toLowerCase();

    return families.filter((family) => {
      const matchesStatus = statusFilter === "ALL" || family.status === statusFilter;
      if (!matchesStatus) return false;
      if (!query) return true;

      return (
        family.family_code.toLowerCase().includes(query) ||
        (family.household_head_name?.toLowerCase().includes(query) ?? false)
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
        <Button onClick={() => router.push("/families/new")}>إضافة أسرة</Button>
      </div>

      {isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تحميل سجل العائلات</AlertTitle>
          <AlertDescription className="flex flex-col gap-2">
            <span>
              {error instanceof Error
                ? error.message
                : "حدث خطأ غير متوقع أثناء الاتصال بالخادم."}
            </span>
            <Button
              variant="outline"
              size="sm"
              className="w-fit"
              onClick={() => refetch()}
            >
              إعادة المحاولة
            </Button>
          </AlertDescription>
        </Alert>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Card size="sm">
              <CardHeader className="pb-1">
                <CardDescription>إجمالي الأسر</CardDescription>
                <CardTitle className="text-2xl font-semibold tabular-nums">
                  {isLoading ? <Skeleton className="h-8 w-12" /> : summary.total}
                </CardTitle>
              </CardHeader>
            </Card>
            <Card size="sm">
              <CardHeader className="pb-1">
                <CardDescription>نشطة</CardDescription>
                <CardTitle className="text-2xl font-semibold tabular-nums">
                  {isLoading ? <Skeleton className="h-8 w-12" /> : summary.active}
                </CardTitle>
              </CardHeader>
            </Card>
            <Card size="sm">
              <CardHeader className="pb-1">
                <CardDescription>غير نشطة</CardDescription>
                <CardTitle className="text-2xl font-semibold tabular-nums">
                  {isLoading ? <Skeleton className="h-8 w-12" /> : summary.inactive}
                </CardTitle>
              </CardHeader>
            </Card>
            <Card size="sm">
              <CardHeader className="pb-1">
                <CardDescription>مؤرشفة</CardDescription>
                <CardTitle className="text-2xl font-semibold tabular-nums">
                  {isLoading ? <Skeleton className="h-8 w-12" /> : summary.archived}
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
                onValueChange={(value) =>
                  setStatusFilter(value as FamilyLifecycleStatus | "ALL")
                }
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
                  {isLoading ? (
                    Array.from({ length: 4 }).map((_, i) => (
                      <TableRow key={i}>
                        {Array.from({ length: 6 }).map((__, j) => (
                          <TableCell key={j}>
                            <Skeleton className="h-4 w-full max-w-32" />
                          </TableCell>
                        ))}
                      </TableRow>
                    ))
                  ) : filteredFamilies.length === 0 ? (
                    <TableRow>
                      <TableCell
                        colSpan={6}
                        className="py-10 text-center text-sm text-muted-foreground"
                      >
                        {families.length === 0
                          ? "لا توجد أسر مسجّلة بعد"
                          : "لا توجد نتائج مطابقة لبحثك"}
                      </TableCell>
                    </TableRow>
                  ) : (
                    filteredFamilies.map((family) => (
                      <TableRow
                        key={family.family_code}
                        className="cursor-pointer"
                        onClick={() => router.push(`/families/${family.family_code}`)}
                      >
                        <TableCell className="font-medium">
                          <span dir="ltr">{family.family_code}</span>
                        </TableCell>
                        <TableCell>{family.household_head_name ?? "—"}</TableCell>
                        <TableCell className="tabular-nums">
                          {family.member_count}
                        </TableCell>
                        <TableCell>
                          <FamilyStatusBadge status={family.status} />
                        </TableCell>
                        <TableCell className="text-muted-foreground">
                          {family.updated_at
                            ? new Date(family.updated_at).toLocaleString("ar", {
                                dateStyle: "medium",
                                timeStyle: "short",
                              })
                            : "—"}
                        </TableCell>
                        <TableCell className="text-end">
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            onClick={(event) => {
                              event.stopPropagation();
                              router.push(`/families/${family.family_code}`);
                            }}
                            aria-label="عرض الأسرة"
                          >
                            <Eye className="size-4" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}
