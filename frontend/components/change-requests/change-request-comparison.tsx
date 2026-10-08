import { Info } from "lucide-react";
import { StatusBadge } from "@/components/shared/status-badge";

// The current-vs-proposed comparison of a Change Request (PWA-5d).
//
// React never interprets a type's data: the handler's STAFF presentation
// must already be display-ready. The only shape rendered is a list of rows
// the handler labelled itself —
//
//   { rows: [{ label: string, current: string | null, proposed: string | null }] }
//
// — any other shape (including the PWA-5c test handler's {current, proposed}
// maps) is NOT rendered: raw keys and values could be sensitive registry
// fields. This row shape is the proposed presentation contract for the first
// real handler (PWA-6.1); it is not yet produced in Production.

export type ComparisonRow = { label: string; current: string | null; proposed: string | null };

const isText = (value: unknown): value is string | null => value === null || typeof value === "string";

/** The display-ready rows of a presentation, or null if it is not in the supported shape. */
export function comparisonRows(presentation: unknown): ComparisonRow[] | null {
  if (!presentation || typeof presentation !== "object" || !("rows" in presentation)) return null;
  const rows = (presentation as { rows: unknown }).rows;
  if (!Array.isArray(rows) || rows.length === 0) return null;
  const valid = rows.every(
    (row) =>
      row !== null &&
      typeof row === "object" &&
      typeof (row as ComparisonRow).label === "string" &&
      (row as ComparisonRow).label.trim() !== "" &&
      isText((row as ComparisonRow).current) &&
      isText((row as ComparisonRow).proposed)
  );
  return valid ? (rows as ComparisonRow[]).map(({ label, current, proposed }) => ({ label, current, proposed })) : null;
}

function Value({ value }: { value: string | null }) {
  return value === null || value.trim() === "" ? (
    <span className="text-subtle-foreground">
      —<span className="sr-only">غير مسجّل</span>
    </span>
  ) : (
    <bdi className="break-words whitespace-pre-line">{value}</bdi>
  );
}

function ChangeMarker({ changed }: { changed: boolean }) {
  // Text, not color alone.
  return changed ? (
    <StatusBadge tone="warning">تعديل مطلوب</StatusBadge>
  ) : (
    <StatusBadge tone="neutral">بدون تغيير</StatusBadge>
  );
}

export function ChangeRequestComparison({ rows }: { rows: ComparisonRow[] }) {
  return (
    <div data-comparison>
      {/* Desktop: البيانات الحالية | التعديل المطلوب */}
      <table className="hidden w-full text-sm md:table">
        <caption className="sr-only">مقارنة البيانات الحالية بالتعديل المطلوب</caption>
        <thead>
          <tr className="border-b border-stroke-subtle text-xs text-muted-foreground">
            <th scope="col" className="w-1/4 py-2 text-start font-medium">
              الحقل
            </th>
            <th scope="col" className="py-2 text-start font-medium">
              البيانات الحالية
            </th>
            <th scope="col" className="py-2 text-start font-medium">
              التعديل المطلوب
            </th>
            <th scope="col" className="w-28 py-2 text-start font-medium">
              <span className="sr-only">حالة الحقل</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => {
            const changed = (row.current ?? "") !== (row.proposed ?? "");
            return (
              <tr key={i} className="border-b border-stroke-subtle align-top last:border-0" data-comparison-row data-changed={changed}>
                <th scope="row" className="py-2.5 pe-3 text-start font-medium text-muted-foreground">
                  {row.label}
                </th>
                <td className="py-2.5 pe-3 text-foreground/80">
                  <Value value={row.current} />
                </td>
                <td className={changed ? "py-2.5 pe-3 font-semibold text-foreground" : "py-2.5 pe-3 text-foreground/80"}>
                  <Value value={row.proposed} />
                </td>
                <td className="py-2.5">
                  <ChangeMarker changed={changed} />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {/* Phone / tablet: stacked */}
      <ul className="flex flex-col divide-y divide-stroke-subtle md:hidden" aria-label="مقارنة البيانات الحالية بالتعديل المطلوب">
        {rows.map((row, i) => {
          const changed = (row.current ?? "") !== (row.proposed ?? "");
          return (
            <li key={i} className="flex flex-col gap-1.5 py-3" data-changed={changed}>
              <div className="flex items-center justify-between gap-2">
                <span className="text-[13px] font-medium text-muted-foreground">{row.label}</span>
                <ChangeMarker changed={changed} />
              </div>
              <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <dt className="text-xs text-subtle-foreground">الحالية</dt>
                <dd className="min-w-0 text-foreground/80">
                  <Value value={row.current} />
                </dd>
                <dt className="text-xs text-subtle-foreground">المطلوبة</dt>
                <dd className={changed ? "min-w-0 font-semibold text-foreground" : "min-w-0 text-foreground/80"}>
                  <Value value={row.proposed} />
                </dd>
              </dl>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/** What the review screen shows for a request's proposal — never raw data. */
export function ChangeRequestProposal({ typeAvailable, presentation }: { typeAvailable: boolean; presentation: unknown }) {
  const rows = typeAvailable ? comparisonRows(presentation) : null;
  if (rows) return <ChangeRequestComparison rows={rows} />;

  return (
    <div className="flex items-start gap-2.5 rounded-control bg-surface-2 px-3.5 py-3 text-sm text-muted-foreground" data-proposal-unavailable>
      <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
      <p>
        {typeAvailable
          ? "عرض تفاصيل هذا النوع من الطلبات غير مدعوم في هذه الواجهة بعد."
          : "تفاصيل هذا النوع من الطلبات غير متاحة حاليًا."}
      </p>
    </div>
  );
}
