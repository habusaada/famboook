"use client";

import { useState } from "react";
import { AlertCircle, FileSpreadsheet } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { useAuth } from "@/components/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import { useCreateImportBatch, useReplaceImportWorkbook, useSelectImportWorksheet } from "@/lib/api/imports";
import { ProcessList, type ProcessState } from "@/components/administration/import-wizard/wizard-parts";
import { fmt, formatBytes } from "@/components/administration/import-wizard/labels";
import type { ImportBatchDetail, ImportMode } from "@/lib/types/api/imports";

const UPLOAD_STEPS = ["رفع الملف", "قراءة ملف Excel", "فحص أوراق العمل", "قراءة الأعمدة", "الملف جاهز لتعيين الأعمدة"];

/** Upload / inspection errors, never internal details. 409 points to the live batch. */
function uploadError(error: unknown): { message: string; existingId?: string } {
  if (error instanceof ApiError && error.status === 409) {
    const existing = (error.payload as { existing_batch?: { id: string } } | null)?.existing_batch;
    return { message: "هذا الملف مرفوع مسبقًا لنفس العشيرة ولم يُغلق بعد.", existingId: existing?.id };
  }
  if (error instanceof ApiError && error.status === 422) {
    const errors = error.validationErrors ?? {};
    return {
      message:
        errors.file?.[0] ?? errors.worksheet?.[0] ?? errors.clan_code?.[0] ?? errors.import_mode?.[0] ?? errors.batch?.[0] ?? "تعذّر فحص الملف.",
    };
  }
  if (error instanceof ApiError && error.status === 413) return { message: "حجم الملف يتجاوز الحد المسموح." };
  if (error instanceof ApiError && error.status === 403) return { message: "لا تملك صلاحية رفع ملفات الاستيراد." };
  return { message: "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى." };
}

function ErrorAlert({ error, onOpenBatch }: { error: unknown; onOpenBatch: (id: string) => void }) {
  const e = uploadError(error);
  return (
    <Alert variant="destructive">
      <AlertCircle className="size-4" />
      <AlertTitle>تعذّر إكمال العملية</AlertTitle>
      <AlertDescription className="flex flex-wrap items-center gap-2">
        {e.message}
        {e.existingId && (
          <Button variant="link" className="h-auto p-0" onClick={() => onOpenBatch(e.existingId!)}>
            فتح الدفعة الموجودة
          </Button>
        )}
      </AlertDescription>
    </Alert>
  );
}

/** Truthful states: the server reads and inspects inside the upload request. */
function uploadStates(pending: boolean, done: boolean): { label: string; state: ProcessState }[] {
  return UPLOAD_STEPS.map((label, i) => ({
    label,
    state: done ? "done" : pending && i === 0 ? "active" : "waiting",
  }));
}

function WorksheetChooser({ batch }: { batch: ImportBatchDetail }) {
  const select = useSelectImportWorksheet(batch.id);
  const plausible = batch.worksheets.filter((w) => w.plausible);
  const [choice, setChoice] = useState(batch.worksheet_name ?? "");
  const changing = choice !== "" && choice !== batch.worksheet_name;

  return (
    <div className="flex flex-col gap-2">
      <p className="text-sm font-medium">ورقة البيانات</p>
      {batch.worksheet_name === null && (
        <p className="text-sm text-warning">يحتوي الملف على أكثر من ورقة محتملة؛ اختر ورقة البيانات صراحة.</p>
      )}
      <div className="flex flex-col gap-1.5">
        {plausible.map((w) => (
          <label key={w.name} className="flex cursor-pointer items-center gap-3 rounded-md border border-stroke-subtle p-2.5 has-checked:border-brand-600 has-checked:bg-brand-50">
            <input type="radio" name="worksheet" value={w.name} checked={choice === w.name} onChange={() => setChoice(w.name)} className="size-4 accent-brand-600" />
            <span className="font-medium">{w.name}</span>
            <span className="text-xs text-muted-foreground">
              {fmt(w.data_rows)} صف — {fmt(w.column_count)} عمود{w.too_many_rows ? " — يتجاوز الحد" : ""}
            </span>
          </label>
        ))}
      </div>
      {changing && (
        <div className="flex flex-col gap-1.5">
          {batch.mapping_confirmed_at && <p className="text-xs text-warning">تغيير ورقة العمل يلغي تعيين الأعمدة والصفوف المجهّزة.</p>}
          <Button className="w-fit" disabled={select.isPending} onClick={() => select.mutate({ worksheet: choice })}>
            {select.isPending ? "جارٍ الاعتماد…" : "اعتماد ورقة العمل"}
          </Button>
        </div>
      )}
      {select.error && <ErrorAlert error={select.error} onOpenBatch={() => undefined} />}
    </div>
  );
}

