"use client";

import { useRef, useState } from "react";
import { AlertTriangle, ArrowDown, ArrowUp, Download, Eye, FileSpreadsheet, Plus, Save, Send, Trash2 } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
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
    <Badge variant={classification === "SENSITIVE" ? "destructive" : "outline"} className="font-normal">
      {exportClassificationLabels[classification]}
    </Badge>
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
    <Card size="sm">
      <CardHeader>
        <CardTitle>بيانات الكشف المطلوبة</CardTitle>
        <CardDescription>
          الأعمدة التي طلبتها الجهة في كشف المستفيدين، بالترتيب والتسمية المطلوبين. هذه ليست معايير استهداف:
          تحدد فقط البيانات التي تُرسل عن المستفيدين المعتمدين.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <SaveError message={error} />
        {fields.length === 0 ? (
          <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">لم تُحدد أي أعمدة بعد.</p>
        ) : (
          <ol className="flex flex-col gap-2">
            {fields.map((field, index) => {
              const meta = byKey.get(field.field_key);
              return (
                <li key={field.field_key} data-export-field={field.field_key} className="flex flex-wrap items-center gap-2 rounded-md border p-2">
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
          <div className="flex flex-wrap items-center gap-2 border-t pt-3">
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
      </CardContent>
    </Card>
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
    <Card size="sm">
      <CardHeader>
        <CardTitle>معاينة وإصدار الكشف</CardTitle>
        <CardDescription>المعاينة تعرض البيانات الحالية للمستفيدين المعتمدين ولا تحفظ شيئًا. الإصدار ينشئ نسخة ثابتة لا تتغير.</CardDescription>
        {assistance.status === "OPEN" && (
          <CardAction>
            <Button type="button" size="sm" variant="outline" disabled={!!disabledReason || preview.isPending} onClick={runPreview}>
              <Eye className="size-4" />
              {preview.isPending ? "جارٍ المعاينة..." : "معاينة الكشف"}
            </Button>
          </CardAction>
        )}
      </CardHeader>
      <CardContent className="flex flex-col gap-3 p-0">
        <div className="flex flex-col gap-2 px-4">
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
            <div className="flex flex-wrap items-center gap-3 px-4 text-sm">
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
              <p className="border-t p-8 text-center text-sm text-muted-foreground">لا يوجد مستفيدون معتمدون لإصدارهم.</p>
            ) : (
              <div className="overflow-x-auto border-t">
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
              <div className="grid gap-3 border-t px-4 py-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
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
      </CardContent>

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
    </Card>
  );
}

/** "بيانات الكشف" tab (EXTERNAL only). */
export function AssistanceExportTab({ assistance, abilities }: { assistance: Assistance; abilities: AssistanceResponse["abilities"] }) {
  const { data, isLoading, isError } = useExportFields(assistance.id, true);
  const [dirty, setDirty] = useState(false);

  if (isLoading) return <Skeleton className="h-48" />;
  if (isError || !data) return <p className="text-sm text-muted-foreground">تعذّر تحميل بيانات الكشف.</p>;

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

  return (
    <Card size="sm">
      <CardHeader>
        <CardTitle>الكشوف الصادرة</CardTitle>
        <CardDescription>كل كشف نسخة ثابتة لما أُرسل للجهة. الإصدار يعني &quot;تم إصدار الكشف للجهة&quot; فقط، وليس التسليم.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2 p-0">
        <div className="px-4">
          <SaveError message={error} />
        </div>
        {isLoading ? (
          <Skeleton className="mx-4 mb-4 h-24" />
        ) : lists.length === 0 ? (
          <div className="flex flex-col items-center gap-2 border-t p-12 text-center">
            <FileSpreadsheet className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">لم يُصدر أي كشف بعد.</p>
          </div>
        ) : (
          <div className="overflow-x-auto border-t">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>رقم الكشف</TableHead>
                  <TableHead>الجهة المستلمة</TableHead>
                  <TableHead>تاريخ الإصدار</TableHead>
                  <TableHead>بواسطة</TableHead>
                  <TableHead>عدد السجلات</TableHead>
                  <TableHead>بيانات حساسة</TableHead>
                  <TableHead className="w-0" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {lists.map((l) => (
                  <TableRow key={l.id} data-list-number={l.list_number}>
                    <TableCell className="font-medium" dir="ltr">{l.list_number}</TableCell>
                    <TableCell>{l.recipient_organization}</TableCell>
                    <TableCell className="text-xs text-muted-foreground">{formatDateTime(l.issued_at)}</TableCell>
                    <TableCell>{l.issued_by?.name ?? "—"}</TableCell>
                    <TableCell className="tabular-nums">{l.row_count}</TableCell>
                    <TableCell>{l.contains_sensitive ? <Badge variant="destructive">نعم</Badge> : <span className="text-muted-foreground">لا</span>}</TableCell>
                    <TableCell>
                      {canOpen(l) && (
                        <div className="flex items-center gap-1">
                          <Button variant="ghost" size="sm" onClick={() => setViewing(l)}>
                            <Eye className="size-4" />
                            عرض
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
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
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>
      <ListRowsDialog list={viewing} onClose={() => setViewing(null)} />
    </Card>
  );
}
