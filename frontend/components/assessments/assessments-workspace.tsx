"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertCircle, ChevronLeft, ClipboardList, Loader2, Lock, SearchX, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { Code, PageHeader } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { AssessmentStatusTag, assessedDomainsLabel, assessmentsNoun } from "@/components/assessments/assessment-case";
import { useAssessmentRegistry } from "@/lib/api/assessments";
import { ApiError } from "@/lib/api/client";
import type { AssessmentRegistryFilters, AssessmentRegistryRow, AssessmentStatus } from "@/lib/types/api/assessment";
import { assessmentStatusLabels } from "@/lib/utils/assessment";
import { cn } from "@/lib/utils";

const ALL = "ALL";
const fmt = (n: number) => n.toLocaleString("ar");
const isFiltered = (f: AssessmentRegistryFilters) => Boolean(f.status || f.family);
const head = "h-10 text-xs font-medium text-muted-foreground";
const day = (iso: string) => iso.slice(0, 10);

/** DRAFT + assessment.update → continue in the existing editor; otherwise the existing detail. */
function target(row: AssessmentRegistryRow, canUpdate: boolean) {
  const base = `/families/${encodeURIComponent(row.family.family_code)}/assessments/${row.id}`;
  return row.status === "DRAFT" && canUpdate
    ? { href: `${base}/edit`, label: "استكمال", aria: `استكمال مسودة تقييم الأسرة ${row.family.family_code}` }
    : { href: base, label: "عرض", aria: `عرض تقييم الأسرة ${row.family.family_code}` };
}

function Summary({ summary }: { summary?: { total: number; draft: number; completed: number } }) {
  return (
    <AppCard padded={false} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-6 sm:px-5" data-registry-summary>
      <div className="flex items-center gap-3">
        <IconBox icon={ClipboardList} size="sm" />
        <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <span className="text-[13px] font-medium text-muted-foreground">التقييمات المسجلة</span>
          {summary ? (
            <>
              <bdi className="text-xl font-bold tabular-nums text-foreground">{fmt(summary.total)}</bdi>
              <span className="text-[13px] text-muted-foreground">{assessmentsNoun(summary.total)} في السجل</span>
            </>
          ) : (
            <Skeleton className="h-6 w-10 self-center" />
          )}
        </div>
      </div>
      <div className="hidden h-6 w-px bg-stroke-subtle sm:block" aria-hidden />
      {/* Whole-registry counts from the server (independent of filters). */}
      <dl className="flex flex-wrap items-center gap-x-5 gap-y-1 text-[13px]">
        {(["DRAFT", "COMPLETED"] as const).map((s) => (
          <div key={s} className="flex items-center gap-1.5" data-stat={s}>
            <dt className="flex items-center gap-1.5 text-muted-foreground">
              <span aria-hidden className={cn("size-1.5 rounded-full", s === "DRAFT" ? "bg-info" : "bg-success")} />
              {s === "DRAFT" ? "مسودات" : "مكتملة"}
            </dt>
            <dd className="font-semibold tabular-nums text-foreground">
              {summary ? fmt(s === "DRAFT" ? summary.draft : summary.completed) : "…"}
            </dd>
          </div>
        ))}
      </dl>
    </AppCard>
  );
}

function SkeletonRows() {
  return (
    <div className="flex flex-col divide-y divide-stroke-subtle" aria-busy="true">
      {Array.from({ length: 5 }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-4 py-3.5 sm:px-5">
          <div className="flex flex-col gap-1.5">
            <Skeleton className="h-4 w-40" />
            <Skeleton className="h-3 w-24" />
          </div>
          <Skeleton className="ms-auto hidden h-5 w-16 sm:block" />
          <Skeleton className="hidden h-5 w-20 sm:block" />
        </div>
      ))}
    </div>
  );
}

/**
 * Assessments Pilot Workspace (/assessments): the cross-family registry.
 * Read-only here — drafts are continued and completed assessments reviewed
 * in the existing family-scoped pages; creation stays in the Family Profile.
 */
