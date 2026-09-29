"use client";

import { useState } from "react";
import { ClipboardCheck, Rocket } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { useImportProblemRows } from "@/lib/api/imports";
import { FIELD_LABELS, MODE_LABELS, ROW_STATUS_LABELS, fmt, issueLabel } from "@/components/administration/import-wizard/labels";
import { NotAppliedNote, StagingCounts } from "@/components/administration/import-wizard/wizard-parts";
import { ReconciliationCounts, ReconciliationPanel } from "@/components/administration/import-wizard/step-reconciliation";
import type { ImportBatchDetail, ProblemRowFilter } from "@/lib/types/api/imports";

/** Backend issue breakdown (rows per issue code) — never recounted here. */
export function IssueBreakdown({ issues }: { issues: Record<string, number> }) {
  const entries = Object.entries(issues).sort((a, b) => b[1] - a[1]);
  if (entries.length === 0) return <p className="text-sm text-muted-foreground">لا توجد مشكلات مسجلة.</p>;
  return (
    <div className="flex flex-col gap-1.5" data-issue-breakdown>
    <div className="overflow-x-auto rounded-md border border-stroke-subtle">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="ps-4">المشكلة</TableHead>
            <TableHead className="pe-4 text-end">عدد الصفوف</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {entries.map(([code, count]) => (
            <TableRow key={code} data-issue={code}>
              <TableCell className="ps-4">{issueLabel(code)}</TableCell>
              <TableCell className="pe-4 text-end tabular-nums">{fmt(count)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
    <p className="text-xs text-muted-foreground">
      يُحسب كل صف مرة واحدة لكل نوع مشكلة، وقد يحمل الصف أكثر من مشكلة؛ لذلك قد لا يساوي مجموع هذه الأعداد عدد الصفوف التي تحتاج مراجعة.
    </p>
    </div>
  );
}

/** Read-only problem rows: row number, status, issue codes, key — nothing else. */
export function ProblemRows({ batchId }: { batchId: string }) {
  const [filter, setFilter] = useState<ProblemRowFilter>("all");
  const [page, setPage] = useState(1);
  const rows = useImportProblemRows(batchId, filter, page, true);
  const meta = rows.data?.meta;

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-2" role="group" aria-label="تصفية الصفوف">
        {(["all", "needs_review", "rejected"] as ProblemRowFilter[]).map((f) => (
          <Button
            key={f}
            size="sm"
            variant={filter === f ? "default" : "outline"}
            onClick={() => {
              setFilter(f);
              setPage(1);
            }}
          >
            {f === "all" ? "الكل" : f === "needs_review" ? "يحتاج مراجعة" : "مرفوض"}
          </Button>
        ))}
      </div>
      {rows.isLoading ? (
        <Skeleton className="h-24 w-full" />
      ) : (rows.data?.data ?? []).length === 0 ? (
        <p className="text-sm text-muted-foreground">لا توجد صفوف في هذا التصنيف.</p>
      ) : (
        <div className="overflow-x-auto rounded-md border border-stroke-subtle">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="ps-4">رقم الصف في Excel</TableHead>
                <TableHead>الحالة</TableHead>
                <TableHead>نوع المشكلة</TableHead>
                <TableHead className="pe-4">مفتاح الأسرة</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.data!.data.map((r) => (
                <TableRow key={r.row_number}>
                  <TableCell className="ps-4 tabular-nums">{fmt(r.row_number)}</TableCell>
                  <TableCell>
                    <Badge variant={r.status === "REJECTED" ? "destructive" : "secondary"}>{ROW_STATUS_LABELS[r.status] ?? r.status}</Badge>
                  </TableCell>
                  <TableCell className="whitespace-normal">{r.issues.map(issueLabel).join("، ")}</TableCell>
                  <TableCell className="pe-4">{r.source_family_key ?? <span className="text-muted-foreground">—</span>}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center gap-2 text-sm">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>السابق</Button>
          <span className="tabular-nums text-muted-foreground">
            صفحة {fmt(meta.current_page)} من {fmt(meta.last_page)} — {fmt(meta.total)} صف
          </span>
          <Button size="sm" variant="outline" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>التالي</Button>
        </div>
      )}
    </div>
  );
}

export function StepReview({ batch }: { batch: ImportBatchDetail }) {
  const mapped = Object.keys(batch.column_mapping?.fields ?? {});
  return (
    <div className="flex flex-col gap-4">
      <Panel className="flex flex-col gap-5">
        <SectionHeader icon={ClipboardCheck} title="مراجعة البيانات" description="مراجعة نتائج تجهيز الملف قبل أي تطبيق على السجل." />
        <Alert>
          <AlertDescription className="font-medium">لم يتم تطبيق البيانات على السجل بعد.</AlertDescription>
        </Alert>
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          <div className="flex justify-between gap-2"><dt className="text-muted-foreground">العشيرة المستهدفة</dt><dd className="font-medium">{batch.clan?.name}</dd></div>
          <div className="flex justify-between gap-2"><dt className="text-muted-foreground">نوع العملية</dt><dd className="font-medium">{MODE_LABELS[batch.import_mode].title}</dd></div>
          <div className="flex justify-between gap-2"><dt className="text-muted-foreground">اسم الملف</dt><dd className="truncate font-medium">{batch.source_filename}</dd></div>
          <div className="flex justify-between gap-2"><dt className="text-muted-foreground">ورقة البيانات</dt><dd className="font-medium">{batch.worksheet_name}</dd></div>
          <div className="flex justify-between gap-2">
            <dt className="text-muted-foreground">تعيين الأعمدة</dt>
            <dd className="font-medium">
              {batch.mapping_confirmed_at ? `معتمد (${fmt(mapped.length)} حقلًا)` : "غير معتمد"}
            </dd>
          </div>
          <div className="flex justify-between gap-2"><dt className="text-muted-foreground">مفاتيح الأسر</dt><dd className="font-medium">{fmt(batch.summary.distinct_family_keys)} مفتاحًا — {fmt(batch.summary.missing_family_key)} بلا مفتاح</dd></div>
        </dl>
        {mapped.length > 0 && (
          <p className="text-xs text-muted-foreground">الحقول المعيّنة: {mapped.map((f) => FIELD_LABELS[f] ?? f).join("، ")}</p>
        )}
        <StagingCounts counts={batch.summary.counts} />
        <div className="flex flex-col gap-2">
          <p className="text-sm font-semibold">تفصيل المشكلات</p>
          <IssueBreakdown issues={batch.summary.issues} />
        </div>
      </Panel>

      {/* Family-key decisions (backend counts). Decisions are not an import. */}
      <Panel className="flex flex-col gap-3" data-section="key-resolution">
        <SectionHeader title="مفاتيح الأسر" description="قرارات حسم مفاتيح العائلة لهذه الدفعة — لم تُنشأ أي أسرة بعد." />
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
          {[
            ["إجمالي المفاتيح", batch.summary.key_resolution.distinct_keys],
            ["تم الحسم", batch.summary.key_resolution.resolved_keys],
            ["غير محسوم", batch.summary.key_resolution.unresolved_keys],
            ["مرتبطة بفروع موجودة", batch.summary.key_resolution.match_existing],
            ["مرتبطة بنفس فرع مفتاح آخر", batch.summary.key_resolution.same_branch_as_key],
            ["فروع جديدة", batch.summary.key_resolution.created_branch],
            ["بدون فرع", batch.summary.key_resolution.no_branch],
          ].map(([label, value]) => (
            <div key={label as string} className="flex justify-between gap-2">
              <dt className="text-muted-foreground">{label}</dt>
              <dd className="font-medium tabular-nums">{fmt(value as number)}</dd>
            </div>
          ))}
        </dl>
      </Panel>

      <Panel className="flex flex-col gap-3">
        <SectionHeader title="الصفوف التي تحتاج مراجعة" description="للاطلاع فقط: رقم الصف والحالة ونوع المشكلة ومفتاح الأسرة — دون بيانات شخصية." />
        <ProblemRows batchId={batch.id} />
      </Panel>

      {/* Reserved for future reconciliation — no counts are fabricated. */}
      <ReconciliationPanel batch={batch} />
    </div>
  );
}

/** Shell only: no Apply action; shows the (current) reconciliation result. */
export function StepApply({ batch }: { batch: ImportBatchDetail }) {
  return (
    <Panel className="flex flex-col gap-4">
      <SectionHeader icon={Rocket} title="الاعتماد والاستيراد" description="هذه الخطوة غير مفعّلة في المرحلة الحالية." />
      <p className="font-medium">تمت مراجعة الملف وتجهيزه، ولم يتم تطبيق البيانات على السجل بعد.</p>
      <ReconciliationCounts batch={batch} />
      <div className="flex flex-col gap-1.5 text-sm text-muted-foreground">
        <p>ستلخّص هذه الخطوة لاحقًا:</p>
        <ul className="list-inside list-disc">
          <li>إضافة سجلات جديدة</li>
          <li>تجاهل السجلات غير المتغيرة</li>
          <li>مراجعة التغييرات</li>
          <li>معالجة التعارضات</li>
        </ul>
      </div>
      <NotAppliedNote />
    </Panel>
  );
}
