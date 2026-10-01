"use client";

import Link from "next/link";
import { useAuth } from "@/components/auth/auth-context";
import { useRouter } from "next/navigation";
import { AlertCircle, ChevronLeft, Home, Loader2, Plus, Search, SearchX, Users, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { AppCard } from "@/components/shared/app-card";
import { IconBox } from "@/components/shared/icon-box";
import { Code, PageHeader } from "@/components/shared/page-layout";
import { EmptyState } from "@/components/shared/empty-state";
import { useFamilies } from "@/lib/api/families";
import { useRegistrySearch } from "@/lib/hooks/use-registry-search";
import type { FamilyLifecycleStatus, FamilySummary } from "@/lib/types/api/family";
import { cn } from "@/lib/utils";

const ALL = "ALL";
const fmt = (n: number) => n.toLocaleString("ar");

const statusLabels: Record<FamilyLifecycleStatus, string> = {
  ACTIVE: "نشطة",
  INACTIVE: "غير نشطة",
  ARCHIVED: "مؤرشفة",
};

const statusFilterOptions: { value: FamilyLifecycleStatus | typeof ALL; label: string }[] = [
  { value: ALL, label: "كل الحالات" },
  { value: "ACTIVE", label: statusLabels.ACTIVE },
  { value: "INACTIVE", label: statusLabels.INACTIVE },
  { value: "ARCHIVED", label: statusLabels.ARCHIVED },
];

const updatedLabel = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString("ar", { day: "numeric", month: "short", year: "numeric" }) : "—";

const head = "h-10 text-xs font-medium text-muted-foreground";

// ------------------------------------------------------------------ building blocks

/** Family marker + bidi-safe code (the link to the Family Profile). */
function FamilyIdentity({ family }: { family: FamilySummary }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <IconBox icon={Home} tone="brand" size="sm" />
      <div className="flex min-w-0 flex-col">
        <Link
          href={`/families/${family.family_code}`}
          onClick={(e) => e.stopPropagation()}
          className="w-fit rounded-sm font-semibold text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
        >
          <Code>{family.family_code}</Code>
        </Link>
        {family.registration_date && (
          <span className="text-xs text-subtle-foreground">
            مسجّلة <bdi dir="ltr">{family.registration_date}</bdi>
          </span>
        )}
      </div>
    </div>
  );
}

function HeadName({ family, className }: { family: FamilySummary; className?: string }) {
  return family.household_head_name ? (
    <span className={cn("font-medium text-foreground", className)}>{family.household_head_name}</span>
  ) : (
    <span className={cn("text-subtle-foreground", className)}>رب الأسرة غير محدد</span>
  );
}

function Branch({ family }: { family: FamilySummary }) {
  return (
    <div className="flex min-w-0 flex-col">
      <span className={family.branch_name ? "truncate text-foreground" : "text-subtle-foreground"}>{family.branch_name ?? "بدون فرع"}</span>
      {family.clan_name && <span className="truncate text-xs text-subtle-foreground">{family.clan_name}</span>}
    </div>
  );
}

function SkeletonRows() {
  return (
    <div className="flex flex-col divide-y divide-stroke-subtle" aria-busy="true">
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-4 py-3.5 sm:px-5">
          <Skeleton className="size-8 rounded-control" />
          <Skeleton className="h-4 w-28" />
          <Skeleton className="h-4 w-44" />
          <Skeleton className="ms-auto hidden h-4 w-24 sm:block" />
        </div>
      ))}
    </div>
  );
}

// ------------------------------------------------------------------ registry

