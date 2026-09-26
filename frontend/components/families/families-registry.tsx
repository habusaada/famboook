"use client";

import { useAuth } from "@/components/auth/auth-context";
import { useRouter } from "next/navigation";
import { AlertCircle, Eye, Search, X } from "lucide-react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { useFamilies } from "@/lib/api/families";
import { useRegistrySearch } from "@/lib/hooks/use-registry-search";
import type { FamilyLifecycleStatus } from "@/lib/types/api/family";

const ALL = "ALL";

const statusFilterOptions: { value: FamilyLifecycleStatus | typeof ALL; label: string }[] = [
  { value: ALL, label: "كل الحالات" },
  { value: "ACTIVE", label: "نشطة" },
  { value: "INACTIVE", label: "غير نشطة" },
  { value: "ARCHIVED", label: "مؤرشفة" },
];

/**
 * Family registry (docs/03 §93a): search, status filter and pagination run
 * on the server; state lives in the URL. The cards count the whole registry
 * by status; the result count below the table is for the current search.
 */
export function FamiliesRegistry() {
  const router = useRouter();
  const { can } = useAuth();
  const registry = useRegistrySearch();
  const status = registry.param("status");

  const { data, isLoading, isFetching, isError, error, refetch } = useFamilies({
    search: registry.q,
    status,
    page: registry.page,
  });
  const families = data?.data ?? [];
  const summary = data?.summary;
  const hasActiveFilters = registry.text.trim() !== "" || status !== "";

  const stat = (label: string, value: number | undefined) => (
    <Card size="sm">
      <CardHeader className="pb-1">
        <CardDescription>{label}</CardDescription>
        <CardTitle className="text-2xl font-semibold tabular-nums">
          {value === undefined ? <Skeleton className="h-8 w-12" /> : value.toLocaleString("ar")}
        </CardTitle>
      </CardHeader>
    </Card>
  );

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h2 className="text-xl font-semibold tracking-tight">سجل العائلات</h2>
          <p className="text-sm text-muted-foreground">إدارة الأسر المسجلة ومتابعة حالة كل سجل</p>
        </div>
        {can("family.create") && <Button onClick={() => router.push("/families/new")}>إضافة أسرة</Button>}
      </div>

      {isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تحميل سجل العائلات</AlertTitle>
          <AlertDescription className="flex flex-col gap-2">
            <span>{error instanceof Error ? error.message : "حدث خطأ غير متوقع أثناء الاتصال بالخادم."}</span>
            <Button variant="outline" size="sm" className="w-fit" onClick={() => refetch()}>
              إعادة المحاولة
            </Button>
          </AlertDescription>
        </Alert>
      ) : (
        <>
          {/* Whole registry, independent of the search below. */}
          <div className="grid grid-cols-2 gap-3 xl:grid-cols-4" data-registry-summary>
            {stat("إجمالي الأسر في السجل", summary?.total)}
            {stat("نشطة", summary?.active)}
            {stat("غير نشطة", summary?.inactive)}
            {stat("مؤرشفة", summary?.archived)}
          </div>

          <Card size="sm">
            <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  type="search"
                  value={registry.text}
                  onChange={(event) => registry.setText(event.target.value)}
                  placeholder="ابحث برقم الأسرة أو اسم أحد أفرادها أو رقم الفرد..."
                  aria-label="بحث في سجل العائلات"
                  className="ps-8"
                  data-registry-search
                />
              </div>

              <Select value={status || ALL} onValueChange={(value) => registry.setFilter("status", value === ALL ? "" : value)}>
                <SelectTrigger className="sm:w-52" aria-label="الحالة">
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
                <Button variant="ghost" onClick={registry.reset} className="sm:w-auto" data-registry-reset>
                  <X className="size-4" />
                  مسح البحث
                </Button>
              )}
            </CardContent>
          </Card>

          <Card size="sm">
            <CardContent className="overflow-x-auto p-0" aria-busy={isFetching}>
              <Table className={isFetching && !isLoading ? "opacity-60 transition-opacity" : undefined}>
                <TableHeader>
                  <TableRow>
                    <TableHead>رقم الأسرة</TableHead>
                    <TableHead>رب الأسرة</TableHead>
                    <TableHead>العشيرة / العائلة — الفرع</TableHead>
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
                        {Array.from({ length: 7 }).map((__, j) => (
                          <TableCell key={j}>
                            <Skeleton className="h-4 w-full max-w-32" />
                          </TableCell>
                        ))}
                      </TableRow>
                    ))
                  ) : families.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={7} className="py-10 text-center text-sm text-muted-foreground">
                        {registry.q || status ? "لا توجد نتائج مطابقة لبحثك" : "لا توجد أسر مسجّلة بعد"}
                      </TableCell>
                    </TableRow>
                  ) : (
                    families.map((family) => (
                      <TableRow
                        key={family.family_code}
                        className="cursor-pointer"
                        data-family={family.family_code}
                        onClick={() => router.push(`/families/${family.family_code}`)}
                      >
                        <TableCell className="font-medium">
                          <span dir="ltr">{family.family_code}</span>
                        </TableCell>
                        <TableCell>{family.household_head_name ?? "—"}</TableCell>
                        <TableCell className="text-sm">
                          {family.clan_name ?? "—"}
                          <span className="block text-xs text-muted-foreground">{family.branch_name ?? "بدون فرع"}</span>
                        </TableCell>
                        <TableCell className="tabular-nums">{family.member_count}</TableCell>
                        <TableCell>
                          <FamilyStatusBadge status={family.status} />
                        </TableCell>
                        <TableCell className="text-muted-foreground">
                          {family.updated_at
                            ? new Date(family.updated_at).toLocaleString("ar", { dateStyle: "medium", timeStyle: "short" })
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
            {data && (
              <RegistryPagination
                meta={data.meta}
                onPage={registry.setPage}
                unit={registry.q || status ? "نتيجة" : "أسرة"}
              />
            )}
          </Card>
        </>
      )}
    </div>
  );
}
