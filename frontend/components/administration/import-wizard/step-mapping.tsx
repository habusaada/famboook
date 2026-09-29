"use client";

import { useMemo, useState } from "react";
import { AlertCircle, Columns3 } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { useAuth } from "@/components/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import { useConfirmImportMapping, useImportColumns } from "@/lib/api/imports";
import { FIELD_LABELS, fmt } from "@/components/administration/import-wizard/labels";
import { NotAppliedNote, ProcessList, StagingCounts, type ProcessState } from "@/components/administration/import-wizard/wizard-parts";
import { IssueBreakdown, ProblemRows } from "@/components/administration/import-wizard/step-review";
import type { ImportBatchDetail, ImportColumns } from "@/lib/types/api/imports";

const NONE = "__none__";
const STAGING_STEPS = ["اعتماد تعيين الأعمدة", "قراءة الصفوف", "التحقق البنيوي", "تجهيز الصفوف", "إعداد ملخص المشكلات"];

function initialDraft(data: ImportColumns): { fields: Record<string, string>; ignored: Record<string, boolean> } {
  const fields: Record<string, string> = {};
  if (data.confirmed_mapping) {
    Object.assign(fields, data.confirmed_mapping.fields);
  } else {
    for (const c of data.columns) if (c.suggested_field) fields[c.suggested_field] = c.letter;
  }
  const mapped = new Set(Object.values(fields));
  const ignored: Record<string, boolean> = {};
  // Unmapped columns start as "ignore"; the administrator sees and can change them.
  for (const c of data.columns) if (!mapped.has(c.letter)) ignored[c.letter] = true;
  return { fields, ignored };
}

