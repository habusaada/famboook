"use client";

import { Button } from "@/components/ui/button";
import type { PaginationMeta } from "@/lib/types/api/family";

const fmt = (n: number) => n.toLocaleString("ar");

/** Server-side pagination bar for the registries: total results + prev/next. */
export function RegistryPagination({
  meta,
  onPage,
  unit,
}: {
  meta: PaginationMeta;
  onPage: (page: number) => void;
  unit: string;
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm text-muted-foreground" data-pagination>
      <span data-total={meta.total}>
        {fmt(meta.total)} {unit}
      </span>
      <div className="flex items-center gap-2">
        <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          السابق
        </Button>
        <span data-page={meta.current_page}>
          صفحة {fmt(meta.current_page)} من {fmt(Math.max(meta.last_page, 1))}
        </span>
        <Button
          variant="outline"
          size="sm"
          disabled={meta.current_page >= meta.last_page}
          onClick={() => onPage(meta.current_page + 1)}
        >
          التالي
        </Button>
      </div>
    </div>
  );
}