function ReplaceWorkbook({ batch }: { batch: ImportBatchDetail }) {
  const replace = useReplaceImportWorkbook(batch.id);
  const [file, setFile] = useState<File | null>(null);

  return (
    <div className="flex flex-col gap-2 border-t border-stroke-subtle pt-4">
      <Label htmlFor="replace-file">استبدال الملف</Label>
      <p className="text-xs text-muted-foreground">
        يُبقي العشيرة ونوع العملية، ويلغي ورقة العمل وتعيين الأعمدة والصفوف المجهّزة لهذه الدفعة.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <Input id="replace-file" type="file" accept=".xlsx" className="w-full sm:w-80" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        <Button variant="outline" disabled={!file || replace.isPending} onClick={() => file && replace.mutate({ file })}>
          {replace.isPending ? "جارٍ الرفع والفحص…" : "استبدال"}
        </Button>
      </div>
      {replace.error && <ErrorAlert error={replace.error} onOpenBatch={() => undefined} />}
    </div>
  );
}

export function StepFile({
  batch,
  clanCode,
  mode,
  onBatch,
}: {
  batch: ImportBatchDetail | null;
  clanCode: string;
  mode: ImportMode | "";
  onBatch: (id: string) => void;
}) {
  const { can } = useAuth();
  const create = useCreateImportBatch();
  const [file, setFile] = useState<File | null>(null);
  const sheet = batch?.worksheets.find((w) => w.name === batch.worksheet_name);

  return (
    <Panel className="flex flex-col gap-5">
      <SectionHeader
        icon={FileSpreadsheet}
        title="ملف البيانات"
        description="يُفحص الملف فقط في هذه الخطوة؛ لا تُجهَّز الصفوف قبل اعتماد تعيين الأعمدة."
      />

      {!batch && (
        <div className="flex flex-col gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="import-file">ملف Excel ‏(‎.xlsx‎ — حتى 10 م.ب)</Label>
            <Input
              id="import-file"
              type="file"
              accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
              className="w-full sm:w-96"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              disabled={!can("import.upload")}
            />
          </div>
          <Button
            className="w-fit"
            disabled={!file || !clanCode || !mode || create.isPending || !can("import.upload")}
            onClick={() => file && mode && create.mutate({ clanCode, mode, file }, { onSuccess: (res) => onBatch(res.data.id) })}
          >
            {create.isPending ? "جارٍ الرفع والفحص…" : "رفع الملف وفحصه"}
          </Button>
          {(create.isPending || create.isSuccess) && (
            <div className="flex flex-col gap-2">
              <ProcessList items={uploadStates(create.isPending, create.isSuccess)} />
              {create.isPending && (
                <p className="text-xs text-muted-foreground">تجري القراءة والفحص على الخادم ضمن طلب الرفع نفسه، دون نسبة تقدّم.</p>
              )}
            </div>
          )}
          {create.error && <ErrorAlert error={create.error} onOpenBatch={onBatch} />}
        </div>
      )}

      {batch && (
        <>
          <ProcessList items={uploadStates(false, true)} />
          <div className="rounded-md border border-stroke-subtle" data-file-status>
            <p className="border-b border-stroke-subtle px-4 py-2 text-sm font-semibold">حالة الملف</p>
            <dl className="grid gap-x-6 gap-y-2 p-4 text-sm sm:grid-cols-2">
              <div className="flex justify-between gap-2"><dt className="text-muted-foreground">اسم الملف</dt><dd className="truncate font-medium">{batch.source_filename}</dd></div>
              <div className="flex justify-between gap-2"><dt className="text-muted-foreground">حالة الرفع</dt><dd className="font-medium text-success">تم الرفع والفحص</dd></div>
              <div className="flex justify-between gap-2"><dt className="text-muted-foreground">حجم الملف</dt><dd className="font-medium">{formatBytes(batch.source_size_bytes)}</dd></div>
              <div className="flex justify-between gap-2"><dt className="text-muted-foreground">ورقة البيانات</dt><dd className="font-medium">{batch.worksheet_name ?? "بانتظار الاختيار"}</dd></div>
              <div className="flex justify-between gap-2"><dt className="text-muted-foreground">عدد الصفوف المكتشفة</dt><dd className="font-medium tabular-nums">{sheet ? fmt(sheet.data_rows) : "—"}</dd></div>
              <div className="flex justify-between gap-2"><dt className="text-muted-foreground">عدد الأعمدة</dt><dd className="font-medium tabular-nums">{sheet ? fmt(sheet.column_count) : "—"}</dd></div>
            </dl>
          </div>
          <WorksheetChooser key={`${batch.id}:${batch.worksheet_name}:${batch.source_filename}`} batch={batch} />
          {can("import.upload") && <ReplaceWorkbook batch={batch} />}
        </>
      )}
    </Panel>
  );
}
