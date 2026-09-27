"use client";

import { useAuth } from "@/components/auth/auth-context";
import { useRouter } from "next/navigation";
import { AlertCircle, ChevronLeft, Plus, Search, SearchX, Users, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { Code, PageHeader, Panel } from "@/components/shared/page-layout";
import { StatStrip } from "@/components/shared/stat-strip";
import { EmptyState } from "@/components/shared/empty-state";
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

const COLUMNS = 7;

/**
 * Family registry (docs/03 §93a): search, status filter and pagination run
 * on the server; state lives in the URL. The summary counts the whole
 * registry by status; the result count below the table is for the current
 * search.
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
  const filtered = Boolean(registry.q || status);
  const count = (n: number | undefined) => (n === undefined ? undefined : n.toLocaleString("ar"));
  const addFamily = can("family.create") ? (
    <Button onClick={() => router.push("/families/new")} className="h-10">
      <Plus className="size-4" />
      إضافة أسرة
    </Button>
  ) : null;

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="الأسر" description="سجل الأسر المسجّلة وأفرادها، ومتابعة حالة كل سجل" actions={addFamily} />

      {isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تحميل سجل الأسر</AlertTitle>
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
          <div data-registry-summary>
            <StatStrip
              size="md"
              items={[
                { label: "إجمالي الأسر في السجل", value: count(summary?.total) },
                { label: "نشطة", value: count(summary?.active) },
                { label: "غير نشطة", value: count(summary?.inactive) },
                { label: "مؤرشفة", value: count(summary?.archived) },
              ]}
            />
          </div>

          <Panel flush>
            {/* One toolbar: search, status filter, reset. */}
            <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-subtle-foreground" />
                <Input
                  type="search"
                  value={registry.text}
                  onChange={(event) => registry.setText(event.target.value)}
                  placeholder="ابحث برقم الأسرة أو اسم أحد أفرادها أو رقم الفرد..."
                  aria-label="بحث في سجل العائلات"
                  className="h-10 ps-9"
                  data-registry-search
                />
              </div>

              <Select value={status || ALL} onValueChange={(value) => registry.setFilter("status", value === ALL ? "" : value)}>
                <SelectTrigger className="h-10! w-full sm:w-48" aria-label="الحالة">
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
                <Button variant="ghost" onClick={registry.reset} className="h-10 text-muted-foreground sm:w-auto" data-registry-reset>
                  <X className="size-4" />
                  مسح البحث
                </Button>
              )}
            </div>

            <div className="overflow-x-auto" aria-busy={isFetching}>
              <Table className={isFetching && !isLoading ? "opacity-60 transition-opacity" : undefined}>
                <TableHeader className="bg-secondary/60">
                  <TableRow className="hover:bg-transparent">
                    <TableHead className="h-10 ps-5 text-xs font-medium text-muted-foreground">رقم الأسرة</TableHead>
                    <TableHead className="h-10 text-xs font-medium text-muted-foreground">رب الأسرة</TableHead>
                    <TableHead className="h-10 text-xs font-medium text-muted-foreground">الفرع</TableHead>
                    <TableHead className="h-10 text-xs font-medium text-muted-foreground">عدد الأفراد</TableHead>
                    <TableHead className="h-10 text-xs font-medium text-muted-foreground">الحالة</TableHead>
                    <TableHead className="h-10 text-xs font-medium text-muted-foreground">آخر تحديث</TableHead>
                    <TableHead className="h-10 pe-5 text-end text-xs font-medium text-muted-foreground">
                      <span className="sr-only">إجراءات</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isLoading ? (
                    Array.from({ length: 5 }).map((_, i) => (
                      <TableRow key={i} className="h-14">
                        {Array.from({ length: COLUMNS }).map((__, j) => (
                          <TableCell key={j} className="first:ps-5">
                            <Skeleton className="h-4 w-full max-w-32" />
                          </TableCell>
                        ))}
                      </TableRow>
                    ))
                  ) : families.length === 0 ? (
                    <TableRow className="hover:bg-transparent">
                      <TableCell colSpan={COLUMNS} className="p-0">
                        {filtered ? (
                          <EmptyState
                            icon={SearchX}
                            title="لا توجد أسر مطابقة"
                            description="جرّب تعديل عبارة البحث أو عوامل التصفية، أو ابحث برقم الأسرة أو اسم أحد أفرادها أو رقم الفرد."
                            action={
                              <Button variant="outline" onClick={registry.reset}>
                                <X className="size-4" />
                                مسح البحث
                              </Button>
                            }
                          />
                        ) : (
                          <EmptyState
                            icon={Users}
                            title="لا توجد أسر مسجّلة بعد"
                            description="ستظهر هنا الأسر فور تسجيلها."
                            action={addFamily}
                          />
                        )}
                      </TableCell>
                    </TableRow>
                  ) : (
                    families.map((family) => (
                      <TableRow
                        key={family.family_code}
                        className="group h-14 cursor-pointer hover:bg-brand-50/50"
                        data-family={family.family_code}
                        onClick={() => router.push(`/families/${family.family_code}`)}
                      >
                        <TableCell className="ps-5">
                          <Code className="text-sm text-brand-800">{family.family_code}</Code>
                        </TableCell>
                        <TableCell className="font-medium text-foreground">
                          {family.household_head_name ?? <span className="font-normal text-subtle-foreground">غير محدد</span>}
                        </TableCell>
                        <TableCell>
                          <span className={family.branch_name ? "text-foreground" : "text-subtle-foreground"}>
                            {family.branch_name ?? "بدون فرع"}
                          </span>
                          {family.clan_name && <span className="block text-xs text-subtle-foreground">{family.clan_name}</span>}
                        </TableCell>
                        <TableCell className="tabular-nums">{family.member_count.toLocaleString("ar")}</TableCell>
                        <TableCell>
                          <FamilyStatusBadge status={family.status} />
                        </TableCell>
                        <TableCell className="text-[13px] text-muted-foreground">
                          {family.updated_at
                            ? new Date(family.updated_at).toLocaleString("ar", { dateStyle: "medium", timeStyle: "short" })
                            : "—"}
                        </TableCell>
                        <TableCell className="pe-5 text-end">
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            className="text-subtle-foreground group-hover:text-brand-700"
                            onClick={(event) => {
                              event.stopPropagation();
                              router.push(`/families/${family.family_code}`);
                            }}
                            aria-label={`عرض الأسرة ${family.family_code}`}
                          >
                            <ChevronLeft className="size-4" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
            {data && (
              <div className="border-t">
                <RegistryPagination meta={data.meta} onPage={registry.setPage} unit={filtered ? "نتيجة" : "أسرة"} />
              </div>
            )}
          </Panel>
        </>
      )}
    </div>
  );
}
