"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertCircle, ChevronLeft, Loader2, Search, SearchX, UserRound, Users, X } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/components/auth/auth-context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { AppCard } from "@/components/shared/app-card";
import { IconBox } from "@/components/shared/icon-box";
import { Initials } from "@/components/shared/initials";
import { Code, PageHeader } from "@/components/shared/page-layout";
import { EmptyState } from "@/components/shared/empty-state";
import { StatusBadge } from "@/components/shared/status-badge";
import { usePeople } from "@/lib/api/people";
import { useRegistrySearch } from "@/lib/hooks/use-registry-search";
import type { PersonSummary } from "@/lib/types/api/person";
import type { PaginatedResponse } from "@/lib/types/api/family";
import { ageLabel } from "@/lib/utils/date";
import { relationshipLabel } from "@/lib/utils/relationship";
import { cn } from "@/lib/utils";

const fmt = (n: number) => n.toLocaleString("ar");
const genderLabel = (g: string | null) => (g === "MALE" ? "ذكر" : g === "FEMALE" ? "أنثى" : "غير محدد");

/** Arabic count noun for people: 1 شخص · 2 شخصان · 3–10 أشخاص · 11+ شخصًا. */
function peopleNoun(n: number): string {
  if (n === 1) return "شخص";
  if (n === 2) return "شخصان";
  if (n >= 3 && n <= 10) return "أشخاص";
  return "شخصًا";
}

const head = "h-10 text-xs font-medium text-muted-foreground";

// ------------------------------------------------------------------ building blocks

function Deceased({ person }: { person: PersonSummary }) {
  return person.life_status === "DECEASED" ? <StatusBadge tone="neutral">متوفى</StatusBadge> : null;
}

/** Person identity: initials, name (the link), bidi-safe Person code. */
function PersonIdentity({ person, linked = true }: { person: PersonSummary; linked?: boolean }) {
  const deceased = person.life_status === "DECEASED";
  const name = <span className={cn("font-semibold", deceased ? "text-muted-foreground" : "text-foreground")}>{person.full_name}</span>;
  return (
    <div className="flex min-w-0 items-center gap-3">
      <Initials
        name={person.full_name}
        className={cn("size-9", deceased ? "bg-surface-2 text-subtle-foreground" : "bg-brand-50 text-brand-800")}
      />
      <div className="flex min-w-0 flex-col">
        <span className="flex min-w-0 flex-wrap items-center gap-2">
          {linked ? (
            <Link
              href={`/people/${person.person_code}`}
              onClick={(e) => e.stopPropagation()}
              className="truncate rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-ring"
            >
              {name}
            </Link>
          ) : (
            <span className="truncate">{name}</span>
          )}
          <Deceased person={person} />
        </span>
        <Code className="text-xs font-normal text-muted-foreground">{person.person_code}</Code>
      </div>
    </div>
  );
}

function RelationshipLabel({ person }: { person: PersonSummary }) {
  const family = person.family;
  if (!family) return null;
  if (family.is_household_head) return <StatusBadge tone="brand">رب الأسرة</StatusBadge>;
  const label = family.relationship
    ? relationshipLabel({ id: 0, code: family.relationship.code, name: family.relationship.name }, person.gender)
    : "غير محدد";
  return <span className={family.relationship ? "text-foreground" : "text-muted-foreground"}>{label}</span>;
}

/**
 * Current family context. `family` is null when the user may not view
 * Families (the API omits it) or the person has no current membership —
 * never derived client-side.
 */
function FamilyContext({ person, canViewFamilies }: { person: PersonSummary; canViewFamilies: boolean }) {
  if (!person.family) {
    return (
      <span className="text-muted-foreground">{canViewFamilies ? "بلا أسرة حالية" : <span aria-label="غير متاح">—</span>}</span>
    );
  }
  return (
    <div className="flex min-w-0 flex-col">
      <Link
        href={`/families/${person.family.family_code}`}
        onClick={(e) => e.stopPropagation()}
        className="w-fit rounded-sm font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
        data-family-link
      >
        <Code>{person.family.family_code}</Code>
      </Link>
      <span className="truncate text-xs text-muted-foreground">
        {person.family.branch_name ?? "بدون فرع"}
      </span>
    </div>
  );
}

function AgeCell({ person }: { person: PersonSummary }) {
  return person.birth_date ? (
    <div className="flex flex-col">
      <span className="tabular-nums text-foreground">{ageLabel(person.birth_date)} سنة</span>
      <bdi dir="ltr" className="text-xs text-muted-foreground tabular-nums">
        {person.birth_date}
      </bdi>
    </div>
  ) : (
    <span className="text-muted-foreground">غير معروف</span>
  );
}