export function AssessmentsWorkspace() {
  const router = useRouter();
  const [filters, setFilters] = useState<AssessmentRegistryFilters>({});
  const [familyText, setFamilyText] = useState("");
  const [page, setPage] = useState(1);
  const { data, isLoading, isFetching, isError, error } = useAssessmentRegistry(filters, page);

  const header = (
    <PageHeader title="التقييمات" description="متابعة تقييمات الأسر وحالتها وفتح سجل التقييم لاستكماله أو مراجعته." />
  );

  if (isError && error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col gap-4">
        {header}
        <AppCard padded={false}>
          <EmptyState icon={Lock} title="لا تملك صلاحية عرض التقييمات." />
        </AppCard>
      </div>
    );
  }

  const rows = data?.data ?? [];
  const total = data?.meta.total;
  const canUpdate = data?.abilities.update ?? false;
  const filtered = isFiltered(filters);
  const refreshing = isFetching && !isLoading;
  const apply = (next: AssessmentRegistryFilters) => {
    setFilters(next);
    setPage(1);
  };
  const applyFamily = () => {
    const code = familyText.trim().toUpperCase();
    if (code !== (filters.family ?? "")) apply({ ...filters, family: code || undefined });
  };
  const reset = () => {
    setFamilyText("");
    apply({});
  };
  const active = [
    filters.status && `الحالة: ${assessmentStatusLabels[filters.status]}`,
    filters.family && `الأسرة: ${filters.family}`,
  ].filter(Boolean) as string[];

  return (
    <div className="flex flex-col gap-4">
      {header}
      <Summary summary={data?.summary} />

      <AppCard padded={false} className="overflow-hidden">
        {/* Toolbar: server-side status and exact Family code filters. */}
        <div className="flex flex-col gap-2.5 p-3 sm:flex-row sm:flex-wrap sm:items-center sm:p-4" role="group" aria-label="تصفية التقييمات">
          <div className="flex flex-wrap items-center gap-2">
            <Select
              value={filters.status ?? ALL}
              onValueChange={(v) => apply({ ...filters, status: v === ALL ? undefined : (v as AssessmentStatus) })}
            >
              <SelectTrigger size="sm" aria-label="الحالة" className="h-10! min-w-36 bg-surface-2 max-sm:w-full max-sm:flex-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL}>الحالة: الكل</SelectItem>
                <SelectItem value="DRAFT">{assessmentStatusLabels.DRAFT}</SelectItem>
                <SelectItem value="COMPLETED">{assessmentStatusLabels.COMPLETED}</SelectItem>
              </SelectContent>
            </Select>
            <form
              className="max-sm:w-full"
              onSubmit={(e) => {
                e.preventDefault();
                applyFamily();
              }}
            >
              <Input
                value={familyText}
                onChange={(e) => setFamilyText(e.target.value)}
                onBlur={applyFamily}
                placeholder="رقم الأسرة كاملًا"
                aria-label="رقم الأسرة (مطابقة تامة)"
                dir="ltr"
                className="h-10 w-full bg-surface-2 text-end sm:w-48"
                data-family-filter
              />
            </form>
          </div>
          {filtered && (
            <Button variant="ghost" onClick={reset} className="h-10 text-muted-foreground sm:ms-auto" data-assessments-reset>
              <X className="size-4" />
              إعادة الضبط
            </Button>
          )}
        </div>

        {/* Results context: meta.total of the current filters. */}
        <div
          className="flex min-h-10 flex-wrap items-center gap-x-3 gap-y-1 border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-[13px] sm:px-5"
          aria-live="polite"
          aria-label="نتائج التقييمات"
        >
          {total !== undefined ? (
            <span className="text-muted-foreground">
              <bdi className="font-semibold text-foreground tabular-nums">{fmt(total)}</bdi>{" "}
              {filtered ? (total === 1 ? "نتيجة مطابقة" : "نتائج مطابقة") : assessmentsNoun(total)}
            </span>
          ) : (
            <Skeleton className="h-4 w-24" />
          )}
          {active.length > 0 && (
            <span className="text-muted-foreground" data-active-filters>
              {active.map((a, i) => (
                <span key={a}>
                  {i > 0 && " · "}
                  <bdi>{a}</bdi>
                </span>
              ))}
            </span>
          )}
          {refreshing && (
            <span className="ms-auto flex items-center gap-1.5 text-muted-foreground">
              <Loader2 className="size-3.5 animate-spin" aria-hidden />
              جارٍ التحديث
            </span>
          )}
        </div>

        <div className={cn("border-t border-stroke-subtle", refreshing && "opacity-60 transition-opacity")}>
          {isLoading ? (
            <SkeletonRows />
          ) : isError ? (
            <div className="p-4">
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertTitle>تعذّر تحميل التقييمات</AlertTitle>
                <AlertDescription>
                  {error instanceof ApiError && error.status === 422 ? "قيمة التصفية غير صالحة." : "حدث خطأ أثناء الاتصال بالخادم."}
                </AlertDescription>
              </Alert>
            </div>
          ) : rows.length === 0 ? (
            filtered ? (
              <EmptyState
                icon={SearchX}
                title="لا توجد تقييمات مطابقة"
                description="تحقق من رقم الأسرة كاملًا، أو غيّر الحالة."
                action={
                  <Button variant="outline" onClick={reset}>
                    <X className="size-4" />
                    إعادة الضبط
                  </Button>
                }
              />
            ) : (
              <EmptyState
                icon={ClipboardList}
                title="لا توجد تقييمات بعد"
                description="تُنشأ التقييمات من ملف الأسرة، في تبويب «التقييمات»."
              />
            )
          ) : (
            <>
              {/* Desktop (≥ lg): semantic table */}
              <Table className="hidden lg:table">
                <TableHeader className="bg-surface-1">
                  <TableRow className="border-stroke-subtle hover:bg-transparent">
                    <TableHead className={`${head} ps-5`}>التقييم</TableHead>
                    <TableHead className={head}>الأسرة</TableHead>
                    <TableHead className={head}>الفرع</TableHead>
                    <TableHead className={head}>الحالة</TableHead>
                    <TableHead className={head}>المجالات</TableHead>
                    <TableHead className={head}>آخر تحديث</TableHead>
                    <TableHead className={`${head} pe-5`}>
                      <span className="sr-only">فتح</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => {
                    const open = target(row, canUpdate);
                    const draft = row.status === "DRAFT";
                    return (
                      <TableRow
                        key={row.id}
                        data-assessment-id={row.id}
                        data-assessment-status={row.status}
                        className="group h-[60px] cursor-pointer border-stroke-subtle transition-colors hover:bg-surface-hover"
                        onClick={() => router.push(open.href)}
                      >
                        <TableCell className="ps-5">
                          <div className="flex flex-col">
                            <Link
                              href={open.href}
                              onClick={(e) => e.stopPropagation()}
                              className={cn(
                                "w-fit rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring",
                                draft ? "font-semibold text-foreground" : "font-medium text-foreground"
                              )}
                              aria-label={open.aria}
                            >
                              تقييم بتاريخ <bdi dir="ltr" className="tabular-nums">{row.assessment_date}</bdi>
                            </Link>
                            <span className="text-xs text-muted-foreground">
                              {row.completed_at ? (
                                <>
                                  اكتمل <bdi dir="ltr" className="tabular-nums">{day(row.completed_at)}</bdi>
                                </>
                              ) : (
                                <>أدخله {row.created_by?.name ?? "—"}</>
                              )}
                            </span>
                          </div>
                        </TableCell>
                        <TableCell className="max-w-56">
                          <div className="flex min-w-0 flex-col">
                            <Link
                              href={`/families/${encodeURIComponent(row.family.family_code)}?tab=assessments`}
                              onClick={(e) => e.stopPropagation()}
                              className="w-fit rounded-sm font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                              data-family-link
                            >
                              <Code>{row.family.family_code}</Code>
                            </Link>
                            <span className="truncate text-xs text-muted-foreground">
                              {row.family.household_head_name ?? "رب الأسرة غير محدد"}
                            </span>
                          </div>
                        </TableCell>
                        <TableCell className={row.family.branch_name ? "text-foreground" : "text-muted-foreground"}>
                          {row.family.branch_name ?? "بدون فرع"}
                        </TableCell>
                        <TableCell>
                          <AssessmentStatusTag status={row.status} />
                        </TableCell>
                        <TableCell className="text-muted-foreground">{assessedDomainsLabel(row.assessed_domain_count)}</TableCell>
                        <TableCell className="text-[13px] text-muted-foreground">
                          <bdi dir="ltr" className="tabular-nums">{day(row.updated_at)}</bdi>
                        </TableCell>
                        <TableCell className="pe-5 text-end">
                          <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className={cn(
                              "h-8 gap-1 font-medium group-hover:bg-surface-1 group-hover:text-brand-700",
                              open.label === "استكمال" ? "text-brand-700" : "text-foreground"
                            )}
                          >
                            <Link href={open.href} onClick={(e) => e.stopPropagation()} aria-label={open.aria} data-row-action={open.label}>
                              {open.label}
                              <ChevronLeft className="size-4" />
                            </Link>
                          </Button>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>

              {/* Tablet and phone (< lg): compact work rows */}
              <ul className="divide-y divide-stroke-subtle lg:hidden" aria-label="قائمة التقييمات">
                {rows.map((row) => {
                  const open = target(row, canUpdate);
                  return (
                    <li key={row.id} data-assessment-id={row.id}>
                      <Link
                        href={open.href}
                        className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5"
                        aria-label={open.aria}
                      >
                        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                          <div className="flex items-center justify-between gap-2">
                            <span className="flex min-w-0 flex-wrap items-center gap-x-2">
                              <Code className="font-semibold text-brand-800">{row.family.family_code}</Code>
                              <span className="text-sm text-foreground">
                                تقييم <bdi dir="ltr" className="tabular-nums">{row.assessment_date}</bdi>
                              </span>
                            </span>
                            <AssessmentStatusTag status={row.status} />
                          </div>
                          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            <span>{row.family.household_head_name ?? "رب الأسرة غير محدد"}</span>
                            <span aria-hidden>·</span>
                            <span>{row.family.branch_name ?? "بدون فرع"}</span>
                          </span>
                          <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                            <span>{assessedDomainsLabel(row.assessed_domain_count)}</span>
                            <span aria-hidden>·</span>
                            <span>
                              آخر تحديث <bdi dir="ltr" className="tabular-nums">{day(row.updated_at)}</bdi>
                            </span>
                            <span aria-hidden>·</span>
                            <span className="font-medium text-brand-700">{open.label}</span>
                          </span>
                        </div>
                        <ChevronLeft className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </>
          )}
        </div>

        {data && rows.length > 0 && (
          <div className="border-t border-stroke-subtle">
            <RegistryPagination meta={data.meta} onPage={setPage} unit={filtered ? "نتيجة" : assessmentsNoun(data.meta.total)} />
          </div>
        )}
      </AppCard>
    </div>
  );
}
