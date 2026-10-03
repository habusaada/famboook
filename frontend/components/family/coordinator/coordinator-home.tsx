"use client";

import { useState } from "react";
import Link from "next/link";
import { ChevronLeft, Loader2, Search, UsersRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { fieldClass } from "@/components/family/auth/auth-parts";
import { useCoordinatorContext } from "@/components/family/coordinator/coordinator-gate";
import { type CoordinatorFamily, hierarchyLabel, scopeLabel, useCoordinatorFamiliesQuery } from "@/lib/api/coordinator";

function FamilyRow({ family }: { family: CoordinatorFamily }) {
  return (
    <li>
      <Link
        href={`/family/coordinator/families/${encodeURIComponent(family.family_code)}`}
        className="flex items-center gap-3 px-4 py-3.5 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
        data-coordinator-family
      >
        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-3">
            <p className="min-w-0 text-[15px] leading-snug font-semibold break-words text-foreground">
              <bdi>{family.head_name ?? "—"}</bdi>
            </p>
            <span dir="ltr" className="mt-0.5 shrink-0 font-mono text-[13px] font-medium text-brand-800">
              {family.family_code}
            </span>
          </div>
          <p className="mt-1 text-[13px] leading-relaxed break-words text-muted-foreground">
            <bdi>{hierarchyLabel(family)}</bdi>
          </p>
          <p className="mt-0.5 text-[13px] text-subtle-foreground">عدد الأفراد: {family.active_member_count}</p>
        </div>
        <ChevronLeft className="size-4 shrink-0 text-subtle-foreground" aria-hidden />
      </Link>
    </li>
  );
}

/**
 * The first Coordinator Space screen (docs/11 §8): the effective scopes, how
 * many Families they cover, and a searchable, server-paginated list of
 * Family SUMMARIES. Nothing here is the Coordinator's own household.
 */
export function CoordinatorHome() {
  const context = useCoordinatorContext();
  const [draft, setDraft] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const families = useCoordinatorFamiliesQuery(search, page);
  const meta = families.data?.meta;

  function submit(event: React.FormEvent) {
    event.preventDefault();
    setSearch(draft.trim());
    setPage(1);
  }

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl leading-snug font-bold text-foreground">مساحة التنسيق</h1>

      <section aria-labelledby="coordinator-scope-title" className="rounded-2xl border border-border bg-surface-1 p-4">
        <h2 id="coordinator-scope-title" className="text-sm font-semibold text-foreground">
          نطاق التنسيق
        </h2>
        <ul className="mt-3 flex flex-wrap gap-2" data-coordinator-scopes>
          {context.scopes.map((scope) => (
            <li
              key={`${scope.type}-${scope.code}`}
              className="rounded-lg border border-brand-100 bg-brand-50 px-2.5 py-1.5 text-[13px] font-medium text-brand-900"
            >
              <bdi>{scopeLabel(scope)}</bdi>
            </li>
          ))}
        </ul>
        <p className="mt-4 flex items-center gap-2 text-sm text-muted-foreground" data-coordinator-count>
          <UsersRound className="size-4 text-brand-700" aria-hidden />
          عدد الأسر في النطاق: <span className="font-semibold text-foreground">{context.family_count}</span>
        </p>
      </section>

      <section aria-labelledby="coordinator-families-title" className="flex flex-col gap-3">
        <h2 id="coordinator-families-title" className="text-sm font-semibold text-foreground">
          الأسر في النطاق
        </h2>

        <form onSubmit={submit} role="search" className="flex gap-2">
          <div className="min-w-0 flex-1">
            <Label htmlFor="coordinator-search" className="sr-only">
              بحث برمز الأسرة أو اسم رب الأسرة
            </Label>
            <Input
              id="coordinator-search"
              type="search"
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              placeholder="رمز الأسرة أو اسم رب الأسرة"
              maxLength={100}
              className={fieldClass}
            />
          </div>
          <Button type="submit" className="h-12 rounded-xl px-4 hover:bg-[var(--family-primary-hover)]" aria-label="بحث">
            <Search className="size-4" aria-hidden />
          </Button>
        </form>

        {families.isError ? (
          <p className="rounded-xl border border-border bg-surface-1 px-4 py-6 text-center text-sm text-muted-foreground" role="alert">
            تعذّر تحميل الأسر.
          </p>
        ) : !families.data ? (
          <div className="flex justify-center py-8">
            <Loader2 className="size-5 animate-spin text-brand-700" aria-label="جارٍ تحميل الأسر" />
          </div>
        ) : families.data.data.length === 0 ? (
          <p className="rounded-xl border border-border bg-surface-1 px-4 py-6 text-center text-sm text-muted-foreground" data-coordinator-empty>
            لا توجد أسر مطابقة.
          </p>
        ) : (
          <ul className="divide-y divide-stroke-subtle overflow-hidden rounded-2xl border border-border bg-surface-1" aria-busy={families.isFetching}>
            {families.data.data.map((family) => (
              <FamilyRow key={family.family_code} family={family} />
            ))}
          </ul>
        )}

        {meta && meta.last_page > 1 && (
          <nav aria-label="صفحات الأسر" className="flex items-center justify-between gap-3">
            <Button variant="outline" className="h-11 rounded-xl px-4" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              السابق
            </Button>
            <span className="text-sm text-muted-foreground">
              صفحة {meta.current_page} من {meta.last_page}
            </span>
            <Button variant="outline" className="h-11 rounded-xl px-4" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
              التالي
            </Button>
          </nav>
        )}
      </section>
    </div>
  );
}