function SkeletonRows() {
  return (
    <div className="flex flex-col divide-y divide-stroke-subtle" aria-busy="true">
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-4 py-3.5 sm:px-5">
          <Skeleton className="size-9 rounded-full" />
          <div className="flex flex-col gap-1.5">
            <Skeleton className="h-4 w-44" />
            <Skeleton className="h-3 w-20" />
          </div>
          <Skeleton className="ms-auto hidden h-4 w-24 sm:block" />
        </div>
      ))}
    </div>
  );
}

/**
 * Whole-registry total: `meta.total` of the unfiltered query — the current
 * one, or (while searching) the unfiltered result already in the query
 * cache. No extra request; unknown ("—") when the page was opened with a
 * search and no unfiltered result has been loaded yet.
 */
function useRegistryTotal(current: PaginatedResponse<PersonSummary> | undefined, filtered: boolean): number | null | undefined {
  const queryClient = useQueryClient();
  if (!filtered) return current?.meta.total;
  const cached = queryClient
    .getQueriesData<PaginatedResponse<PersonSummary>>({ queryKey: ["people", "registry"] })
    .find(([key, value]) => value && !(key[2] as { search?: string } | undefined)?.search);
  return cached?.[1]?.meta.total ?? null;
}

function RegistrySummary({ total }: { total: number | null | undefined }) {
  return (
    <AppCard padded={false} className="flex items-center gap-3 px-4 py-3 sm:px-5" data-registry-summary>
      <IconBox icon={Users} size="sm" />
      <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <span className="text-[13px] font-medium text-muted-foreground">الأشخاص المسجلون</span>
        {total === undefined ? (
          <Skeleton className="h-6 w-10 self-center" />
        ) : total === null ? (
          <span className="text-xl font-bold text-muted-foreground" aria-label="غير متاح أثناء البحث">
            —
          </span>
        ) : (
          <>
            <bdi className="text-xl font-bold tabular-nums text-foreground">{fmt(total)}</bdi>
            <span className="text-[13px] text-muted-foreground">{peopleNoun(total)} في السجل</span>
          </>
        )}
      </div>
    </AppCard>
  );
}

// ------------------------------------------------------------------ registry

/**
 * People registry (docs/03 §93a): server-side search by Person code or
 * name, paginated, state in the URL. No National ID, contact details or
 * health data; Family context only for users who may view Families.
 */
