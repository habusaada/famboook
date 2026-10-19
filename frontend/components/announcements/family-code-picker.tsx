"use client";

import { useEffect, useState } from "react";
import { Check, Plus, Search, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Code } from "@/components/shared/page-layout";
import { ANNOUNCEMENT_MAX_FAMILIES, useStaffFamilySearch } from "@/lib/api/announcements";
import { ApiError } from "@/lib/api/client";

const fmt = (n: number) => n.toLocaleString("ar");

/**
 * The Staff selected-family picker (FB-NOTIFY-01C.3c): searches the existing
 * registry (ACTIVE families) and keeps PUBLIC family codes only — the same
 * pattern as the assistance nominee picker. Never an internal id. At most
 * ANNOUNCEMENT_MAX_FAMILIES distinct families.
 */
export function StaffFamilyCodePicker({
  value,
  onChange,
  invalid,
  describedBy,
}: {
  value: string[];
  onChange: (codes: string[]) => void;
  invalid?: boolean;
  describedBy?: string;
}) {
  const [text, setText] = useState("");
  const [term, setTerm] = useState("");
  useEffect(() => {
    const timer = setTimeout(() => setTerm(text.trim()), 350);
    return () => clearTimeout(timer);
  }, [text]);
  const search = useStaffFamilySearch(term);
  const selected = new Set(value);
  const full = selected.size >= ANNOUNCEMENT_MAX_FAMILIES;

  const add = (code: string) => {
    if (selected.has(code) || full) return;
    onChange([...value, code]);
  };
  const remove = (code: string) => onChange(value.filter((c) => c !== code));

  return (
    <div className="flex flex-col gap-3" data-family-code-picker>
      <div className="relative">
        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-subtle-foreground" aria-hidden />
        <Input
          type="search"
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="ابحث برمز الأسرة أو اسم رب الأسرة"
          aria-label="البحث عن أسرة"
          aria-invalid={invalid || undefined}
          aria-describedby={describedBy}
          className="h-11 bg-surface-2 ps-9"
          data-family-search
        />
      </div>

      {term.length >= 2 && (
        <ul className="max-h-60 divide-y overflow-y-auto rounded-lg border" aria-label="نتائج البحث عن الأسر" data-family-results>
          {search.isPending && <li className="p-3 text-sm text-muted-foreground">جارٍ البحث…</li>}
          {search.isError && (
            <li className="p-3 text-sm text-danger" role="alert">
              {search.error instanceof ApiError && search.error.status === 403 ? "لا تملك صلاحية البحث في سجل الأسر." : "تعذّر البحث. حاول مرة أخرى."}
            </li>
          )}
          {search.data?.data.length === 0 && <li className="p-3 text-sm text-muted-foreground">لا توجد أسر نشطة مطابقة.</li>}
          {search.data?.data.map((family) => {
            const chosen = selected.has(family.family_code);
            return (
              <li key={family.family_code} className="flex items-center justify-between gap-2 px-3 py-2">
                <span className="flex min-w-0 flex-col">
                  <Code className="text-sm">{family.family_code}</Code>
                  {family.household_head_name && <span className="truncate text-xs text-muted-foreground">{family.household_head_name}</span>}
                </span>
                <Button
                  type="button"
                  size="sm"
                  variant={chosen ? "ghost" : "outline"}
                  disabled={chosen || full}
                  onClick={() => add(family.family_code)}
                  aria-label={chosen ? `${family.family_code} مضافة` : `إضافة ${family.family_code}`}
                  data-family-add={family.family_code}
                >
                  {chosen ? <Check className="size-4" aria-hidden /> : <Plus className="size-4" aria-hidden />}
                  {chosen ? "مضافة" : "إضافة"}
                </Button>
              </li>
            );
          })}
        </ul>
      )}

      <div className="flex flex-col gap-2">
        <p className="text-[13px] text-muted-foreground" aria-live="polite" data-family-selected-count>
          الأسر المختارة: <bdi className="font-semibold text-foreground tabular-nums">{fmt(selected.size)}</bdi> من{" "}
          <bdi className="tabular-nums">{fmt(ANNOUNCEMENT_MAX_FAMILIES)}</bdi>
          {full && " — بلغت الحد الأقصى."}
        </p>
        {value.length > 0 && (
          <ul className="flex flex-wrap gap-1.5" aria-label="الأسر المختارة" data-family-selected>
            {value.map((code) => (
              <li key={code} className="flex items-center gap-1 rounded-md border bg-surface-2 py-0.5 ps-2 pe-0.5 text-sm">
                <Code>{code}</Code>
                <button
                  type="button"
                  onClick={() => remove(code)}
                  aria-label={`إزالة ${code}`}
                  className="rounded p-1 text-muted-foreground hover:bg-surface-hover hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                  data-family-remove={code}
                >
                  <X className="size-3.5" aria-hidden />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
