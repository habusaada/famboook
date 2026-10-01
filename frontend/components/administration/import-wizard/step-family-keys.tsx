"use client";

import { useMemo, useState } from "react";
import { AlertTriangle, Info, KeyRound } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { useAuth } from "@/components/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import { useAutoCreateBranches, useBulkResolveFamilyKeys, useClearFamilyKey, useImportFamilyKeys, useResolveFamilyKey } from "@/lib/api/imports";
import { DECISION_LABELS, fmt, isApplyStarted } from "@/components/administration/import-wizard/labels";
import type { FamilyKeyDecision, FamilyKeyDiscovery, FamilyKeysPayload, ImportBatchDetail } from "@/lib/types/api/imports";

type Filter = "all" | "unresolved" | "resolved" | "existing" | "new" | "none";
type Sort = "rows" | "key" | "state";

const FILTERS: { value: Filter; label: string }[] = [
  { value: "all", label: "الكل" },
  { value: "unresolved", label: "لم يُحسم" },
  { value: "resolved", label: "تم الحسم" },
  { value: "existing", label: "فرع موجود" },
  { value: "new", label: "فرع جديد" },
  { value: "none", label: "بدون فرع" },
];

function matches(k: FamilyKeyDiscovery, f: Filter): boolean {
  const d = k.resolution?.decision;
  switch (f) {
    case "unresolved":
      return !d;
    case "resolved":
      return !!d;
    case "existing":
      return d === "MATCH_EXISTING_BRANCH" || d === "SAME_BRANCH_AS_KEY";
    case "new":
      return d === "CREATE_NEW_BRANCH";
    case "none":
      return d === "NO_BRANCH";
    default:
      return true;
  }
}

function errorText(error: unknown): string | null {
  if (!error) return null;
  if (error instanceof ApiError && error.status === 403) return "لا تملك صلاحية تنفيذ هذا الإجراء (إنشاء الفروع يتطلب صلاحية إدارة العشائر).";
  if (error instanceof ApiError && error.status === 422) {
    const first = Object.values(error.validationErrors ?? {})[0]?.[0];
    return first ?? error.message422 ?? "تعذّر حفظ القرار.";
  }
  return "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.";
}

function branchLabel(b: { name: string; code: string; group?: string | null }): string {
  return `${b.name} — ${b.code}${b.group ? ` (${b.group})` : " (بدون مجموعة)"}`;
}

