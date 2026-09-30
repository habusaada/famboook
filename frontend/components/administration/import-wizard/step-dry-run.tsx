"use client";

import { Fragment, useState } from "react";
import { AlertTriangle, Eye, Info, X } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Panel, SectionHeader } from "@/components/shared/page-layout";
import { useAuth } from "@/components/auth/auth-context";
import { useDryRun, useDryRunRows } from "@/lib/api/imports";
import {
  DRY_RUN_FILTER_LABELS,
  EFFECT_GROUP_LABELS,
  INTENT_LABELS,
  OMIT_REASON_CODES,
  PRECONDITION_LABELS,
  fmt,
  planReasonLabel,
} from "@/components/administration/import-wizard/labels";
import { ProcessList } from "@/components/administration/import-wizard/wizard-parts";
import type { ApplyIntent, DryRunCounts, DryRunEffect, DryRunFilter, DryRunRow, ImportBatchDetail } from "@/lib/types/api/imports";

/**
 * Step 6 — "المعاينة قبل الاستيراد" (docs/03 §96b): shows what a future Apply
 * WOULD do, computed by the backend planner. Read only: nothing is created
 * or changed, and there is no execution action in this phase.
 */

const INTENTS: ApplyIntent[] = ["CREATE", "REUSE", "OMIT", "BLOCK"];
const INTENT_TONE: Record<ApplyIntent, string> = {
  CREATE: "text-brand-700",
  REUSE: "text-success",
  OMIT: "text-muted-foreground",
  BLOCK: "text-danger",
};
const EFFECT_NAMES: Record<string, string> = { HEAD_PERSON: "رب الأسرة" };

function IntentBadge({ e }: { e: DryRunEffect }) {
  if (!e.intent) return <span className="text-muted-foreground">—</span>;
  return (
    <Badge variant={e.intent === "BLOCK" ? "destructive" : "secondary"} className={INTENT_TONE[e.intent]}>
      {INTENT_LABELS[e.intent]}
    </Badge>
  );
}

/** A reuse of a Person planned in another row, or an existing registry Person. */
function refText(e: DryRunEffect): string | null {
  if (e.person_code) return `شخص موجود ${e.person_code}`;
  if (e.owner) {
    const what = EFFECT_NAMES[e.owner.effect] ?? `الزوج/الزوجة ${fmt(Number(e.owner.effect.split("_")[1]))}`;
    return `يُنشأ في الصف ${fmt(e.owner.row_number)} (${what})`;
  }
  return null;
}

function SummaryCards({ counts }: { counts: DryRunCounts }) {
  const cards = [
    { label: "صفوف المصدر", value: counts.source_rows, tone: "text-foreground" },
    { label: "قابلة للتنفيذ", value: counts.executable_rows, tone: "text-success" },
    { label: "متعذرة", value: counts.blocked_rows, tone: counts.blocked_rows > 0 ? "text-danger" : "text-foreground" },
    { label: "صفوف بها ملاحظات", value: counts.warning_rows, tone: counts.warning_rows > 0 ? "text-warning" : "text-foreground" },
    { label: "أشخاص سيُنشؤون", value: counts.persons.create, tone: "text-brand-700" },
    { label: "أشخاص موجودون يُستخدمون", value: counts.persons.reuse_existing, tone: "text-foreground" },
  ];
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" data-dry-run-counts>
      {cards.map((c) => (
        <div key={c.label} className="flex flex-col gap-1 rounded-md border border-stroke-subtle bg-surface-1 p-3">
          <span className="text-[13px] text-muted-foreground">{c.label}</span>
          <span className={`text-2xl font-bold tabular-nums ${c.tone}`}>{fmt(c.value)}</span>
        </div>
      ))}
    </div>
  );
}

