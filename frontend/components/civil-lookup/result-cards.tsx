"use client";

import { ChevronLeft, Search, SearchX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/shared/empty-state";
import { Panel } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import type { CivilLookupList, CivilLookupResultEntry } from "@/lib/types/api/civil-lookup";
import type { PaginationMeta } from "@/lib/types/api/family";
import { formatCivilDate } from "@/lib/utils/civil-date";
import { MISSING } from "./labels";

// FB-CIVIL-02E — one result card for every list (full name, detailed search,
// household / children). A card shows only the result-list fields the API
// returns (masked National ID, name, gender code, birth date, place codes);
// opening a listed record is not counted.

function listMeta(data: CivilLookupList): PaginationMeta {
  const total = data.meta.total ?? 0;
  const from = total === 0 ? null : (data.meta.page - 1) * data.meta.per_page + 1;
  return {
    current_page: data.meta.page,
    last_page: data.meta.last_page,
    per_page: data.meta.per_page,
    total,
    from,
    to: from === null ? null : from + data.results.length - 1,
    path: "",
  };
}

function Field({ label, children, ltr }: { label: string; children: React.ReactNode; ltr?: boolean }) {
  return (
    <div className="flex min-w-0 flex-col">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-sm font-medium break-words">{ltr ? <bdi dir="ltr" className="tabular-nums">{children}</bdi> : children}</dd>
    </div>
  );
}

function ResultCard({ entry, canView, busy, opening, onOpen }: {
  entry: CivilLookupResultEntry;
  canView: boolean;
  busy: boolean;
  opening: boolean;
  onOpen: (entry: CivilLookupResultEntry) => void;
}) {
  const place = [entry.govern, entry.city].filter(Boolean).join(" / ");
  return (
    <li className="flex flex-col gap-3 rounded-xl border border-border bg-surface-1 p-4" data-result>
      <h3 className="text-base leading-snug font-semibold break-words text-foreground" data-result-name>
        {entry.fullname ?? MISSING}
      </h3>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
        <Field label="رقم الهوية" ltr={entry.national_id !== null}>
          {entry.national_id ?? MISSING}
        </Field>
        <Field label="تاريخ الميلاد">{formatCivilDate(entry.datebirth) ?? MISSING}</Field>
        <Field label="الجنس (رمز المصدر)">{entry.gender ?? MISSING}</Field>
        <Field label="المحافظة / المدينة (رمز المصدر)">{place || MISSING}</Field>
      </dl>
      {canView && (
        <Button variant="outline" className="h-11 w-full" disabled={busy} onClick={() => onOpen(entry)} aria-busy={opening || undefined}>
          {opening ? "جارٍ فتح السجل..." : "عرض السجل"}
          {!opening && <ChevronLeft className="size-4" aria-hidden />}
        </Button>
      )}
    </li>
  );
}

export function ResultCards({
  list,
  canView,
  busy,
  openingRef,
  onOpen,
  onPage,
}: {
  list: CivilLookupList;
  canView: boolean;
  busy: boolean;
  openingRef: string | null;
  onOpen: (entry: CivilLookupResultEntry) => void;
  onPage: (page: number) => void;
}) {
  if (list.outcome === "TOO_BROAD") {
    return (
      <Panel data-outcome="TOO_BROAD">
        <EmptyState icon={Search} title="نتائج كثيرة جدًا" description="يطابق البحث أكثر من 500 سجل. أضف كلمات أخرى من الاسم لتضييق البحث." />
      </Panel>
    );
  }
  if (list.results.length === 0) {
    return (
      <Panel data-outcome="NOT_FOUND">
        <EmptyState icon={SearchX} title="لا توجد نتائج مطابقة في الإصدار المنشور." description="تحقق من الكتابة أو جرّب طريقة بحث أخرى." />
      </Panel>
    );
  }

  return (
    <section className="flex flex-col gap-3" data-outcome={list.outcome} aria-label="نتائج البحث">
      {list.meta.total !== null && (
        <p className="text-[13px] text-muted-foreground" data-result-count>
          {list.meta.total.toLocaleString("ar")} نتيجة
        </p>
      )}
      <ul className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        {list.results.map((entry) => (
          <ResultCard key={entry.ref} entry={entry} canView={canView} busy={busy} opening={openingRef === entry.ref} onOpen={onOpen} />
        ))}
      </ul>
      {list.meta.total !== null && list.meta.total > list.meta.per_page && list.search_ref && (
        <RegistryPagination meta={listMeta(list)} onPage={onPage} unit="سجل" />
      )}
    </section>
  );
}
