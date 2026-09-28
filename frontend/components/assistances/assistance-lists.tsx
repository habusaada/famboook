"use client";

import { useRef, useState } from "react";
import { AlertTriangle, ArrowDown, ArrowUp, Columns3, Download, Eye, FileSpreadsheet, Plus, Save, Send, Trash2 } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Textarea } from "@/components/ui/textarea";
import { FieldLabel, SaveError } from "@/components/shared/edit-dialog-parts";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import {
  downloadBeneficiaryList,
  useBeneficiaryList,
  useBeneficiaryLists,
  useExportFields,
  useIssueList,
  useListPreview,
  useUpdateExportConfiguration,
} from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import type {
  Assistance,
  AssistanceResponse,
  BeneficiaryList,
  ExportClassification,
  ExportField,
  ExportFieldsResponse,
  ListColumn,
  ListValue,
} from "@/lib/types/api/assistance";
import { formatDateTime } from "@/lib/utils/date";
import { exportClassificationLabels } from "@/lib/utils/assistance";

const SENSITIVE_WARNING = "يحتوي الكشف على بيانات شخصية حساسة.";

function errorText(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    if (e.status === 422 || e.status === 409) {
      const errors = e.validationErrors ?? {};
      const first = Object.values(errors)[0]?.[0];
      return first ?? e.message422 ?? fallback;
    }
    if (e.status === 403) return e.message422 && e.message422 !== "This action is unauthorized." ? e.message422 : "لا تملك الصلاحية اللازمة (قد يتطلب ذلك صلاحية تصدير البيانات الحساسة).";
  }
  return fallback;
}

function ClassificationBadge({ classification }: { classification: ExportClassification }) {
  return (
    <StatusBadge tone={classification === "SENSITIVE" ? "danger" : classification === "CONTACT" ? "warning" : "neutral"}>
      <span className="sr-only">التصنيف: </span>
      {exportClassificationLabels[classification]}
    </StatusBadge>
  );
}

function cell(value: ListValue): string {
  return value === null || value === undefined || value === "" ? "—" : String(value);
}

// ---------------------------------------------------------------------------