export function PeopleRegistry() {
  const router = useRouter();
  const { can } = useAuth();
  const canViewFamilies = can("family.view");
  const registry = useRegistrySearch();
  const { data, isLoading, isFetching, isError, error, refetch } = usePeople({ search: registry.q, page: registry.page });
  const people = data?.data ?? [];
  const filtered = Boolean(registry.q);
  const hasText = registry.text.trim() !== "";
  const registryTotal = useRegistryTotal(data, filtered);

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title="الأشخاص" description="ابحث عن فرد برقم الفرد أو الاسم، وافتح ملفه" />

      {isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تحميل سجل الأشخاص</AlertTitle>
          <AlertDescription className="flex flex-col gap-2">
            <span>{error instanceof Error ? error.message : "حدث خطأ غير متوقع أثناء الاتصال بالخادم."}</span>
            <Button variant="outline" size="sm" className="w-fit" onClick={() => refetch()}>
              إعادة المحاولة
            </Button>
          </AlertDescription>
        </Alert>
      ) : (
        <>
          <RegistrySummary total={registryTotal} />

          <AppCard padded={false} className="overflow-hidden">
            {/* Toolbar: search is the only (and primary) tool. */}
            <div className="flex flex-col gap-2.5 p-3 sm:flex-row sm:items-center sm:p-4" role="search">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute start-3.5 top-1/2 size-[18px] -translate-y-1/2 text-subtle-foreground" aria-hidden />
                <Input
                  type="search"
                  value={registry.text}
                  onChange={(event) => registry.setText(event.target.value)}
                  placeholder="ابحث برقم الفرد أو الاسم"
                  aria-label="بحث في سجل الأشخاص"
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
              {hasText && (
                <Button variant="ghost" onClick={registry.reset} className="h-11 shrink-0 text-muted-foreground" data-registry-reset>
                  <X className="size-4" />
                  مسح البحث
                </Button>
              )}
            </div>

            {/* Results context: meta.total of the current query. */}
            <div className="flex min-h-10 flex-wrap items-center gap-x-3 gap-y-1 border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-[13px] sm:px-5" aria-live="polite">
              {data ? (
                <span className="text-muted-foreground">
                  <bdi className="font-semibold text-foreground tabular-nums">{fmt(data.meta.total)}</bdi>{" "}
                  {filtered ? "نتيجة مطابقة" : `${peopleNoun(data.meta.total)} مسجّلون`}
                </span>
              ) : (
                <Skeleton className="h-4 w-24" />
              )}
              {registry.q && (
                <span className="text-subtle-foreground">
                  للبحث عن «<bdi className="text-foreground">{registry.q}</bdi>»
                </span>
              )}
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
              ) : people.length === 0 ? (
                filtered ? (
                  <EmptyState
                    icon={SearchX}
                    title="لا يوجد أشخاص مطابقون"
                    description="جرّب رقم الفرد كاملًا أو جزءًا مختلفًا من الاسم."
                    action={
                      <Button variant="outline" onClick={registry.reset}>
                        <X className="size-4" />
                        مسح البحث
                      </Button>
                    }
                  />
                ) : (
                  <EmptyState icon={UserRound} title="لا يوجد أشخاص مسجّلون بعد" description="يُضاف الأفراد من خلال تسجيل الأسرة وأفرادها." />
                )
              ) : (
                <>
                  {/* Desktop (≥ lg): semantic table */}
                  <Table className="hidden lg:table">
                    <TableHeader className="bg-surface-1">
                      <TableRow className="border-stroke-subtle hover:bg-transparent">
                        <TableHead className={`${head} ps-5`}>الفرد</TableHead>
                        <TableHead className={head}>الأسرة الحالية</TableHead>
                        <TableHead className={head}>الصلة بالأسرة</TableHead>
                        <TableHead className={head}>الجنس</TableHead>
                        <TableHead className={head}>العمر</TableHead>
                        <TableHead className={`${head} pe-5`}>
                          <span className="sr-only">فتح</span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {people.map((person) => (
                        <TableRow
                          key={person.person_code}
                          data-person={person.person_code}
                          className="group h-[60px] cursor-pointer border-stroke-subtle transition-colors hover:bg-surface-hover"
                          onClick={() => router.push(`/people/${person.person_code}`)}
                        >
                          <TableCell className="ps-5">
                            <PersonIdentity person={person} />
                          </TableCell>
                          <TableCell className="max-w-56">
                            <FamilyContext person={person} canViewFamilies={canViewFamilies} />
                          </TableCell>
                          <TableCell>
                            <RelationshipLabel person={person} />
                          </TableCell>
                          <TableCell className="text-foreground">{genderLabel(person.gender)}</TableCell>
                          <TableCell>
                            <AgeCell person={person} />
                          </TableCell>
                          <TableCell className="pe-5 text-end">
                            <Button asChild variant="ghost" size="sm" className="h-8 gap-1 font-medium text-foreground group-hover:bg-surface-1 group-hover:text-brand-700">
                              <Link href={`/people/${person.person_code}`} onClick={(e) => e.stopPropagation()} aria-label={`عرض الفرد ${person.person_code}`}>
                                عرض
                                <ChevronLeft className="size-4" />
                              </Link>
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>

                  {/* Tablet and phone (< lg): dense person rows */}
                  <ul className="divide-y divide-stroke-subtle lg:hidden" aria-label="نتائج سجل الأشخاص">
                    {people.map((person) => (
                      <li key={person.person_code}>
                        <Link
                          href={`/people/${person.person_code}`}
                          className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring sm:px-5"
                          aria-label={`عرض الفرد ${person.person_code} — ${person.full_name}`}
                        >
                          <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                            <PersonIdentity person={person} linked={false} />
                            <span className="flex flex-wrap items-center gap-x-2 gap-y-1 ps-12 text-xs text-muted-foreground">
                              {person.family ? (
                                <>
                                  <RelationshipLabel person={person} />
                                  <span aria-hidden>·</span>
                                  <Code className="text-brand-800">{person.family.family_code}</Code>
                                </>
                              ) : (
                                <span>{canViewFamilies ? "بلا أسرة حالية" : "الأسرة غير متاحة"}</span>
                              )}
                              <span aria-hidden>·</span>
                              <span>{genderLabel(person.gender)}</span>
                              <span aria-hidden>·</span>
                              <span className="tabular-nums">
                                {person.birth_date ? `${ageLabel(person.birth_date)} سنة` : "العمر غير معروف"}
                              </span>
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

            {data && people.length > 0 && (
              <div className="border-t border-stroke-subtle">
                <RegistryPagination meta={data.meta} onPage={registry.setPage} unit={filtered ? "نتيجة" : peopleNoun(data.meta.total)} />
              </div>
            )}
          </AppCard>
        </>
      )}
    </div>
  );
}