function EffectTable({ counts }: { counts: DryRunCounts }) {
  return (
    <div className="overflow-x-auto rounded-md border border-stroke-subtle" data-dry-run-effects>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="ps-4">السجل</TableHead>
            {INTENTS.map((i) => <TableHead key={i} className="text-end">{INTENT_LABELS[i]}</TableHead>)}
          </TableRow>
        </TableHeader>
        <TableBody>
          {Object.entries(counts.effects).map(([group, byIntent]) => (
            <TableRow key={group}>
              <TableCell className="ps-4">{EFFECT_GROUP_LABELS[group] ?? group}</TableCell>
              {INTENTS.map((i) => (
                <TableCell key={i} className={`text-end tabular-nums ${byIntent[i] > 0 ? INTENT_TONE[i] : "text-muted-foreground"}`}>{fmt(byIntent[i])}</TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function ReasonTable({ title, entries, unit, onPick }: { title: string; entries: [string, number][]; unit: string; onPick: (code: string) => void }) {
  if (entries.length === 0) return null;
  return (
    <div className="flex flex-col gap-1.5">
      <p className="text-sm font-semibold">{title}</p>
      <ul className="flex flex-col divide-y divide-stroke-subtle rounded-md border border-stroke-subtle text-sm">
        {entries.map(([code, n]) => (
          <li key={code} className="flex items-center justify-between gap-3 px-3 py-2" data-reason={code}>
            <Button variant="link" className="h-auto p-0 text-start whitespace-normal" onClick={() => onPick(code)}>{planReasonLabel(code)}</Button>
            <span className="shrink-0 tabular-nums text-muted-foreground">{fmt(n)} {unit}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function RowDetail({ row }: { row: DryRunRow }) {
  return (
    <div className="flex flex-col gap-3 bg-surface-2/60 p-3 text-sm">
      {row.block_reasons.length > 0 && <p className="text-danger">أسباب التعذر: {row.block_reasons.map(planReasonLabel).join("، ")}</p>}
      {row.warnings.length > 0 && <p className="text-warning">ملاحظات: {row.warnings.map(planReasonLabel).join("، ")}</p>}
      <dl className="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
        {[
          ["رب الأسرة", row.head_person],
          ["الأسرة", row.family],
          ["عضوية رب الأسرة", row.head_membership],
          ["الإقرار الأسري", row.declaration],
          ["الإقامة الأصلية", row.residence],
        ].map(([label, e]) => {
          const effect = e as DryRunEffect;
          return (
            <div key={label as string} className="flex flex-wrap items-center justify-between gap-2">
              <dt className="text-muted-foreground">{label as string}</dt>
              <dd className="flex flex-wrap items-center gap-2">
                <IntentBadge e={effect} />
                {refText(effect) && <span className="text-xs text-muted-foreground">{refText(effect)}</span>}
                {label === "الأسرة" && <span className="text-xs text-muted-foreground">{effect.branch ?? "بدون فرع"}</span>}
                {effect.life_status === "DECEASED" && <span className="text-xs text-warning">متوفى</span>}
                {effect.reason && effect.intent !== "BLOCK" && <span className="text-xs text-muted-foreground">{planReasonLabel(effect.reason)}</span>}
              </dd>
            </div>
          );
        })}
      </dl>
      {row.spouses.length > 0 && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>خانة الزوج/الزوجة</TableHead>
              <TableHead>الهوية</TableHead>
              <TableHead>الشخص</TableHead>
              <TableHead>العضوية في هذه الأسرة</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {row.spouses.map((s) => (
              <TableRow key={s.slot}>
                <TableCell className="tabular-nums">{fmt(s.slot)}</TableCell>
                <TableCell dir="ltr" className="text-end font-mono text-xs">{s.national_id_masked ?? "—"}</TableCell>
                <TableCell>
                  <div className="flex flex-wrap items-center gap-2">
                    <IntentBadge e={s.person} />
                    {s.person.gender && <span className="text-xs text-muted-foreground">{s.person.gender === "FEMALE" ? "أنثى" : "ذكر"}</span>}
                    {refText(s.person) && <span className="text-xs text-muted-foreground">{refText(s.person)}</span>}
                  </div>
                </TableCell>
                <TableCell>
                  <div className="flex flex-wrap items-center gap-2">
                    <IntentBadge e={s.membership} />
                    {s.membership.reason && <span className="text-xs text-muted-foreground">{planReasonLabel(s.membership.reason)}</span>}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
      <p className="text-xs text-muted-foreground">رموز الأسر والأشخاص الجديدة لا تُحجز في المعاينة؛ تُولَّد عند التنفيذ فقط.</p>
    </div>
  );
}

function spouseSummary(row: DryRunRow): string {
  if (row.spouses.length === 0) return "—";
  const created = row.spouses.filter((s) => s.membership.intent === "CREATE").length;
  const omitted = row.spouses.filter((s) => s.membership.intent === "OMIT").length;
  return `${fmt(row.spouses.length)} — عضوية ${fmt(created)}${omitted ? `، بلا عضوية ${fmt(omitted)}` : ""}`;
}

function RowPlans({ batchId, reason, onReason }: { batchId: string; reason: string; onReason: (r: string) => void }) {
  const [filter, setFilter] = useState<DryRunFilter>("all");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<number | null>(null);
  const rows = useDryRunRows(batchId, { filter, reason, search: search.trim(), page }, true);
  const meta = rows.data?.meta;

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex flex-wrap gap-1.5" role="group" aria-label="تصفية خطط الصفوف">
          {(["all", "executable", "blocked", "warnings"] as DryRunFilter[]).map((f) => (
            <Button key={f} size="sm" variant={filter === f ? "default" : "outline"} onClick={() => { setFilter(f); setPage(1); setOpen(null); }}>
              {DRY_RUN_FILTER_LABELS[f]}
            </Button>
          ))}
        </div>
        <Input
          className="h-8 w-56"
          placeholder="رقم الصف أو مفتاح الأسرة"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); setOpen(null); }}
          aria-label="بحث في خطط الصفوف"
        />
        {reason && (
          <Badge variant="outline" className="gap-1">
            {planReasonLabel(reason)}
            <button type="button" aria-label="إزالة تصفية السبب" onClick={() => { onReason(""); setPage(1); }}><X className="size-3" /></button>
          </Badge>
        )}
      </div>
      {rows.isLoading ? (
        <Skeleton className="h-32 w-full" />
      ) : (rows.data?.data ?? []).length === 0 ? (
        <p className="text-sm text-muted-foreground">لا توجد صفوف مطابقة.</p>
      ) : (
        <div className="overflow-x-auto rounded-md border border-stroke-subtle">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="ps-4">رقم الصف</TableHead>
                <TableHead>مفتاح العائلة</TableHead>
                <TableHead>هوية رب الأسرة</TableHead>
                <TableHead>الحالة</TableHead>
                <TableHead>رب الأسرة</TableHead>
                <TableHead>الأسرة</TableHead>
                <TableHead>الأزواج / الزوجات</TableHead>
                <TableHead>ملاحظات</TableHead>
                <TableHead className="pe-4"><span className="sr-only">تفاصيل</span></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.data!.data.map((r) => (
                <Fragment key={r.row_number}>
                  <TableRow data-plan-executable={r.executable}>
                    <TableCell className="ps-4 tabular-nums">{fmt(r.row_number)}</TableCell>
                    <TableCell>{r.source_family_key ?? <span className="text-muted-foreground">—</span>}</TableCell>
                    <TableCell dir="ltr" className="text-end font-mono text-xs">{r.national_id_masked ?? "—"}</TableCell>
                    <TableCell>
                      <Badge variant={r.executable ? "secondary" : "destructive"} className={r.executable ? "text-success" : undefined}>
                        {r.executable ? "قابل للتنفيذ" : "متعذر"}
                      </Badge>
                    </TableCell>
                    <TableCell><IntentBadge e={r.head_person} /></TableCell>
                    <TableCell><IntentBadge e={r.family} /></TableCell>
                    <TableCell className="text-xs">{spouseSummary(r)}</TableCell>
                    <TableCell className="whitespace-normal text-xs">
                      {r.block_reasons.length > 0 && <span className="text-danger">{r.block_reasons.map(planReasonLabel).join("، ")}</span>}
                      {r.block_reasons.length > 0 && r.warnings.length > 0 && "، "}
                      {r.warnings.length > 0 && <span className="text-muted-foreground">{r.warnings.map(planReasonLabel).join("، ")}</span>}
                      {r.block_reasons.length === 0 && r.warnings.length === 0 && "—"}
                    </TableCell>
                    <TableCell className="pe-4">
                      <Button size="sm" variant="ghost" onClick={() => setOpen(open === r.row_number ? null : r.row_number)}>
                        {open === r.row_number ? "إخفاء" : "تفاصيل"}
                      </Button>
                    </TableCell>
                  </TableRow>
                  {open === r.row_number && (
                    <TableRow className="hover:bg-transparent">
                      <TableCell colSpan={9} className="p-0"><RowDetail row={r} /></TableCell>
                    </TableRow>
                  )}
                </Fragment>
              ))}
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

export function StepDryRun({ batch }: { batch: ImportBatchDetail }) {
  const { can } = useAuth();
  const [started, setStarted] = useState(false);
  const [reason, setReason] = useState("");
  const dry = useDryRun(batch.id, started);
  const result = dry.data?.data;
  const counts = result?.counts ?? null;
  const running = dry.isFetching;

  return (
    <div className="flex flex-col gap-4">
      <Panel className="flex flex-col gap-4" data-section="dry-run">
        <SectionHeader icon={Eye} title="المعاينة قبل الاستيراد" description="ما الذي سيحدث لو طُبّقت هذه الدفعة الآن — محسوب من الخادم لكل صف." />
        <Alert>
          <Info className="size-4" />
          <AlertDescription className="font-medium">هذه معاينة فقط ولن يتم إنشاء أو تعديل أي سجل.</AlertDescription>
        </Alert>
        <div className="flex flex-wrap items-center gap-3">
          <Button disabled={running || !can("import.review")} onClick={() => (started ? dry.refetch() : setStarted(true))}>
            {running ? "جارٍ إعداد المعاينة…" : started ? "إعادة تشغيل المعاينة" : "تشغيل المعاينة"}
          </Button>
          <span className="text-xs text-muted-foreground">التنفيذ النهائي غير مفعّل في هذه المرحلة.</span>
        </div>
        {running && <ProcessList items={[{ label: "حساب خطة كل صف: الأشخاص والأسر والعضويات والإقرارات والإقامة (دون أي كتابة)", state: "active" }]} />}
        {dry.error && <p className="text-sm text-danger">تعذّر إعداد المعاينة. الرجاء المحاولة مرة أخرى.</p>}

        {result?.state === "PRECONDITIONS_FAILED" && (
          <Alert variant="destructive" data-preconditions>
            <AlertTriangle className="size-4" />
            <AlertTitle>لا يمكن إعداد المعاينة الآن</AlertTitle>
            <AlertDescription>
              <ul className="list-inside list-disc">
                {result.preconditions.map((p) => (
                  <li key={p.code}>{PRECONDITION_LABELS[p.code] ?? p.code}{p.count ? ` (${fmt(p.count)})` : ""}</li>
                ))}
              </ul>
            </AlertDescription>
          </Alert>
        )}

        {counts && (
          <>
            {result?.state === "ROWS_BLOCKED" ? (
              <Alert>
                <AlertTriangle className="size-4" />
                <AlertTitle>{fmt(counts.blocked_rows)} صفًا متعذرة</AlertTitle>
                <AlertDescription>لن تُنفَّذ هذه الصفوف حتى تُعالج أسباب توقفها.</AlertDescription>
              </Alert>
            ) : (
              <p className="text-sm font-medium text-success" data-dry-run-success>
                المعاينة ناجحة — جميع الصفوف الـ{fmt(counts.executable_rows)} قابلة للتنفيذ، ولا توجد حالات مانعة.
              </p>
            )}
            <SummaryCards counts={counts} />
            <EffectTable counts={counts} />
            <p className="text-xs text-muted-foreground">
              خانات الأزواج/الزوجات المعبأة: {fmt(counts.spouse_slots)} — أشخاص يُستخدمون من صف آخر في الدفعة نفسها: {fmt(counts.spouse_person_reuse.planned)}، ومن السجل: {fmt(counts.spouse_person_reuse.existing)}.
            </p>
            <div className="grid gap-4 lg:grid-cols-2">
              <ReasonTable title="ملاحظات غير مانعة" unit="صف" entries={Object.entries(counts.warnings)} onPick={setReason} />
              <ReasonTable title="حالات التجاوز" unit="حالة" entries={Object.entries(counts.reasons).filter(([code]) => OMIT_REASON_CODES.has(code))} onPick={setReason} />
            </div>
            {/* Blocking reasons stay apart from notes and intentional omissions. */}
            {counts.blocked_rows > 0 && (
              <div className="text-danger">
                <ReasonTable title="أسباب التعذر" unit="حالة" entries={Object.entries(counts.reasons).filter(([code]) => !OMIT_REASON_CODES.has(code))} onPick={setReason} />
              </div>
            )}
          </>
        )}
      </Panel>

      {counts && (
        <Panel className="flex flex-col gap-3" data-section="dry-run-rows">
          <SectionHeader title="خطة كل صف" description="للاطلاع فقط: أرقام هوية مقنّعة ورموز قرارات — دون أسماء أو بيانات المصدر." />
          <RowPlans batchId={batch.id} reason={reason} onReason={setReason} />
        </Panel>
      )}
    </div>
  );
}
