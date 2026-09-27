"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import type { PaginationMeta } from "@/lib/types/api/family";

const fmt = (n: number) => n.toLocaleString("ar");

/**
 * Server-side pagination bar for the registries (docs/10): the visible range
 * and total, and previous/next. RTL: "previous" points right, "next" left.
 */
export function RegistryPagination({
  meta,
  onPage,
  unit,
}: {
  meta: PaginationMeta;
  onPage: (page: number) => void;
  unit: string;
}) {
  const lastPage = Math.max(meta.last_page, 1);

  return (
    <nav
      aria-label="التنقل بين الصفحات"
      className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-[13px] text-muted-foreground sm:px-5"
      data-pagination
    >
      <span data-total={meta.total}>
        {meta.from !== null && meta.to !== null ? (
          <>
            <bdi className="tabular-nums">
              {fmt(meta.from)}–{fmt(meta.to)}
            </bdi>{" "}
            من <bdi className="font-semibold text-foreground tabular-nums">{fmt(meta.total)}</bdi> {unit}
          </>
        ) : (
          <>
            <bdi className="font-semibold text-foreground tabular-nums">{fmt(meta.total)}</bdi> {unit}
          </>
        )}
      </span>
      <div className="flex items-center gap-1.5">
        <Button
          variant="outline"
          size="sm"
          className="h-8 gap-1 px-2.5"
          disabled={meta.current_page <= 1}
          onClick={() => onPage(meta.current_page - 1)}
          aria-label="الصفحة السابقة"
        >
          <ChevronRight className="size-4" />
          السابق
        </Button>
        <span data-page={meta.current_page} aria-current="page" className="min-w-24 px-2 text-center">
          صفحة <bdi className="font-semibold text-foreground tabular-nums">{fmt(meta.current_page)}</bdi> من{" "}
          <bdi className="tabular-nums">{fmt(lastPage)}</bdi>
        </span>
        <Button
          variant="outline"
          size="sm"
          className="h-8 gap-1 px-2.5"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
          aria-label="الصفحة التالية"
        >
          التالي
          <ChevronLeft className="size-4" />
        </Button>
      </div>
    </nav>
  );
}