function MappingEditor({ batch, data }: { batch: ImportBatchDetail; data: ImportColumns }) {
  const { can } = useAuth();
  const confirm = useConfirmImportMapping(batch.id);
  const [draft, setDraft] = useState(() => initialDraft(data));
  const [showResult, setShowResult] = useState(false);

  const used = useMemo(() => new Map(Object.entries(draft.fields).map(([f, l]) => [l, f])), [draft.fields]);
  const unmapped = data.columns.filter((c) => !used.has(c.letter));
  const missingRequired = data.fields.filter((f) => f.required && !draft.fields[f.field]);
  const undecided = unmapped.filter((c) => !draft.ignored[c.letter]);
  const blocked = missingRequired.length > 0 || undecided.length > 0;
  const serverErrors = confirm.error instanceof ApiError && confirm.error.status === 422 ? confirm.error.validationErrors ?? {} : {};

  function setField(field: string, letter: string) {
    setDraft((d) => {
      const fields = { ...d.fields };
      if (letter === NONE) delete fields[field];
      else fields[field] = letter;
      const ignored = { ...d.ignored };
      if (letter !== NONE) delete ignored[letter];
      const prev = d.fields[field];
      if (prev && prev !== letter) ignored[prev] = true; // a released column returns to "ignore"
      return { fields, ignored };
    });
    setShowResult(false);
  }

  function submit() {
    const mapping: Record<string, string | null> = {};
    for (const f of data.fields) mapping[f.field] = draft.fields[f.field] ?? null;
    const ignored = unmapped.filter((c) => draft.ignored[c.letter]).map((c) => c.letter);
    setShowResult(true);
    confirm.mutate({ mapping, ignored });
  }

  const staged = confirm.isSuccess ? confirm.data.data : batch.staged ? batch : null;
  const processItems: { label: string; state: ProcessState }[] = STAGING_STEPS.map((label, i) => ({
    label,
    state: confirm.isSuccess ? "done" : confirm.isPending ? (i === 0 ? "active" : "waiting") : "waiting",
  }));

  const fieldRow = (field: string, required: boolean) => (
    <TableRow key={field}>
      <TableCell className="ps-4 font-medium">
        <span className="flex items-center gap-2">
          {FIELD_LABELS[field] ?? field}
          {required ? <Badge variant="secondary">مطلوب</Badge> : <span className="text-xs text-muted-foreground">اختياري</span>}
        </span>
        {serverErrors[`mapping.${field}`] && <p className="text-xs text-danger">{serverErrors[`mapping.${field}`][0]}</p>}
      </TableCell>
      <TableCell className="pe-4">
        <Select value={draft.fields[field] ?? NONE} onValueChange={(v) => setField(field, v)}>
          <SelectTrigger className="w-full sm:w-72" aria-label={FIELD_LABELS[field]}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={NONE}>— غير معيّن —</SelectItem>
            {data.columns.map((c) => (
              <SelectItem key={c.letter} value={c.letter} disabled={used.has(c.letter) && used.get(c.letter) !== field}>
                <span dir="ltr" className="font-mono text-xs">{c.letter}</span> · {c.header}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </TableCell>
    </TableRow>
  );

  const scalar = data.fields.filter((f) => !f.field.startsWith("wife_"));
  const wives = data.fields.filter((f) => f.field.startsWith("wife_"));

  return (
    <div className="flex flex-col gap-5">
      {batch.staged && !confirm.isSuccess && (
        <Alert>
          <AlertDescription>هذه الدفعة مجهّزة بتعيين معتمد. اعتماد تعيين مختلف يعيد تجهيز صفوفها.</AlertDescription>
        </Alert>
      )}

      <div className="overflow-x-auto rounded-md border border-stroke-subtle">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="ps-4">حقل Famboook</TableHead>
              <TableHead className="pe-4">عمود Excel</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {scalar.map((f) => fieldRow(f.field, f.required))}
            <TableRow className="hover:bg-transparent">
              <TableCell colSpan={2} className="ps-4 pt-4 text-xs font-semibold text-muted-foreground">
                الزوجات — تُميَّز الأعمدة المتكررة بموضعها (حرف العمود)
              </TableCell>
            </TableRow>
            {wives.map((f) => fieldRow(f.field, f.required))}
          </TableBody>
        </Table>
      </div>

      <div className="flex flex-col gap-2">
        <p className="text-sm font-semibold">أعمدة غير مربوطة بحقل</p>
        {unmapped.length === 0 ? (
          <p className="text-sm text-muted-foreground">كل الأعمدة مربوطة بحقول.</p>
        ) : (
          <ul className="flex flex-col gap-1.5">
            {unmapped.map((c) => (
              <li key={c.letter} className="flex flex-wrap items-center gap-3 rounded-md border border-stroke-subtle px-3 py-2 text-sm">
                <span dir="ltr" className="font-mono text-xs">{c.letter}</span>
                <span className="font-medium">{c.header}</span>
                <label className="ms-auto flex items-center gap-2">
                  <input
                    type="checkbox"
                    className="size-4 accent-brand-600"
                    checked={!!draft.ignored[c.letter]}
                    onChange={(e) => setDraft((d) => ({ ...d, ignored: { ...d.ignored, [c.letter]: e.target.checked } }))}
                  />
                  تجاهل هذا العمود
                </label>
                {!draft.ignored[c.letter] && <span className="w-full text-xs text-danger">اربط هذا العمود بحقل أو تجاهله.</span>}
              </li>
            ))}
          </ul>
        )}
        {serverErrors.ignored && <p className="text-xs text-danger">{serverErrors.ignored[0]}</p>}
      </div>

      {data.excluded_columns.length > 0 && (
        <div className="flex flex-col gap-1.5 rounded-md border border-dashed border-stroke-subtle p-3" data-excluded-columns>
          <p className="text-sm font-semibold">أعمدة مستبعدة</p>
          <p className="text-xs text-muted-foreground">خارج بيانات Famboook المعتمدة: لا تُعرض قيمها ولا تُحفظ ولا يمكن ربطها.</p>
          <ul className="flex flex-wrap gap-2">
            {data.excluded_columns.map((c) => (
              <li key={c.letter}>
                <Badge variant="outline">
                  <span dir="ltr" className="font-mono">{c.letter}</span> · {c.header}
                </Badge>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="flex flex-col gap-2">
        <p className="text-sm font-semibold">معاينة</p>
        <div className="overflow-x-auto rounded-md border border-stroke-subtle">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="ps-4">عمود Excel</TableHead>
                <TableHead>اسم العمود</TableHead>
                <TableHead>حقل Famboook</TableHead>
                <TableHead className="pe-4">عينة</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.columns.map((c) => (
                <TableRow key={c.letter}>
                  <TableCell className="ps-4"><span dir="ltr" className="font-mono text-xs">{c.letter}</span></TableCell>
                  <TableCell>{c.header}</TableCell>
                  <TableCell>{used.has(c.letter) ? FIELD_LABELS[used.get(c.letter)!] : <span className="text-muted-foreground">تجاهل</span>}</TableCell>
                  <TableCell className="pe-4 text-muted-foreground">{c.samples[0] ?? "—"}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </div>

      {missingRequired.length > 0 && (
        <p className="text-sm text-danger">الحقول المطلوبة غير المعيّنة: {missingRequired.map((f) => FIELD_LABELS[f.field]).join("، ")}</p>
      )}
      <Button className="w-fit" disabled={blocked || confirm.isPending || !can("import.validate")} onClick={submit}>
        {confirm.isPending ? "جارٍ المعالجة…" : "اعتماد تعيين الأعمدة"}
      </Button>

      {showResult && (confirm.isPending || confirm.isSuccess) && (
        <div className="flex flex-col gap-2">
          <ProcessList items={processItems} />
          {confirm.isPending && (
            <p className="text-xs text-muted-foreground">تجري القراءة والتحقق والتجهيز على الخادم ضمن الطلب نفسه؛ لا تتوفر نسبة تقدّم حقيقية.</p>
          )}
          {confirm.isSuccess && <p className="text-sm font-semibold text-success">اكتملت المعالجة</p>}
        </div>
      )}
      {confirm.error && !(confirm.error instanceof ApiError && confirm.error.status === 422) && (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تجهيز الصفوف</AlertTitle>
          <AlertDescription>
            {confirm.error instanceof ApiError && confirm.error.status === 409 ? "ملف هذه الدفعة غير متاح. ارفع الملف مرة أخرى." : "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى."}
          </AlertDescription>
        </Alert>
      )}

      {staged && (
        <div className="flex flex-col gap-3 border-t border-stroke-subtle pt-4">
          <StagingCounts counts={staged.summary.counts} />
          <p className="text-sm font-medium">تم تجهيز {fmt(staged.summary.counts.ready)} صف بنجاح.</p>
          <NotAppliedNote />

          {/* WHY rows need review — right under the counts, no step change needed. */}
          {staged.summary.counts.needs_review + staged.summary.counts.rejected > 0 && (
            <>
              <div className="flex flex-col gap-2 pt-2" data-section="issue-summary">
                <p className="text-sm font-semibold">ملخص المشكلات</p>
                <IssueBreakdown issues={staged.summary.issues} />
              </div>
              <div className="flex flex-col gap-2 pt-2" data-section="problem-rows">
                <p className="text-sm font-semibold">الصفوف التي تحتاج مراجعة</p>
                <p className="text-xs text-muted-foreground">
                  للاطلاع فقط: رقم الصف والحالة ونوع المشكلة ومفتاح العائلة — دون بيانات شخصية.
                </p>
                <ProblemRows batchId={staged.id} />
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}

export function StepMapping({ batch }: { batch: ImportBatchDetail }) {
  const columns = useImportColumns(batch.id, batch.worksheet_name);

  return (
    <Panel className="flex flex-col gap-5">
      <SectionHeader
        icon={Columns3}
        title="تعيين الأعمدة"
        description={`راجع كيف ترتبط أعمدة ورقة «${batch.worksheet_name ?? ""}» بحقول Famboook ثم اعتمدها. الاقتراحات قابلة للتعديل.`}
      />
      {columns.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : columns.error || !columns.data ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر قراءة الأعمدة</AlertTitle>
        </Alert>
      ) : (
        // Remount (reset the draft) only when the sheet's columns change —
        // a new worksheet or replaced file — never merely after confirming.
        <MappingEditor
          key={`${batch.id}:${columns.data.data.worksheet}:${columns.data.data.columns.map((c) => `${c.letter}=${c.header}`).join("|")}`}
          batch={batch}
          data={columns.data.data}
        />
      )}
    </Panel>
  );
}