function FieldEditor({
  assistance,
  catalog,
  initial,
  canConfigure,
  canSensitive,
  onSavedChange,
}: {
  assistance: Assistance;
  catalog: ExportFieldsResponse["data"]["catalog"];
  initial: ExportField[];
  canConfigure: boolean;
  canSensitive: boolean;
  onSavedChange: (dirty: boolean) => void;
}) {
  const [fields, setFields] = useState<ExportField[]>(initial);
  const [adding, setAdding] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const mutation = useUpdateExportConfiguration(assistance.id);
  const byKey = new Map(catalog.map((f) => [f.field_key, f]));
  const available = catalog.filter((f) => !fields.some((x) => x.field_key === f.field_key));
  const hasSensitive = fields.some((f) => byKey.get(f.field_key)?.classification === "SENSITIVE");

  function update(next: ExportField[]) {
    setFields(next);
    setSaved(false);
    onSavedChange(true);
  }

  function move(index: number, delta: number) {
    const next = [...fields];
    const [item] = next.splice(index, 1);
    next.splice(index + delta, 0, item);
    update(next);
  }

  return (
    <AppCard aria-labelledby="export-fields-title">
      <SectionHeader
        icon={Columns3}
        title={<span id="export-fields-title">١. بيانات الكشف المطلوبة</span>}
        description="الأعمدة التي طلبتها الجهة في كشف المستفيدين، بالترتيب والتسمية المطلوبين. هذه ليست معايير استهداف: تحدد فقط البيانات التي تُرسل عن المستفيدين المعتمدين."
      />
      <div className="mt-4 flex flex-col gap-3">
        <SaveError message={error} />
        {fields.length === 0 ? (
          <p className="rounded-lg border border-dashed border-stroke-subtle p-6 text-center text-sm text-muted-foreground">لم تُحدد أي أعمدة بعد.</p>
        ) : (
          <ol className="flex flex-col gap-2">
            {fields.map((field, index) => {
              const meta = byKey.get(field.field_key);
              return (
                <li key={field.field_key} data-export-field={field.field_key} className="flex flex-wrap items-center gap-2 rounded-lg border border-stroke-subtle bg-surface-1 p-2">
                  <span className="w-6 text-center text-xs text-muted-foreground tabular-nums">{index + 1}</span>
                  <Input
                    aria-label={`اسم عمود ${meta?.default_label ?? field.field_key}`}
                    className="h-8 min-w-48 flex-1"
                    value={field.column_label}
                    disabled={!canConfigure}
                    onChange={(e) => update(fields.map((f, i) => (i === index ? { ...f, column_label: e.target.value } : f)))}
                  />
                  <span className="text-xs text-muted-foreground">
                    {meta?.default_label} <span dir="ltr">({field.field_key})</span>
                  </span>
                  {meta && <ClassificationBadge classification={meta.classification} />}
                  {canConfigure && (
                    <div className="ms-auto flex items-center gap-1">
                      <Button type="button" variant="ghost" size="icon-sm" aria-label="تحريك لأعلى" disabled={index === 0} onClick={() => move(index, -1)}>
                        <ArrowUp className="size-4" />
                      </Button>
                      <Button type="button" variant="ghost" size="icon-sm" aria-label="تحريك لأسفل" disabled={index === fields.length - 1} onClick={() => move(index, 1)}>
                        <ArrowDown className="size-4" />
                      </Button>
                      <Button type="button" variant="ghost" size="icon-sm" aria-label="حذف العمود" onClick={() => update(fields.filter((_, i) => i !== index))}>
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  )}
                </li>
              );
            })}
          </ol>
        )}

        {hasSensitive && (
          <p className="flex items-center gap-1.5 text-xs text-destructive">
            <AlertTriangle className="size-3.5" />
            يتطلب هذا الحقل صلاحية تصدير البيانات الحساسة.
          </p>
        )}

        {canConfigure && (
          <div className="flex flex-wrap items-center gap-2 border-t border-stroke-subtle pt-3">
            <Select value={adding} onValueChange={setAdding}>
              <SelectTrigger aria-label="حقل جديد" className="min-w-56">
                <SelectValue placeholder="اختر حقلًا لإضافته" />
              </SelectTrigger>
              <SelectContent>
                {available.map((f) => (
                  <SelectItem key={f.field_key} value={f.field_key} disabled={f.classification === "SENSITIVE" && !canSensitive}>
                    {f.default_label} — {exportClassificationLabels[f.classification]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={!adding}
              onClick={() => {
                const meta = byKey.get(adding);
                if (!meta) return;
                update([...fields, { field_key: meta.field_key, column_label: meta.default_label }]);
                setAdding("");
              }}
            >
              <Plus className="size-4" />
              إضافة الحقل
            </Button>
            <Button
              type="button"
              size="sm"
              className="ms-auto"
              disabled={mutation.isPending || fields.some((f) => !f.column_label.trim())}
              onClick={() =>
                mutation.mutate(fields, {
                  onSuccess: () => {
                    setError(null);
                    setSaved(true);
                    onSavedChange(false);
                  },
                  onError: (e) => setError(errorText(e, "تعذّر حفظ بيانات الكشف.")),
                })
              }
            >
              <Save className="size-4" />
              {mutation.isPending ? "جارٍ الحفظ..." : "حفظ بيانات الكشف"}
            </Button>
          </div>
        )}
        {saved && <p className="text-xs text-muted-foreground">تم حفظ بيانات الكشف.</p>}
      </div>
    </AppCard>
  );
}

// ---------------------------------------------------------------------------

function IssueSection({ assistance, disabledReason }: { assistance: Assistance; disabledReason: string | null }) {
  const preview = useListPreview(assistance.id);
  const issue = useIssueList(assistance.id);
  const [excluded, setExcluded] = useState<Set<string>>(new Set());
  const [recipient, setRecipient] = useState(assistance.provider_name);
  const [notes, setNotes] = useState("");
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [issued, setIssued] = useState<string | null>(null);
  const submitting = useRef(false);
  const data = preview.data?.data;
  const selectedIds = data ? data.rows.filter((r) => !excluded.has(r.beneficiary_id)).map((r) => r.beneficiary_id) : [];

  function runPreview() {
    setError(null);
    setIssued(null);
    setExcluded(new Set());
    preview.mutate(undefined, { onError: (e) => setError(errorText(e, "تعذّرت معاينة الكشف.")) });
  }

  function doIssue() {
    if (submitting.current) return;
    submitting.current = true;
    issue.mutate(
      { beneficiary_ids: selectedIds, recipient_organization: recipient.trim() || null, notes: notes.trim() || null },
      {
        onSettled: () => {
          submitting.current = false;
        },
        onSuccess: (response) => {
          setConfirming(false);
          setIssued(`تم إصدار الكشف ${response.data.list_number} للجهة (${response.data.row_count} سجل). الإصدار لا يعني التسليم.`);
          preview.reset();
        },
        onError: (e) => {
          setConfirming(false);
          setError(errorText(e, "تعذّر إصدار الكشف."));
        },
      }
    );
  }

  return (
    <AppCard padded={false} className="overflow-hidden" aria-labelledby="list-issue-title">
      <div className="flex flex-col gap-3 px-4 pt-4 pb-3 sm:flex-row sm:items-start sm:justify-between sm:px-5">
        <SectionHeader
          icon={Send}
          title={<span id="list-issue-title">٢. معاينة وإصدار الكشف</span>}
          description="المعاينة تعرض البيانات الحالية للمستفيدين المعتمدين ولا تحفظ شيئًا. الإصدار ينشئ نسخة ثابتة لا تتغير."
        />
        {assistance.status === "OPEN" && (
          <Button type="button" size="sm" variant="outline" className="shrink-0" disabled={!!disabledReason || preview.isPending} onClick={runPreview}>
            <Eye className="size-4" />
            {preview.isPending ? "جارٍ المعاينة..." : "معاينة الكشف"}
          </Button>
        )}
      </div>
      <div className="flex flex-col gap-3">
        <div className="flex flex-col gap-2 px-4 sm:px-5">
          {disabledReason && <p className="text-xs text-muted-foreground">{disabledReason}</p>}
          <SaveError message={error} />
          {issued && (
            <Alert>
              <AlertDescription>{issued}</AlertDescription>
            </Alert>
          )}
        </div>

        {data && (
          <>
            <div className="flex flex-wrap items-center gap-3 px-4 text-sm sm:px-5">
              <span className="font-medium">عدد السجلات: {data.row_count}</span>
              <span className="text-muted-foreground">المحدد للإصدار: {selectedIds.length}</span>
              {data.contains_sensitive && (
                <span className="flex items-center gap-1 text-destructive">
                  <AlertTriangle className="size-4" />
                  {SENSITIVE_WARNING}
                </span>
              )}
            </div>
            {data.rows.length === 0 ? (
              <p className="border-t border-stroke-subtle p-8 text-center text-sm text-muted-foreground">لا يوجد مستفيدون معتمدون لإصدارهم.</p>
            ) : (
              <div className="overflow-x-auto border-t border-stroke-subtle">
                <Table data-list-preview>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-0" />
                      {data.columns.map((c) => (
                        <TableHead key={c.field_key}>{c.column_label}</TableHead>
                      ))}
                      <TableHead>كشوف سابقة</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.rows.map((row) => (
                      <TableRow key={row.beneficiary_id} data-preview-row={row.family_code}>
                        <TableCell>
                          <input
                            type="checkbox"
                            aria-label={`إدراج ${row.family_code}`}
                            className="size-4 accent-primary"
                            checked={!excluded.has(row.beneficiary_id)}
                            onChange={() => {
                              const next = new Set(excluded);
                              if (next.has(row.beneficiary_id)) next.delete(row.beneficiary_id);
                              else next.add(row.beneficiary_id);
                              setExcluded(next);
                            }}
                          />
                        </TableCell>
                        {data.columns.map((c) => (
                          <TableCell key={c.field_key}>{cell(row.values[c.field_key])}</TableCell>
                        ))}
                        <TableCell className="text-xs text-muted-foreground" dir="ltr">
                          {row.listed_in.length ? row.listed_in.join(", ") : "—"}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
            {data.rows.length > 0 && (
              <div className="grid gap-3 border-t border-stroke-subtle px-4 py-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end sm:px-5">
                <div className="flex flex-col gap-1.5">
                  <FieldLabel htmlFor="list-recipient">الجهة المستلمة للكشف</FieldLabel>
                  <Input id="list-recipient" value={recipient} onChange={(e) => setRecipient(e.target.value)} />
                </div>
                <div className="flex flex-col gap-1.5">
                  <FieldLabel htmlFor="list-notes" optional>
                    ملاحظات
                  </FieldLabel>
                  <Textarea id="list-notes" rows={1} value={notes} onChange={(e) => setNotes(e.target.value)} />
                </div>
                <Button type="button" disabled={selectedIds.length === 0 || issue.isPending} onClick={() => setConfirming(true)}>
                  <Send className="size-4" />
                  إصدار الكشف
                </Button>
              </div>
            )}
          </>
        )}
      </div>

      <Dialog open={confirming} onOpenChange={(next) => !issue.isPending && setConfirming(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>إصدار الكشف</DialogTitle>
            <DialogDescription>
              سيُصدر كشف ثابت يضم {selectedIds.length} سجلًا إلى «{recipient || assistance.provider_name}». لا يمكن تعديل الكشف
              أو حذفه بعد الإصدار، والتصحيح يكون بإصدار كشف جديد. إصدار الكشف لا يعني تسليم المساعدة.
            </DialogDescription>
          </DialogHeader>
          {data?.contains_sensitive && (
            <p className="flex items-center gap-1.5 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
              <AlertTriangle className="size-4" />
              {SENSITIVE_WARNING}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setConfirming(false)} disabled={issue.isPending}>
              إلغاء
            </Button>
            <Button type="button" onClick={doIssue} disabled={issue.isPending}>
              {issue.isPending ? "جارٍ الإصدار..." : "تأكيد الإصدار"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppCard>
  );
}

/** "بيانات الكشف" tab (EXTERNAL only). */
export function AssistanceExportTab({ assistance, abilities }: { assistance: Assistance; abilities: AssistanceResponse["abilities"] }) {
  const { data, isLoading, isError } = useExportFields(assistance.id, true);
  const [dirty, setDirty] = useState(false);

  if (isLoading) return <Skeleton className="h-48 rounded-widget" />;
  if (isError || !data)
    return (
      <AppCard padded={false}>
        <EmptyState icon={FileSpreadsheet} title="تعذّر تحميل بيانات الكشف." />
      </AppCard>
    );

  const configured = data.data.configuration.length > 0;
  const disabledReason = !abilities.export
    ? "لا تملك صلاحية معاينة الكشوف أو إصدارها."
    : dirty
      ? "احفظ بيانات الكشف أولًا لمعاينتها."
      : !configured
        ? "حدد بيانات الكشف المطلوبة واحفظها أولًا."
        : data.data.contains_sensitive && !abilities.export_sensitive
          ? "يتضمن الكشف بيانات حساسة ويتطلب صلاحية تصدير البيانات الحساسة."
          : null;

  return (
    <div className="flex flex-col gap-4">
      {/* Initialized once from the saved configuration; after its own save
          the editor already holds the saved state (no remount, so the
          confirmation stays visible). */}
      <FieldEditor
        assistance={assistance}
        catalog={data.data.catalog}
        initial={data.data.configuration}
        canConfigure={data.abilities.configure}
        canSensitive={data.abilities.sensitive}
        onSavedChange={setDirty}
      />
      <IssueSection assistance={assistance} disabledReason={disabledReason} />
    </div>
  );
}

// ---------------------------------------------------------------------------

function ListRowsDialog({ list, onClose }: { list: BeneficiaryList | null; onClose: () => void }) {
  const { data, isLoading, error } = useBeneficiaryList(list?.id ?? null);
  const detail = data?.data;
  const columns: ListColumn[] = detail?.columns ?? [];

  return (
    <Dialog open={!!list} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle>
            الكشف <span dir="ltr">{list?.list_number}</span>
          </DialogTitle>
          <DialogDescription>
            النسخة الثابتة كما أُصدرت إلى «{list?.recipient_organization}» في {list && formatDateTime(list.issued_at)}. لا تتغير
            بتغير بيانات الأسر لاحقًا.
          </DialogDescription>
        </DialogHeader>
        {list?.contains_sensitive && (
          <p className="flex items-center gap-1.5 text-sm text-destructive">
            <AlertTriangle className="size-4" />
            {SENSITIVE_WARNING}
          </p>
        )}
        {isLoading ? (
          <Skeleton className="h-32" />
        ) : error ? (
          <SaveError message={errorText(error, "تعذّر عرض الكشف.")} />
        ) : (
          <div className="overflow-x-auto rounded-md border">
            <Table data-issued-list>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-0">#</TableHead>
                  {columns.map((c) => (
                    <TableHead key={c.field_key}>{c.column_label}</TableHead>
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {detail?.rows.map((row) => (
                  <TableRow key={row.row_number}>
                    <TableCell className="tabular-nums">{row.row_number}</TableCell>
                    {columns.map((c) => (
                      <TableCell key={c.field_key}>{cell(row.values[c.field_key])}</TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

/** "الكشوف الصادرة" tab (EXTERNAL only): immutable history; no edit, no delete. */
export function AssistanceIssuedListsTab({ assistance, abilities }: { assistance: Assistance; abilities: AssistanceResponse["abilities"] }) {
  const { data, isLoading } = useBeneficiaryLists(assistance.id, true);
  const [viewing, setViewing] = useState<BeneficiaryList | null>(null);
  const [error, setError] = useState<string | null>(null);
  const lists = data?.data ?? [];
  const canOpen = (l: BeneficiaryList) => abilities.export && (!l.contains_sensitive || abilities.export_sensitive);

  const actions = (l: BeneficiaryList) =>
    canOpen(l) ? (
      <div className="flex items-center gap-1">
        <Button variant="ghost" size="sm" className="h-8" onClick={() => setViewing(l)} aria-label={`عرض الكشف ${l.list_number}`}>
          <Eye className="size-4" />
          عرض
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="h-8"
          aria-label={`تنزيل الكشف ${l.list_number} بصيغة XLSX`}
          onClick={async () => {
            setError(null);
            const status = await downloadBeneficiaryList(l);
            if (status) setError(status === 403 ? "لا تملك صلاحية تنزيل هذا الكشف." : "تعذّر تنزيل الملف.");
          }}
        >
          <Download className="size-4" />
          تنزيل XLSX
        </Button>
      </div>
    ) : null;

  return (
    <AppCard padded={false} className="overflow-hidden" aria-labelledby="issued-lists-title">
      <div className="px-4 pt-4 pb-3 sm:px-5">
        <SectionHeader
          icon={FileSpreadsheet}
          title={<span id="issued-lists-title">الكشوف الصادرة</span>}
          description='كل كشف نسخة ثابتة لما أُرسل للجهة. الإصدار يعني "تم إصدار الكشف للجهة" فقط، وليس التسليم.'
        />
      </div>
      {error && (
        <div className="px-4 pb-3 sm:px-5">
          <SaveError message={error} />
        </div>
      )}
      {isLoading ? (
        <div className="border-t border-stroke-subtle p-4">
          <Skeleton className="h-24" />
        </div>
      ) : lists.length === 0 ? (
        <EmptyState icon={FileSpreadsheet} title="لم يُصدر أي كشف بعد" className="border-t border-stroke-subtle" />
      ) : (
        <>
          <Table className="hidden border-t border-stroke-subtle lg:table">
            <TableHeader className="bg-surface-1">
              <TableRow className="border-stroke-subtle hover:bg-transparent">
                <TableHead className="ps-5 text-xs text-muted-foreground">رقم الكشف</TableHead>
                <TableHead className="text-xs text-muted-foreground">الجهة المستلمة</TableHead>
                <TableHead className="text-xs text-muted-foreground">تاريخ الإصدار</TableHead>
                <TableHead className="text-xs text-muted-foreground">بواسطة</TableHead>
                <TableHead className="text-xs text-muted-foreground">عدد السجلات</TableHead>
                <TableHead className="text-xs text-muted-foreground">بيانات حساسة</TableHead>
                <TableHead className="w-0 pe-5">
                  <span className="sr-only">الإجراءات</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lists.map((l) => (
                <TableRow key={l.id} data-list-number={l.list_number} className="border-stroke-subtle">
                  <TableCell className="ps-5">
                    <bdi dir="ltr" className="font-semibold tabular-nums">{l.list_number}</bdi>
                  </TableCell>
                  <TableCell>{l.recipient_organization}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{formatDateTime(l.issued_at)}</TableCell>
                  <TableCell>{l.issued_by?.name ?? "—"}</TableCell>
                  <TableCell className="tabular-nums">{l.row_count}</TableCell>
                  <TableCell>
                    {l.contains_sensitive ? <StatusBadge tone="danger">نعم</StatusBadge> : <span className="text-muted-foreground">لا</span>}
                  </TableCell>
                  <TableCell className="pe-5">{actions(l)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <ul className="divide-y divide-stroke-subtle border-t border-stroke-subtle lg:hidden" aria-label="الكشوف الصادرة">
            {lists.map((l) => (
              <li key={l.id} className="flex flex-col gap-1.5 px-4 py-3" data-list-number={l.list_number}>
                <div className="flex items-center justify-between gap-2">
                  <bdi dir="ltr" className="font-semibold tabular-nums">{l.list_number}</bdi>
                  {l.contains_sensitive && <StatusBadge tone="danger">بيانات حساسة</StatusBadge>}
                </div>
                <span className="text-xs text-muted-foreground">
                  {l.recipient_organization} · {l.row_count} سجل · {formatDateTime(l.issued_at)}
                </span>
                {actions(l)}
              </li>
            ))}
          </ul>
        </>
      )}
      <ListRowsDialog list={viewing} onClose={() => setViewing(null)} />
    </AppCard>
  );
}