/**
 * Family registry (docs/03 §93a): search, status filter and pagination run
 * on the server; state lives in the URL. The summary counts the whole
 * registry by status (independent of the search); the results bar counts
 * what matches the current search/filter.
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
  const canCreate = can("family.create");
  const open = (family: FamilySummary) => router.push(`/families/${family.family_code}`);

  const addFamily = canCreate ? (
    <Button onClick={() => router.push("/families/new")} className="h-10">
      <Plus className="size-4" />
      إضافة أسرة
    </Button>
  ) : null;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title="الأسر" description="ابحث عن أسرة وافتح ملفها، أو سجّل أسرة جديدة" actions={addFamily} />

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
          {/* Registry summary: the whole registry by status (not the search). */}
          <AppCard padded={false} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-6 sm:px-5" data-registry-summary>
            <div className="flex items-center gap-3">
              <IconBox icon={Users} size="sm" />
              <div className="flex items-baseline gap-2">
                {summary ? (
                  <bdi className="text-xl font-bold tabular-nums">{fmt(summary.total)}</bdi>
                ) : (
                  <Skeleton className="h-6 w-10" />
                )}
                <span className="text-[13px] text-muted-foreground">أسرة في السجل</span>
              </div>
            </div>
            <div className="hidden h-6 w-px bg-stroke-subtle sm:block" aria-hidden />
            <dl className="flex flex-wrap items-center gap-x-5 gap-y-1 text-[13px]">
              {(["ACTIVE", "INACTIVE", "ARCHIVED"] as const).map((s) => (
                <div key={s} className="flex items-center gap-1.5" data-stat={statusLabels[s]}>
                  <dt className="flex items-center gap-1.5 text-muted-foreground">
                    <span aria-hidden className={cn("size-1.5 rounded-full", s === "ACTIVE" ? "bg-success" : "bg-subtle-foreground/50")} />
                    {statusLabels[s]}
                  </dt>
                  <dd className="font-semibold tabular-nums text-foreground">
                    {summary ? fmt(summary[s.toLowerCase() as "active" | "inactive" | "archived"]) : "…"}
                  </dd>
                </div>
              ))}
            </dl>
          </AppCard>

          <AppCard padded={false} className="overflow-hidden">
            {/* Toolbar: search is the primary tool; status filter; reset. */}
            <div className="flex flex-col gap-2.5 p-3 sm:flex-row sm:items-center sm:p-4" role="search">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute start-3.5 top-1/2 size-[18px] -translate-y-1/2 text-subtle-foreground" aria-hidden />
                <Input
                  type="search"
                  value={registry.text}
                  onChange={(event) => registry.setText(event.target.value)}
                  placeholder="ابحث برقم الأسرة أو الاسم أو رقم الفرد"
                  aria-label="بحث في سجل العائلات"
                  className="h-11 bg-surface-2 ps-10 pe-10 text-[15px] transition-colors focus-visible:bg-surface-1 [&::-webkit-search-cancel-button]:hidden"
                  data-registry-search
                />
                {registry.text && (
                  <button
                    type="button"
                    onClick={() => registry.setText("")}
                    aria-label="مسح نص البحث"
                    className="absolute end-2.5 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-control text-subtle-foreground transition-colors hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                  >
                    <X className="size-4" />
                  </button>
                )}
              </div>

              <div className="flex items-center gap-2">
                <Select value={status || ALL} onValueChange={(value) => registry.setFilter("status", value === ALL ? "" : value)}>
                  <SelectTrigger className="h-11! w-full bg-surface-2 sm:w-44" aria-label="الحالة">
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
                  <Button variant="ghost" onClick={registry.reset} className="h-11 shrink-0 text-muted-foreground" data-registry-reset>
                    <X className="size-4" />
                    مسح البحث
                  </Button>
                )}
              </div>
            </div>

            {/* Results context: what the list below shows. */}
            <div className="flex min-h-10 flex-wrap items-center gap-x-3 gap-y-1 border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-[13px] sm:px-5" aria-live="polite">
              {data ? (
                <span className="text-muted-foreground">
                  <bdi className="font-semibold text-foreground tabular-nums">{fmt(data.meta.total)}</bdi>{" "}
                  {filtered ? "نتيجة مطابقة" : "أسرة"}
                </span>
              ) : (
                <Skeleton className="h-4 w-20" />
              )}
              {registry.q && (
                <span className="text-subtle-foreground">
                  للبحث عن «<bdi className="text-foreground">{registry.q}</bdi>»
                </span>
              )}
              {status && <span className="text-subtle-foreground">· الحالة: {statusLabels[status as FamilyLifecycleStatus] ?? status}</span>}
              {isFetching && !isLoading && (
                <span className="ms-auto flex items-center gap-1.5 text-subtle-foreground">
                  <Loader2 className="size-3.5 animate-spin" aria-hidden />
                  جارٍ التحديث
                </span>
              )}
            </div>

            <div className={cn("border-t border-stroke-subtle", isFetching && !isLoading && "opacity-60 transition-opacity")} aria-busy={isFetching}>
              {isLoading ? (
                <SkeletonRows />
              ) : families.length === 0 ? (
                filtered ? (
                  <EmptyState
                    icon={SearchX}
                    title="لا توجد أسر مطابقة"
                    description="جرّب تعديل عبارة البحث أو الحالة. يمكن البحث برقم الأسرة أو اسم أحد أفرادها أو رقم الفرد."
                    action={
                      <Button variant="outline" onClick={registry.reset}>
                        <X className="size-4" />
                        مسح البحث
                      </Button>
                    }
                  />
                ) : (
                  <EmptyState icon={Users} title="لا توجد أسر مسجّلة بعد" description="ستظهر الأسر هنا فور تسجيلها." action={addFamily} />
                )
              ) : (
                <>
                  {/* Desktop (≥ lg): enterprise table */}
                  <Table className="hidden lg:table">
                    <TableHeader className="bg-surface-1">
                      <TableRow className="border-stroke-subtle hover:bg-transparent">
                        <TableHead className={`${head} ps-5`}>الأسرة</TableHead>
                        <TableHead className={head}>رب الأسرة</TableHead>
                        <TableHead className={`${head} text-center`}>أفراد الأسرة (المعلن)</TableHead>
                        <TableHead className={`${head} text-center`}>المسجلون تفصيليًا</TableHead>
                        <TableHead className={head}>الفرع</TableHead>
                        <TableHead className={head}>الحالة</TableHead>
                        <TableHead className={head}>آخر تحديث</TableHead>
                        <TableHead className={`${head} pe-5`}>
                          <span className="sr-only">فتح</span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {families.map((family) => (
                        <TableRow
                          key={family.family_code}
                          className="group h-[60px] cursor-pointer border-stroke-subtle transition-colors hover:bg-surface-hover"
                          data-family={family.family_code}
                          onClick={() => open(family)}
                        >
                          <TableCell className="ps-5">
                            <FamilyIdentity family={family} />
                          </TableCell>
                          <TableCell>
                            <HeadName family={family} />
                          </TableCell>
                          <TableCell className="text-center font-semibold tabular-nums">
                            {family.declared_household_size == null ? <span className="font-normal text-subtle-foreground">—</span> : fmt(family.declared_household_size)}
                          </TableCell>
                          <TableCell className="text-center tabular-nums text-muted-foreground">{fmt(family.member_count)}</TableCell>
                          <TableCell className="max-w-56">
                            <Branch family={family} />
                          </TableCell>
                          <TableCell>
                            <FamilyStatusBadge status={family.status} />
                          </TableCell>
                          <TableCell className="text-[13px] text-muted-foreground">{updatedLabel(family.updated_at)}</TableCell>
                          <TableCell className="pe-5 text-end">
                            <Button
                              asChild
                              variant="ghost"
                              size="sm"
                              className="h-8 gap-1 text-muted-foreground group-hover:bg-surface-1 group-hover:text-brand-700"
                            >
                              <Link
                                href={`/families/${family.family_code}`}
                                onClick={(e) => e.stopPropagation()}
                                aria-label={`عرض الأسرة ${family.family_code}`}
                              >
                                عرض
                                <ChevronLeft className="size-4" />
                              </Link>
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>

                  {/* Tablet and phone (< lg): dense registry rows */}
                  <ul className="divide-y divide-stroke-subtle lg:hidden" aria-label="نتائج سجل الأسر">
                    {families.map((family) => (
                      <li key={family.family_code}>
                        <Link
                          href={`/families/${family.family_code}`}
                          className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5"
                          aria-label={`عرض الأسرة ${family.family_code}${family.household_head_name ? ` — ${family.household_head_name}` : ""}`}
                        >
                          <IconBox icon={Home} tone="brand" size="sm" />
                          <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <div className="flex flex-wrap items-center gap-2">
                              <Code className="text-[13px] font-semibold text-brand-800">{family.family_code}</Code>
                              <FamilyStatusBadge status={family.status} />
                            </div>
                            <HeadName family={family} className="truncate" />
                            <span className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                              {family.declared_household_size != null && (
                                <>
                                  <span>
                                    أفراد الأسرة (المعلن) <bdi className="font-semibold text-foreground tabular-nums">{fmt(family.declared_household_size)}</bdi>
                                  </span>
                                  <span aria-hidden>·</span>
                                </>
                              )}
                              <span>
                                المسجلون تفصيليًا <bdi className="font-semibold text-foreground tabular-nums">{fmt(family.member_count)}</bdi>
                              </span>
                              <span aria-hidden>·</span>
                              <span className={family.branch_name ? undefined : "text-subtle-foreground"}>{family.branch_name ?? "بدون فرع"}</span>
                              <span aria-hidden className="max-sm:hidden">·</span>
                              <span className="max-sm:hidden">تحديث {updatedLabel(family.updated_at)}</span>
                            </span>
                          </div>
                          <ChevronLeft className="size-4 shrink-0 text-subtle-foreground" aria-hidden />
                        </Link>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </div>

            {data && families.length > 0 && (
              <div className="border-t border-stroke-subtle">
                <RegistryPagination meta={data.meta} onPage={registry.setPage} unit={filtered ? "نتيجة" : "أسرة"} />
              </div>
            )}
          </AppCard>
        </>
      )}
    </div>
  );
}