/** Explicit decision editor for ONE source key. Nothing is saved until confirmed. */
function DecisionDialog({
  batchId,
  item,
  payload,
  onClose,
}: {
  batchId: string;
  item: FamilyKeyDiscovery;
  payload: FamilyKeysPayload;
  onClose: () => void;
}) {
  const resolve = useResolveFamilyKey(batchId);
  const clear = useClearFamilyKey(batchId);
  const { meta } = payload;
  const suggested = meta.branches.find((b) => b.code === item.existing_branch?.code);
  const [decision, setDecision] = useState<FamilyKeyDecision | "">(item.resolution?.decision ?? "");
  const [branchId, setBranchId] = useState(item.resolution?.branch?.id ?? suggested?.id ?? "");
  const [name, setName] = useState(item.key);
  const [code, setCode] = useState("");
  const [sameAs, setSameAs] = useState(item.resolution?.reference_source_key ?? "");
  const others = payload.data.filter((k) => k.key !== item.key && k.resolution?.branch);

  const submit = () => {
    if (!decision) return;
    const vars =
      decision === "MATCH_EXISTING_BRANCH"
        ? { branch_id: branchId }
        : decision === "CREATE_NEW_BRANCH"
          ? { branch: { name: name.trim(), code: code.trim() } }
          : decision === "SAME_BRANCH_AS_KEY"
            ? { same_as_key: sameAs }
            : {};
    resolve.mutate({ source_family_key: item.key, decision, ...vars }, { onSuccess: onClose });
  };
  const ready =
    decision === "NO_BRANCH" ||
    (decision === "MATCH_EXISTING_BRANCH" && branchId !== "") ||
    (decision === "CREATE_NEW_BRANCH" && name.trim() !== "" && code.trim() !== "") ||
    (decision === "SAME_BRANCH_AS_KEY" && sameAs !== "");
  const submitLabel =
    decision === "CREATE_NEW_BRANCH" ? "إنشاء الفرع وربطه" : decision === "NO_BRANCH" ? "تأكيد: بدون فرع" : "حفظ القرار";

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>قرار المفتاح «{item.key}»</DialogTitle>
          <DialogDescription>
            ينطبق القرار على {fmt(item.row_count)} صف بهذا المفتاح في هذه الدفعة. لا يتغيّر المفتاح المحفوظ من الملف.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="key-decision">القرار</Label>
            <Select value={decision || undefined} onValueChange={(v) => setDecision(v as FamilyKeyDecision)}>
              <SelectTrigger id="key-decision" className="w-full">
                <SelectValue placeholder="اختر القرار" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="MATCH_EXISTING_BRANCH" disabled={meta.branches.length === 0}>{DECISION_LABELS.MATCH_EXISTING_BRANCH}</SelectItem>
                <SelectItem value="CREATE_NEW_BRANCH" disabled={!meta.can_create_branch}>{DECISION_LABELS.CREATE_NEW_BRANCH}</SelectItem>
                <SelectItem value="SAME_BRANCH_AS_KEY" disabled={others.length === 0}>{DECISION_LABELS.SAME_BRANCH_AS_KEY}</SelectItem>
                <SelectItem value="NO_BRANCH">{DECISION_LABELS.NO_BRANCH}</SelectItem>
              </SelectContent>
            </Select>
            {!meta.can_create_branch && <p className="text-xs text-muted-foreground">إنشاء الفروع يتطلب صلاحية إدارة العشائر.</p>}
          </div>

          {decision === "MATCH_EXISTING_BRANCH" && (
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="key-branch">الفرع (من {meta.clan.name} فقط)</Label>
              <Select value={branchId || undefined} onValueChange={setBranchId}>
                <SelectTrigger id="key-branch" className="w-full">
                  <SelectValue placeholder="اختر الفرع" />
                </SelectTrigger>
                <SelectContent>
                  {meta.branches.map((b) => (
                    <SelectItem key={b.id} value={b.id}>
                      {branchLabel(b)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {suggested && <p className="text-xs text-muted-foreground">اقتراح: فرع بالاسم نفسه تمامًا — يلزم تأكيدك.</p>}
            </div>
          )}

          {decision === "CREATE_NEW_BRANCH" && (
            <div className="flex flex-col gap-3">
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="new-branch-name">اسم الفرع</Label>
                <Input id="new-branch-name" value={name} onChange={(e) => setName(e.target.value)} />
              </div>
              <div className="flex flex-col gap-1.5">
                <Label htmlFor="new-branch-code">الكود</Label>
                <Input id="new-branch-code" dir="ltr" className="text-end uppercase" placeholder="BRANCH_CODE" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} />
                <p className="text-xs text-muted-foreground">أحرف إنجليزية كبيرة وأرقام و _ فقط، ولا يتغيّر بعد الإنشاء.</p>
              </div>
              <Alert>
                <AlertDescription>سيتم إنشاء الفرع داخل {meta.clan.name} دون مجموعة فروع.</AlertDescription>
              </Alert>
            </div>
          )}

          {decision === "SAME_BRANCH_AS_KEY" && (
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="same-as">المفتاح الآخر</Label>
              <Select value={sameAs || undefined} onValueChange={setSameAs}>
                <SelectTrigger id="same-as" className="w-full">
                  <SelectValue placeholder="اختر مفتاحًا محسومًا بفرع" />
                </SelectTrigger>
                <SelectContent>
                  {others.map((k) => (
                    <SelectItem key={k.key} value={k.key}>
                      {k.key} ← {k.resolution!.branch!.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">يُربط هذا المفتاح بالفرع النهائي للمفتاح الآخر؛ لا يُعاد كتابة أي من المفتاحين.</p>
            </div>
          )}

          {decision === "NO_BRANCH" && (
            <p className="text-sm text-muted-foreground">
              قرار صريح: ستُنشأ الأسر المرتبطة بهذا المفتاح لاحقًا دون فرع. يختلف هذا عن «لم يُحسم».
            </p>
          )}

          {(resolve.error || clear.error) && <p className="text-sm text-danger">{errorText(resolve.error ?? clear.error)}</p>}
        </div>

        <DialogFooter className="gap-2 sm:justify-between">
          {item.resolution ? (
            <Button variant="ghost" disabled={clear.isPending} onClick={() => clear.mutate({ key: item.key }, { onSuccess: onClose })}>
              إلغاء الحسم
            </Button>
          ) : (
            <span />
          )}
          <div className="flex gap-2">
            <Button variant="outline" onClick={onClose}>إلغاء</Button>
            <Button disabled={!ready || resolve.isPending} onClick={submit}>
              {resolve.isPending ? "جارٍ الحفظ…" : submitLabel}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Preview + explicit confirmation for selected keys (codes reviewed per key). */
function BulkCreateDialog({
  batchId,
  items,
  clan,
  onClose,
}: {
  batchId: string;
  items: FamilyKeyDiscovery[];
  clan: { code: string; name: string };
  onClose: () => void;
}) {
  const bulk = useBulkResolveFamilyKeys(batchId);
  const [rows, setRows] = useState(items.map((k) => ({ key: k.key, rows: k.row_count, name: k.key, code: "" })));
  const errors = bulk.error instanceof ApiError ? bulk.error.validationErrors ?? {} : {};

  // A reviewable helper: fills EMPTY codes with a neutral sequence (never derived from Arabic text).
  const numberBlank = () => {
    let n = 1;
    setRows((rs) => rs.map((r) => (r.code ? r : { ...r, code: `${clan.code}_B${String(n++).padStart(3, "0")}` })));
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>إنشاء فروع للمفاتيح المحددة ({fmt(items.length)})</DialogTitle>
          <DialogDescription>راجع الاسم والكود لكل مفتاح. يُنشأ فرع واحد بلا مجموعة لكل مفتاح داخل {clan.name} — كلها أو لا شيء.</DialogDescription>
        </DialogHeader>
        <Button variant="outline" size="sm" className="w-fit" onClick={numberBlank}>ترقيم الأكواد الفارغة</Button>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>المفتاح</TableHead>
              <TableHead className="text-end">الصفوف</TableHead>
              <TableHead>اسم الفرع</TableHead>
              <TableHead>الكود</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((r, i) => (
              <TableRow key={r.key}>
                <TableCell className="font-medium">{r.key}</TableCell>
                <TableCell className="text-end tabular-nums">{fmt(r.rows)}</TableCell>
                <TableCell>
                  <Input value={r.name} onChange={(e) => setRows((rs) => rs.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
                  {errors[`items.${i}.name`] && <p className="text-xs text-danger">{errors[`items.${i}.name`][0]}</p>}
                </TableCell>
                <TableCell>
                  <Input dir="ltr" className="text-end uppercase" value={r.code} onChange={(e) => setRows((rs) => rs.map((x, j) => (j === i ? { ...x, code: e.target.value.toUpperCase() } : x)))} />
                  {errors[`items.${i}.code`] && <p className="text-xs text-danger">{errors[`items.${i}.code`][0]}</p>}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {bulk.error && !(bulk.error instanceof ApiError && bulk.error.status === 422) && <p className="text-sm text-danger">{errorText(bulk.error)}</p>}
        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose}>إلغاء</Button>
          <Button
            disabled={bulk.isPending || rows.some((r) => !r.name.trim() || !r.code.trim())}
            onClick={() =>
              bulk.mutate(
                { decision: "CREATE_NEW_BRANCH", items: rows.map((r) => ({ source_family_key: r.key, name: r.name.trim(), code: r.code.trim() })) },
                { onSuccess: onClose }
              )
            }
          >
            {bulk.isPending ? "جارٍ الإنشاء…" : `تأكيد إنشاء ${fmt(rows.length)} فرعًا وربطها`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function BulkNoBranchDialog({ batchId, items, onClose }: { batchId: string; items: FamilyKeyDiscovery[]; onClose: () => void }) {
  const bulk = useBulkResolveFamilyKeys(batchId);
  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>بدون فرع للمفاتيح المحددة ({fmt(items.length)})</DialogTitle>
          <DialogDescription>قرار صريح بعدم تعيين فرع: {items.map((k) => k.key).join("، ")}</DialogDescription>
        </DialogHeader>
        {bulk.error && <p className="text-sm text-danger">{errorText(bulk.error)}</p>}
        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose}>إلغاء</Button>
          <Button
            disabled={bulk.isPending}
            onClick={() => bulk.mutate({ decision: "NO_BRANCH", items: items.map((k) => ({ source_family_key: k.key })) }, { onSuccess: onClose })}
          >
            تأكيد
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function decisionText(k: FamilyKeyDiscovery): React.ReactNode {
  const r = k.resolution;
  if (!r) return <span className="text-muted-foreground">—</span>;
  if (r.decision === "NO_BRANCH") return DECISION_LABELS.NO_BRANCH;
  return (
    <span className="flex flex-col">
      <span>{DECISION_LABELS[r.decision]}</span>
      <span className="text-xs text-muted-foreground">
        {r.branch?.name} — {r.branch?.code}
        {r.reference_source_key ? ` (مثل «${r.reference_source_key}»)` : ""}
      </span>
    </span>
  );
}

/** Step 4 — "مراجعة مفاتيح الأسر": one explicit decision per distinct source key. */
/**
 * INITIAL import: the workbook's family keys become the Clan's Branches in one
 * explicit decision. Codes are generated by the server; nothing is selected
 * or typed by hand. Manual decisions stay available for exceptions.
 */
function AutoBranchesDialog({
  batchId,
  unresolved,
  clanName,
  onDone,
  onClose,
}: {
  batchId: string;
  unresolved: number;
  clanName: string;
  onDone: (counts: { created: number; matched: number }) => void;
  onClose: () => void;
}) {
  const auto = useAutoCreateBranches(batchId);
  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg" data-auto-branches-confirm>
        <DialogHeader>
          <DialogTitle>إنشاء الفروع من مفاتيح Excel</DialogTitle>
          <DialogDescription>سيُحسم {fmt(unresolved)} مفتاحًا غير محسوم دفعة واحدة في {clanName}:</DialogDescription>
        </DialogHeader>
        <ul className="flex list-inside list-disc flex-col gap-1.5 text-sm">
          <li>المفتاح المطابق تمامًا لاسم فرع موجود في العشيرة يُربط بذلك الفرع، ولا يُنشأ فرع مكرر.</li>
          <li>كل مفتاح آخر يصبح فرعًا دائمًا في العشيرة باسم المفتاح نفسه، برمز دائم (BR_…) يولّده الخادم.</li>
          <li>تبقى الفروع بلا مجموعة فروع؛ تُنظَّم المجموعات لاحقًا.</li>
          <li>القرارات المتخذة سابقًا لا تتغير، والصفوف بلا مفتاح لا تتأثر.</li>
        </ul>
        {auto.error && <p className="text-sm text-danger">{errorText(auto.error)}</p>}
        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose}>إلغاء</Button>
          <Button
            disabled={auto.isPending}
            onClick={() => auto.mutate(undefined, { onSuccess: (res) => onDone(res.meta.auto_branches ?? { created: 0, matched: 0 }) })}
          >
            {auto.isPending ? "جارٍ الإنشاء…" : "تأكيد"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function StepFamilyKeys({ batch }: { batch: ImportBatchDetail }) {
  const { can } = useAuth();
  const keys = useImportFamilyKeys(batch.id, batch.staged);
  const [search, setSearch] = useState("");
  const [filter, setFilter] = useState<Filter>("all");
  const [sort, setSort] = useState<Sort>("rows");
  const [editing, setEditing] = useState<string | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [bulk, setBulk] = useState<"create" | "none" | null>(null);
  const [autoOpen, setAutoOpen] = useState(false);
  const [autoResult, setAutoResult] = useState<{ created: number; matched: number } | null>(null);
  const payload = keys.data;
  const progress = payload?.meta.resolution;

  const visible = useMemo(() => {
    const list = (payload?.data ?? []).filter((k) => matches(k, filter) && k.key.includes(search.trim()));
    return [...list].sort((a, b) =>
      sort === "key" ? a.key.localeCompare(b.key, "ar") : sort === "state" ? Number(!!a.resolution) - Number(!!b.resolution) || b.row_count - a.row_count : b.row_count - a.row_count
    );
  }, [payload, filter, search, sort]);
  const selectedItems = (payload?.data ?? []).filter((k) => selected.has(k.key));
  // Once Apply has started, decisions are history: no edit controls at all.
  const locked = isApplyStarted(batch.status);
  const canResolve = can("import.review") && !locked;
  const toggle = (key: string) =>
    setSelected((s) => {
      const next = new Set(s);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });

  return (
    <Panel className="flex flex-col gap-5">
      <SectionHeader
        icon={KeyRound}
        title="مراجعة مفاتيح الأسر"
        description="ماذا يعني كل مفتاح عائلة من الملف داخل هذه العشيرة؟ المفتاح بيانات مصدر فقط ولا يصبح فرعًا تلقائيًا؛ يُتخذ قرار صريح مرة واحدة لكل مفتاح."
      />

      <div className="grid grid-cols-3 gap-3 text-sm">
        <div className="rounded-md border border-stroke-subtle p-3"><p className="text-muted-foreground">مفاتيح مختلفة</p><p className="text-xl font-bold tabular-nums">{fmt(batch.summary.distinct_family_keys)}</p></div>
        <div className="rounded-md border border-stroke-subtle p-3"><p className="text-muted-foreground">صفوف بلا مفتاح</p><p className="text-xl font-bold tabular-nums">{fmt(batch.summary.missing_family_key)}</p></div>
        <div className="rounded-md border border-stroke-subtle p-3"><p className="text-muted-foreground">مفاتيح بمعادلة</p><p className="text-xl font-bold tabular-nums">{fmt(batch.summary.formula_family_key)}</p></div>
      </div>

      {progress && (
        <div className="flex flex-col gap-2 rounded-md border border-stroke-subtle p-3" data-resolution-progress>
          <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
            <span>تم الحسم <strong className="tabular-nums">{fmt(progress.resolved_keys)} / {fmt(progress.distinct_keys)}</strong></span>
            <span>متبقي <strong className={`tabular-nums ${progress.unresolved_keys > 0 ? "text-warning" : "text-success"}`}>{fmt(progress.unresolved_keys)}</strong></span>
          </div>
          <div className="h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuemin={0} aria-valuemax={progress.distinct_keys} aria-valuenow={progress.resolved_keys}>
            <div className="h-full bg-success" style={{ width: `${progress.distinct_keys ? (progress.resolved_keys / progress.distinct_keys) * 100 : 0}%` }} />
          </div>
          {progress.unresolved_keys > 0 && <p className="text-xs text-muted-foreground">لا يمكن الانتقال إلى مراجعة البيانات قبل حسم كل المفاتيح. «بدون فرع» قرار صريح يُحتسب محسومًا.</p>}
        </div>
      )}

      {canResolve && batch.import_mode === "INITIAL" && payload?.meta.can_create_branch && (progress?.unresolved_keys ?? 0) > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-md border border-stroke-subtle p-3" data-auto-branches>
          <Button onClick={() => setAutoOpen(true)}>إنشاء الفروع من مفاتيح Excel</Button>
          <span className="text-xs text-muted-foreground">يحسم كل المفاتيح غير المحسومة دفعة واحدة؛ الأدوات اليدوية أدناه تبقى للحالات الاستثنائية.</span>
        </div>
      )}
      {autoResult && (
        <Alert data-auto-branches-result>
          <Info className="size-4" />
          <AlertTitle>تم حسم المفاتيح من ملف Excel</AlertTitle>
          <AlertDescription>
            أُنشئ {fmt(autoResult.created)} فرعًا جديدًا، وأُعيد استخدام {fmt(autoResult.matched)} فرعًا موجودًا.
          </AlertDescription>
        </Alert>
      )}

      {locked && (
        <Alert data-keys-read-only>
          <Info className="size-4" />
          <AlertTitle>للاطلاع فقط</AlertTitle>
          <AlertDescription>بدأ تطبيق هذه الدفعة؛ قرارات مفاتيح الأسر محفوظة كما اعتُمدت ولا يمكن تعديلها.</AlertDescription>
        </Alert>
      )}

      {batch.summary.formula_family_key > 0 && (
        <Alert>
          <AlertTriangle className="size-4" />
          <AlertTitle>مفاتيح ناتجة عن معادلة Excel</AlertTitle>
          <AlertDescription>لا تُعد انتماءً معتمدًا لفرع؛ راجعها بعناية قبل الحسم.</AlertDescription>
        </Alert>
      )}

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Input placeholder="بحث في مفاتيح العائلة" value={search} onChange={(e) => setSearch(e.target.value)} className="sm:w-64" />
        <div className="flex flex-wrap gap-1.5">
          {FILTERS.map((f) => (
            <Button key={f.value} size="sm" variant={filter === f.value ? "default" : "outline"} onClick={() => setFilter(f.value)}>
              {f.label}
            </Button>
          ))}
        </div>
        <Select value={sort} onValueChange={(v) => setSort(v as Sort)}>
          <SelectTrigger className="sm:ms-auto sm:w-48" aria-label="الترتيب">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="rows">الأكثر صفوفًا أولًا</SelectItem>
            <SelectItem value="key">المفتاح أبجديًا</SelectItem>
            <SelectItem value="state">غير المحسوم أولًا</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {selected.size > 0 && canResolve && (
        <div className="flex flex-wrap items-center gap-2 rounded-md bg-surface-2 p-2 text-sm">
          <span>{fmt(selected.size)} مفتاحًا محددًا</span>
          {payload?.meta.can_create_branch && <Button size="sm" onClick={() => setBulk("create")}>إنشاء فروع للمفاتيح المحددة</Button>}
          <Button size="sm" variant="outline" onClick={() => setBulk("none")}>بدون فرع للمفاتيح المحددة</Button>
          <Button size="sm" variant="ghost" onClick={() => setSelected(new Set())}>إلغاء التحديد</Button>
        </div>
      )}

      {keys.isLoading ? (
        <Skeleton className="h-40 w-full" />
      ) : !payload || payload.data.length === 0 ? (
        <p className="text-sm text-muted-foreground">لا توجد مفاتيح.</p>
      ) : (
        <div className="overflow-x-auto rounded-md border border-stroke-subtle">
          <Table>
            <TableHeader>
              <TableRow>
                {canResolve && <TableHead className="w-8 ps-3"><span className="sr-only">تحديد</span></TableHead>}
                <TableHead>مفتاح العائلة</TableHead>
                <TableHead className="text-end">عدد الصفوف</TableHead>
                <TableHead className="text-end">منها بمعادلة</TableHead>
                <TableHead>أمثلة على الصفوف</TableHead>
                <TableHead>الفرع المطابق</TableHead>
                <TableHead>القرار</TableHead>
                <TableHead className="pe-4">الحالة</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {visible.map((k) => (
                <TableRow key={k.key} data-resolved={!!k.resolution}>
                  {canResolve && (
                    <TableCell className="ps-3">
                      <input type="checkbox" className="size-4 accent-brand-600" checked={selected.has(k.key)} onChange={() => toggle(k.key)} aria-label={`تحديد ${k.key}`} />
                    </TableCell>
                  )}
                  <TableCell className="font-medium">{k.key}</TableCell>
                  <TableCell className="text-end tabular-nums">{fmt(k.row_count)}</TableCell>
                  <TableCell className="text-end tabular-nums text-muted-foreground">{fmt(k.formula_rows)}</TableCell>
                  <TableCell className="tabular-nums text-muted-foreground">{k.example_rows.map(fmt).join("، ")}</TableCell>
                  <TableCell className="text-xs">{k.existing_branch ? `اقتراح: ${k.existing_branch.name}` : <span className="text-muted-foreground">لا</span>}</TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      {decisionText(k)}
                      {canResolve && (
                        <Button size="sm" variant={k.resolution ? "ghost" : "outline"} onClick={() => setEditing(k.key)}>
                          {k.resolution ? "تعديل" : "اختر القرار"}
                        </Button>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="pe-4">
                    {k.resolution ? <Badge className="bg-success-soft text-success">تم الحسم</Badge> : <Badge className="bg-warning-soft text-warning">لم يُحسم</Badge>}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {canResolve && autoOpen && payload && (
        <AutoBranchesDialog
          batchId={batch.id}
          unresolved={progress?.unresolved_keys ?? 0}
          clanName={payload.meta.clan.name}
          onDone={(counts) => {
            setAutoResult(counts);
            setAutoOpen(false);
            setSelected(new Set());
          }}
          onClose={() => setAutoOpen(false)}
        />
      )}
      {canResolve && editing && payload && (
        <DecisionDialog batchId={batch.id} item={payload.data.find((k) => k.key === editing)!} payload={payload} onClose={() => setEditing(null)} />
      )}
      {canResolve && bulk === "create" && payload && (
        <BulkCreateDialog
          batchId={batch.id}
          items={selectedItems}
          clan={payload.meta.clan}
          onClose={() => {
            setBulk(null);
            setSelected(new Set());
          }}
        />
      )}
      {canResolve && bulk === "none" && payload && (
        <BulkNoBranchDialog
          batchId={batch.id}
          items={selectedItems}
          onClose={() => {
            setBulk(null);
            setSelected(new Set());
          }}
        />
      )}
    </Panel>
  );
}
