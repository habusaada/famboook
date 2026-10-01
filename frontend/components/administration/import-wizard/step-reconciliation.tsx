"use client";

import { Fragment, useState } from "react";
import { AlertTriangle, GitCompareArrows } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { useAuth } from "@/components/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import { useReconcileImportBatch, useReconciliationRows } from "@/lib/api/imports";
import {
  DIFF_FIELD_LABELS,
  FAMILY_MATCH_LABELS,
  HEAD_MATCH_LABELS,
  RECON_ISSUE_LABELS,
  RECON_STATUS_LABELS,
  fmt,
  isApplyStarted,
} from "@/components/administration/import-wizard/labels";
import { ProcessList } from "@/components/administration/import-wizard/wizard-parts";
import type { ImportBatchDetail, ReconciliationIssue, ReconciliationRow, ReconciliationStatus } from "@/lib/types/api/imports";

const STATUSES: ReconciliationStatus[] = ["NEW", "UNCHANGED", "CHANGED", "DUPLICATE_IN_FILE", "CONFLICT", "REVIEW_REQUIRED"];
const TONE: Record<ReconciliationStatus, string> = {
  NEW: "text-brand-700",
  UNCHANGED: "text-success",
  CHANGED: "text-warning",
  DUPLICATE_IN_FILE: "text-warning",
  CONFLICT: "text-danger",
  REVIEW_REQUIRED: "text-warning",
};

function contextText(issue: ReconciliationIssue): string | null {
  const c = issue.context ?? {};
  const parts: string[] = [];
  const rows = (key: string, label: string) => {
    if (Array.isArray(c[key]) && (c[key] as unknown[]).length > 0) parts.push(`${label}: ${(c[key] as number[]).map(fmt).join("، ")}`);
  };
  rows("rows", "الصفوف");
  rows("head_rows", "رب أسرة في الصفوف");
  rows("spouse_rows", "زوجة في الصفوف");
  if (typeof c.national_id === "string") parts.push(`الهوية ${c.national_id}`);
  if (typeof c.person_code === "string") parts.push(`الشخص ${c.person_code}`);
  if (typeof c.family_code === "string") parts.push(`الأسرة ${c.family_code}`);
  if (typeof c.slot === "number") parts.push(`الزوجة ${fmt(c.slot)}`);
  // Polygamous household: source slot evidence only (not a membership verdict).
  if (typeof c.last_slot === "number") parts.push(`آخر خانة زوجة مستخدمة: ${fmt(c.last_slot)}`);
  if (typeof c.field === "string") parts.push(DIFF_FIELD_LABELS[c.field] ?? c.field);
  // Repeated-wife groups: the heads' canonical life statuses.
  if (typeof c.alive === "number") {
    parts.push(`أرباب أسر أحياء ${fmt(c.alive)}، متوفون ${fmt(c.deceased as number)}، غير معروف ${fmt(c.unknown as number)}`);
  }
  return parts.length ? parts.join(" — ") : null;
}

/** Review-only detail: reasons with safe context and a before/after comparison. */
function RowDetail({ row }: { row: ReconciliationRow }) {
  return (
    <div className="flex flex-col gap-3 bg-surface-2/60 p-3 text-sm">
      {row.issues.length > 0 && (
        <ul className="flex flex-col gap-1">
          {row.issues.map((i, n) => (
            <li key={n}>
              <span className="font-medium">{RECON_ISSUE_LABELS[i.code] ?? "ملاحظة غير مصنّفة"}</span>
              {contextText(i) && <span className="text-muted-foreground"> — {contextText(i)}</span>}
            </li>
          ))}
        </ul>
      )}
      {row.differences.length > 0 && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>الحقل</TableHead>
              <TableHead>السجل الحالي</TableHead>
              <TableHead>ملف الاستيراد</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {row.differences.map((d) => (
              <TableRow key={`${d.scope}-${d.field}`}>
                <TableCell>{DIFF_FIELD_LABELS[d.field] ?? d.field}</TableCell>
                <TableCell>{d.registry ?? <span className="text-muted-foreground">—</span>}</TableCell>
                <TableCell>{d.source ?? <span className="text-muted-foreground">—</span>}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      {row.differences.length > 0 && <p className="text-xs text-muted-foreground">للمراجعة فقط — لا يُطبَّق أي تغيير على السجل في هذه المرحلة.</p>}
    </div>
  );
}

function ReconciliationRows({ batchId }: { batchId: string }) {
  const [status, setStatus] = useState<ReconciliationStatus | "">("");
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const rows = useReconciliationRows(batchId, status, page, true);
  const meta = rows.data?.meta;

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-1.5" role="group" aria-label="تصفية حسب نتيجة المطابقة">
        {(["", ...STATUSES] as (ReconciliationStatus | "")[]).map((s) => (
          <Button key={s || "all"} size="sm" variant={status === s ? "default" : "outline"} onClick={() => { setStatus(s); setPage(1); setOpen(null); }}>
            {s ? RECON_STATUS_LABELS[s] : "الكل"}
          </Button>
        ))}
      </div>
      {rows.isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : (rows.data?.data ?? []).length === 0 ? (
        <p className="text-sm text-muted-foreground">لا توجد صفوف في هذا التصنيف.</p>
      ) : (
        <div className="overflow-x-auto rounded-md border border-stroke-subtle">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="ps-4">رقم الصف</TableHead>
                <TableHead>مفتاح العائلة</TableHead>
                <TableHead>رب الأسرة</TableHead>
                <TableHead>الهوية</TableHead>
                <TableHead>النتيجة</TableHead>
                <TableHead>الشخص / الأسرة</TableHead>
                <TableHead>السبب</TableHead>
                <TableHead className="pe-4"><span className="sr-only">تفاصيل</span></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.data!.data.map((r) => {
                // Informational notes are shown in the details, not as blocking reasons.
                const reasons = r.issues.filter((i) => !["EXISTING_PERSON_NO_FAMILY", "SPOUSE_EXISTING_PERSON", "SPOUSE_REPEATED_AFTER_HEAD_DEATH", "POLYGAMY_INDEPENDENT_WIFE_HOUSEHOLD"].includes(i.code));
                const expandable = r.issues.length > 0 || r.differences.length > 0;
                return (
                  <Fragment key={r.row_number}>
                    <TableRow data-recon-status={r.status}>
                      <TableCell className="ps-4 tabular-nums">{fmt(r.row_number)}</TableCell>
                      <TableCell>{r.source_family_key ?? <span className="text-muted-foreground">—</span>}</TableCell>
                      <TableCell className="whitespace-normal">{r.head_name ?? "—"}</TableCell>
                      <TableCell dir="ltr" className="text-end font-mono text-xs">{r.national_id_masked ?? "—"}</TableCell>
                      <TableCell><Badge variant="secondary" className={TONE[r.status]}>{RECON_STATUS_LABELS[r.status]}</Badge></TableCell>
                      <TableCell className="text-xs">
                        <div>{HEAD_MATCH_LABELS[r.head_match] ?? r.head_match}{r.person_code ? ` (${r.person_code})` : ""}</div>
                        <div className="text-muted-foreground">{FAMILY_MATCH_LABELS[r.family_match] ?? r.family_match}{r.family_code ? ` (${r.family_code})` : ""}</div>
                      </TableCell>
                      <TableCell className="whitespace-normal text-xs">
                        {reasons.length ? reasons.map((i) => RECON_ISSUE_LABELS[i.code] ?? "ملاحظة غير مصنّفة").join("، ") : r.differences.length ? `${fmt(r.differences.length)} اختلاف` : "—"}
                      </TableCell>
                      <TableCell className="pe-4">
                        {expandable && (
                          <Button size="sm" variant="ghost" onClick={() => setOpen(open === r.row_number ? null : r.row_number)}>
                            {open === r.row_number ? "إخفاء" : "تفاصيل"}
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                    {open === r.row_number && (
                      <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={8} className="p-0"><RowDetail row={r} /></TableCell>
                      </TableRow>
                    )}
                  </Fragment>
                );
              })}
            </TableBody>
          </Table>
        </div>
      )}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center gap-2 text-sm">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>السابق</Button>
          <span className="tabular-nums text-muted-foreground">صفحة {fmt(meta.current_page)} من {fmt(meta.last_page)} — {fmt(meta.total)} صف</span>
          <Button size="sm" variant="outline" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>التالي</Button>
        </div>
      )}
    </div>
  );
}

/** Reconciliation counts (backend) — reused by Step 6. */
export function ReconciliationCounts({ batch }: { batch: ImportBatchDetail }) {
  const counts = batch.summary.reconciliation.counts;
  if (!counts) return null;
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" data-reconciliation-counts>
      {STATUSES.map((s) => (
        <div key={s} className="flex flex-col gap-1 rounded-md border border-stroke-subtle bg-surface-1 p-3">
          <span className="text-[13px] text-muted-foreground">{RECON_STATUS_LABELS[s]}</span>
          <span className={`text-2xl font-bold tabular-nums ${counts[s] > 0 ? TONE[s] : "text-foreground"}`}>{fmt(counts[s])}</span>
        </div>
      ))}
    </div>
  );
}

/** Step 5 — "المطابقة مع السجل الحالي": compares, never writes. */
export function ReconciliationPanel({ batch }: { batch: ImportBatchDetail }) {
  const { can } = useAuth();
  const run = useReconcileImportBatch(batch.id);
  const r = batch.summary.reconciliation;
  const stats = r.stats;
  // Once Apply has started, reconciliation is history: Apply's own writes make
  // it stale by design, and it can no longer be re-run.
  const locked = isApplyStarted(batch.status);
  const error = run.error instanceof ApiError && run.error.status === 422 ? Object.values(run.error.validationErrors ?? {})[0]?.[0] : run.error ? "تعذّر تنفيذ المطابقة. الرجاء المحاولة مرة أخرى." : null;

  return (
    <Panel className="flex flex-col gap-4" data-section="reconciliation">
      <SectionHeader
        icon={GitCompareArrows}
        title="المطابقة مع السجل الحالي"
        description="ستتم مقارنة البيانات المجهزة بالسجل الحالي دون إجراء أي تعديل على السجل."
      />

      <div className="flex flex-wrap items-center gap-3">
        {!locked && (
          <Button disabled={run.isPending || !can("import.validate") || !batch.summary.key_resolution.complete} onClick={() => run.mutate()}>
            {run.isPending ? "جارٍ المطابقة…" : r.state === "NOT_RUN" ? "مطابقة البيانات مع السجل الحالي" : "إعادة المطابقة"}
          </Button>
        )}
        {r.reconciled_at && (
          <span className="text-xs text-muted-foreground">آخر مطابقة: {new Date(r.reconciled_at).toLocaleString("ar", { dateStyle: "medium", timeStyle: "short" })}</span>
        )}
      </div>
      {run.isPending && (
        <ProcessList items={[{ label: "مقارنة الصفوف بالأشخاص والأسر في السجل (دون تعديل)", state: "active" }]} />
      )}
      {error && <p className="text-sm text-danger">{error}</p>}

      {locked && (
        <Alert data-reconciliation-read-only>
          <AlertTitle>للاطلاع فقط</AlertTitle>
          <AlertDescription>بدأ تطبيق هذه الدفعة؛ تُعرض نتائج المطابقة كما كانت عند اعتماد المعاينة، ولا يمكن إعادة المطابقة.</AlertDescription>
        </Alert>
      )}

      {r.state === "STALE" && !locked && (
        <Alert>
          <AlertTriangle className="size-4" />
          <AlertTitle>نتائج المطابقة قديمة</AlertTitle>
          <AlertDescription>تغيّر التجهيز أو قرارات المفاتيح أو السجل منذ آخر مطابقة. أعد المطابقة قبل الاعتماد عليها.</AlertDescription>
        </Alert>
      )}

      {r.state !== "NOT_RUN" && (
        <>
          <ReconciliationCounts batch={batch} />
          {(r.requires_review ?? 0) > 0 && (
            <p className="text-sm text-warning">
              {fmt(r.requires_review ?? 0)} صفًا تحتاج مراجعة قبل أي اعتماد مستقبلي (تغييرات، مكرر في الملف، تعارض، يحتاج مراجعة). «جديد» و«بدون تغيير» لا يمنعان.
            </p>
          )}
          {stats && (
            <dl className="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
              {[
                ["رب الأسرة: شخص موجود في السجل", stats.head_existing_person],
                ["رب الأسرة: لا يوجد شخص مطابق", stats.head_no_existing_person],
                ["رب الأسرة: بلا رقم هوية", stats.head_no_national_id],
                ["شخص موجود دون أسرة", stats.head_existing_person_no_family],
                ["أسر مطابقة في السجل", stats.family_matches],
                ["زوجات موجودات في السجل", stats.spouse_existing_person_candidates],
                ["صفوف بتداخل رب أسرة / زوجة", stats.cross_role_collision_rows],
              ].map(([label, value]) => (
                <div key={label as string} className="flex justify-between gap-2">
                  <dt className="text-muted-foreground">{label}</dt>
                  <dd className="font-medium tabular-nums">{fmt(value as number)}</dd>
                </div>
              ))}
            </dl>
          )}
          <ReconciliationRows batchId={batch.id} />
          <p className="text-xs text-muted-foreground">
            المطابقة بالهوية فقط ولا تتم بالاسم. غياب أسرة أو شخص عن الملف لا يعني حذفه أو تعطيله.{locked ? "" : " لم يتم تطبيق البيانات على السجل بعد."}
          </p>
        </>
      )}
    </Panel>
  );
}
