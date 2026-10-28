"use client";

import { useState } from "react";
import { AlertTriangle, ArrowRight, Lock, Search, SearchX, ServerOff } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader, Panel } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { LoadingBlock } from "@/components/administration/civil-registry/parts";
import {
  civilLookupErrorCode,
  civilLookupErrorMessage,
  useCivilLookupLinks,
  useCivilLookupOpenLink,
  useCivilLookupOpenRecord,
  useCivilLookupPage,
  useCivilLookupSearch,
  useCivilLookupStatus,
} from "@/lib/api/civil-lookup";
import type {
  CivilLookupLink,
  CivilLookupList,
  CivilLookupMode,
  CivilLookupRecord,
  CivilLookupResultEntry,
  CivilLookupReverseRelation,
  CivilLookupSpace,
} from "@/lib/types/api/civil-lookup";
import type { PaginationMeta } from "@/lib/types/api/family";
import { isValidNationalId } from "@/lib/utils/national-id";
import { cn } from "@/lib/utils";
import { CitizenDetail } from "./citizen-detail";
import { MISSING, RELATION_LABELS } from "./labels";
import { QuotaIndicator } from "./quota-indicator";

// FB-CIVIL-02A — the Staff Portal Civil Registry Lookup
// (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md). Results, records and the
// breadcrumb live in this component's memory only: no URL parameter, no
// browser storage, no query cache. Going back to something already shown
// sends no request (and is never counted).
//
// FB-CIVIL-02B: the same page on the Family Portal and in Coordinator Space
// (`space`): only the API mount and the header change. Who may use it, and
// which fields come back, is decided by the server for that mount.

type View =
  | { kind: "list"; title: string; data: CivilLookupList }
  | { kind: "record"; title: string; record: CivilLookupRecord }
  | { kind: "not-found"; title: string };

const MODES: { key: CivilLookupMode; label: string }[] = [
  { key: "national-id", label: "رقم الهوية" },
  { key: "name", label: "الاسم الكامل" },
  { key: "parts", label: "البحث التفصيلي" },
];

const PART_FIELDS = [
  { key: "name1", label: "الاسم الأول" },
  { key: "father", label: "اسم الأب" },
  { key: "grandfath", label: "اسم الجد" },
  { key: "family", label: "اسم العائلة" },
] as const;

type PartKey = (typeof PART_FIELDS)[number]["key"];

/** Client-side guidance only (the API validates again); mirrors the server's token rules. */
function nameTokensAcceptable(values: string[], min: number): boolean {
  const tokens = values
    .map((v) => v.replace(/[ً-ٰٟـ]/g, "").replace(/[أإآٱ]/g, "ا").trim())
    .filter((v) => v !== "");
  if (tokens.length < min || tokens.length > 6) return false;
  return tokens.every((t) => [...t].length >= 2) && tokens.some((t) => [...t].length >= 3);
}

function validate(mode: CivilLookupMode, nationalId: string, fullname: string, parts: Record<PartKey, string>): string | null {
  if (mode === "national-id") return isValidNationalId(nationalId) ? null : "رقم الهوية يجب أن يتكون من 9 أرقام.";
  if (mode === "name") {
    return nameTokensAcceptable(fullname.split(/\s+/), 2)
      ? null
      : "أدخل كلمتين على الأقل من الاسم (حرفان على الأقل لكل كلمة، وثلاثة أحرف لإحداها).";
  }
  const given = Object.values(parts).filter((v) => v.trim() !== "");
  return nameTokensAcceptable(given, 2) ? null : "أدخل حقلين على الأقل من حقول الاسم (حرفان على الأقل لكل حقل، وثلاثة أحرف لأحدها).";
}

function listMeta(data: CivilLookupList): PaginationMeta {
  const total = data.meta.total ?? 0;
  const from = total === 0 ? null : (data.meta.page - 1) * data.meta.per_page + 1;
  return {
    current_page: data.meta.page,
    last_page: data.meta.last_page,
    per_page: data.meta.per_page,
    total,
    from,
    to: from === null ? null : from + data.results.length - 1,
    path: "",
  };
}

const DESCRIPTION = "استعلام من الإصدار المنشور فقط. كل بحث صحيح يُحتسب من رصيدك اليومي حتى لو لم تُعثر على نتيجة.";

/** The page title: the Staff page header, or the Family Portal heading style (docs/10). */
function LookupHeader({ space }: { space: CivilLookupSpace }) {
  if (space === "staff") return <PageHeader title="الاستعلام عن السجل المدني" description={DESCRIPTION} />;
  return (
    <header className="flex flex-col gap-1.5" data-lookup-header={space}>
      <h1 className="text-2xl leading-snug font-bold text-foreground">الاستعلام عن السجل المدني</h1>
      <p className="text-[13px] text-muted-foreground">{DESCRIPTION}</p>
      <p className="text-[13px] text-muted-foreground" data-shared-quota-note>
        {space === "coordinator"
          ? "يقرأ هذا الاستعلام السجل المدني فقط، ولا يتعلق بأسر نطاق التنسيق. رصيدك اليومي واحد في بوابة الأسرة ومساحة التنسيق."
          : "رصيدك اليومي واحد في بوابة الأسرة ومساحة التنسيق."}
      </p>
    </header>
  );
}

export function CivilLookupPage({ space = "staff" }: { space?: CivilLookupSpace }) {
  const status = useCivilLookupStatus(space);
  const search = useCivilLookupSearch(space);
  const page = useCivilLookupPage(space);
  const openRecord = useCivilLookupOpenRecord(space);
  const openLink = useCivilLookupOpenLink(space);
  const links = useCivilLookupLinks(space);

  const [mode, setMode] = useState<CivilLookupMode>("national-id");
  const [nationalId, setNationalId] = useState("");
  const [fullname, setFullname] = useState("");
  const [parts, setParts] = useState<Record<PartKey, string>>({ name1: "", father: "", grandfath: "", family: "" });
  const [formError, setFormError] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [trail, setTrail] = useState<View[]>([]);

  const busy = search.isPending || page.isPending || openRecord.isPending || openLink.isPending || links.isPending;
  const current = trail.at(-1);
  const quota = status.data?.quota;
  const canView = status.data?.capabilities.view === true;

  const push = (view: View) => setTrail((t) => [...t, view]);
  const replaceTop = (view: View) => setTrail((t) => [...t.slice(0, -1), view]);
  const fail = (e: unknown) => {
    setError(e);
    // A changed release or an expired reference invalidates the whole navigation.
    const code = civilLookupErrorCode(e);
    if (code === "RELEASE_CHANGED" || code === "REFERENCE_INVALID") setTrail([]);
  };

  function submit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    const problem = validate(mode, nationalId, fullname, parts);
    setFormError(problem);
    if (problem) return;
    const body: Record<string, string | null> =
      mode === "national-id"
        ? { national_id: nationalId.trim() }
        : mode === "name"
          ? { fullname: fullname.trim() }
          : Object.fromEntries(PART_FIELDS.map((f) => [f.key, parts[f.key].trim() || null]));
    search.mutate(
      { mode, body },
      {
        onSuccess: (data) => {
          setTrail([{ kind: "list", title: "نتائج البحث", data }]);
          // A single National ID result opens directly (free: it is in the authorized result).
          if (mode === "national-id" && data.results.length === 1 && canView) {
            openRecord.mutate(data.results[0].ref, {
              onSuccess: (r) => r.record && push({ kind: "record", title: r.record.fullname ?? "سجل المواطن", record: r.record }),
              onError: fail,
            });
          }
        },
        onError: fail,
      },
    );
  }

  function showResult(entry: CivilLookupResultEntry) {
    setError(null);
    openRecord.mutate(entry.ref, {
      onSuccess: (r) => r.record && push({ kind: "record", title: r.record.fullname ?? "سجل المواطن", record: r.record }),
      onError: fail,
    });
  }

  function showLink(link: CivilLookupLink) {
    if (!link.ref) return;
    setError(null);
    openLink.mutate(link.ref, {
      onSuccess: (r) =>
        push(
          r.record
            ? { kind: "record", title: `${RELATION_LABELS[link.relation]}: ${r.record.fullname ?? MISSING}`, record: r.record }
            : { kind: "not-found", title: RELATION_LABELS[link.relation] },
        ),
      onError: fail,
    });
  }

  function showLinks(link: CivilLookupLink, relation: CivilLookupReverseRelation) {
    if (!link.ref) return;
    setError(null);
    links.mutate(
      { ref: link.ref, relation },
      { onSuccess: (data) => push({ kind: "list", title: RELATION_LABELS[relation], data }), onError: fail },
    );
  }

  function goToPage(list: CivilLookupList, target: number) {
    if (!list.search_ref) return;
    setError(null);
    page.mutate(
      { ref: list.search_ref, page: target },
      { onSuccess: (data) => replaceTop({ kind: "list", title: (current as { title: string }).title, data: { ...data, search_ref: list.search_ref } }), onError: fail },
    );
  }

  if (status.isPending) return <LoadingBlock rows={4} />;
  if (status.isError) {
    return <EmptyState icon={ServerOff} title="تعذّر تحميل حالة خدمة الاستعلام." description={civilLookupErrorMessage(status.error)} />;
  }

  return (
    <div className="flex flex-col gap-5" data-civil-lookup={space}>
      <LookupHeader space={space} />

      <Panel data-quota-panel>
        {quota && quota.enabled ? (
          <QuotaIndicator quota={quota} />
        ) : (
          <div className="flex items-center gap-2 text-sm text-muted-foreground" data-lookup-disabled={quota?.reason ?? "UNKNOWN"}>
            <Lock className="size-4" aria-hidden />
            {quota?.reason === "LOOKUP_DISABLED"
              ? "خدمة الاستعلام عن السجل المدني غير مفعّلة حاليًا."
              : "خدمة الاستعلام غير مفعّلة لدورك أو لحسابك."}
          </div>
        )}
      </Panel>

      {quota?.enabled && (
        <Panel>
          <form onSubmit={submit} className="flex flex-col gap-4" noValidate data-lookup-form={mode}>
            <div role="tablist" aria-label="طريقة البحث" className="flex flex-wrap gap-2">
              {MODES.map((m) => (
                <button
                  key={m.key}
                  type="button"
                  role="tab"
                  aria-selected={mode === m.key}
                  onClick={() => {
                    setMode(m.key);
                    setFormError(null);
                  }}
                  className={cn(
                    "rounded-control border px-3 py-1.5 text-sm transition-colors",
                    mode === m.key ? "border-brand-600 bg-brand-50 font-semibold text-brand-700" : "border-stroke-subtle hover:bg-surface-hover",
                  )}
                >
                  {m.label}
                </button>
              ))}
            </div>

            {mode === "national-id" && (
              <div className="flex max-w-sm flex-col gap-1.5">
                <Label htmlFor="lookup-national-id">رقم الهوية</Label>
                <Input
                  id="lookup-national-id"
                  inputMode="numeric"
                  autoComplete="off"
                  dir="ltr"
                  maxLength={32}
                  value={nationalId}
                  onChange={(e) => setNationalId(e.target.value)}
                />
                <p className="text-[13px] text-muted-foreground">مطابقة تامة لرقم من 9 أرقام؛ نتيجة واحدة كحد أقصى.</p>
              </div>
            )}
            {mode === "name" && (
              <div className="flex max-w-xl flex-col gap-1.5">
                <Label htmlFor="lookup-fullname">الاسم الكامل أو جزء منه</Label>
                <Input id="lookup-fullname" autoComplete="off" maxLength={100} value={fullname} onChange={(e) => setFullname(e.target.value)} />
                <p className="text-[13px] text-muted-foreground">
                  كلمتان على الأقل وبأي ترتيب. يُتجاهل التشكيل والتطويل واختلاف أشكال الألف؛ لا تُدمج «ة» مع «ه» ولا «ى» مع «ي».
                </p>
              </div>
            )}
            {mode === "parts" && (
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {PART_FIELDS.map((f) => (
                  <div key={f.key} className="flex flex-col gap-1.5">
                    <Label htmlFor={`lookup-${f.key}`}>{f.label}</Label>
                    <Input
                      id={`lookup-${f.key}`}
                      autoComplete="off"
                      maxLength={50}
                      value={parts[f.key]}
                      onChange={(e) => setParts((p) => ({ ...p, [f.key]: e.target.value }))}
                    />
                  </div>
                ))}
                <p className="text-[13px] text-muted-foreground sm:col-span-2 lg:col-span-4">حقلان إلى أربعة حقول؛ يكفي جزء من الاسم في كل حقل.</p>
              </div>
            )}

            {formError && (
              <p className="text-sm text-danger" role="alert" data-form-error>
                {formError}
              </p>
            )}
            <div>
              <Button type="submit" disabled={busy || (quota.remaining !== null && quota.remaining <= 0)}>
                <Search className="size-4" aria-hidden />
                بحث
              </Button>
            </div>
          </form>
        </Panel>
      )}

      {error !== null && (
        <Alert variant="destructive" data-lookup-error={civilLookupErrorCode(error) ?? "UNKNOWN"}>
          <AlertTriangle className="size-4" aria-hidden />
          <AlertDescription>{civilLookupErrorMessage(error)}</AlertDescription>
        </Alert>
      )}

      {trail.length > 0 && (
        <nav aria-label="مسار التنقل" className="flex flex-wrap items-center gap-2 text-sm" data-breadcrumb>
          {trail.length > 1 && (
            <Button variant="ghost" size="sm" onClick={() => setTrail((t) => t.slice(0, -1))} data-back>
              <ArrowRight className="size-3.5" aria-hidden />
              رجوع
            </Button>
          )}
          <ol className="flex flex-wrap items-center gap-1.5">
            {trail.map((view, index) => (
              <li key={index} className="flex items-center gap-1.5">
                {index > 0 && <span aria-hidden className="text-subtle-foreground">/</span>}
                {index === trail.length - 1 ? (
                  <span aria-current="page" className="font-semibold">
                    {view.title}
                  </span>
                ) : (
                  <button type="button" className="text-brand-700 hover:underline" onClick={() => setTrail((t) => t.slice(0, index + 1))}>
                    {view.title}
                  </button>
                )}
              </li>
            ))}
          </ol>
        </nav>
      )}

      {busy && trail.length === 0 && <LoadingBlock rows={3} />}

      {current?.kind === "list" && (
        <ResultsList list={current.data} canView={canView} busy={busy} onOpen={showResult} onPage={(p) => goToPage(current.data, p)} />
      )}
      {current?.kind === "record" && <CitizenDetail record={current.record} busy={busy} onOpenLink={showLink} onOpenLinks={showLinks} />}
      {current?.kind === "not-found" && (
        <Panel>
          <EmptyState icon={SearchX} title="الشخص المرتبط غير موجود في الإصدار المنشور." />
        </Panel>
      )}
    </div>
  );
}

function ResultsList({
  list,
  canView,
  busy,
  onOpen,
  onPage,
}: {
  list: CivilLookupList;
  canView: boolean;
  busy: boolean;
  onOpen: (entry: CivilLookupResultEntry) => void;
  onPage: (page: number) => void;
}) {
  if (list.outcome === "TOO_BROAD") {
    return (
      <Panel data-outcome="TOO_BROAD">
        <EmptyState icon={Search} title="نتائج كثيرة جدًا" description="يطابق البحث أكثر من 500 سجل. أضف كلمات أخرى من الاسم لتضييق البحث." />
      </Panel>
    );
  }
  if (list.results.length === 0) {
    return (
      <Panel data-outcome="NOT_FOUND">
        <EmptyState icon={SearchX} title="لا توجد نتائج مطابقة في الإصدار المنشور." />
      </Panel>
    );
  }

  return (
    <Panel flush data-outcome={list.outcome}>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>الاسم الكامل</TableHead>
            <TableHead>رقم الهوية</TableHead>
            <TableHead>الجنس (رمز المصدر)</TableHead>
            <TableHead>تاريخ الميلاد</TableHead>
            <TableHead>المحافظة / المدينة (رمز المصدر)</TableHead>
            <TableHead>
              <span className="sr-only">إجراء</span>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {list.results.map((entry) => (
            <TableRow key={entry.ref} data-result>
              <TableCell className="font-medium">{entry.fullname ?? MISSING}</TableCell>
              <TableCell>
                <bdi dir="ltr" className="tabular-nums">
                  {entry.national_id ?? MISSING}
                </bdi>
              </TableCell>
              <TableCell>{entry.gender ?? MISSING}</TableCell>
              <TableCell>
                <bdi dir="ltr">{entry.datebirth ?? MISSING}</bdi>
              </TableCell>
              <TableCell>{[entry.govern, entry.city].filter(Boolean).join(" / ") || MISSING}</TableCell>
              <TableCell className="text-end">
                {canView && (
                  <Button variant="outline" size="sm" disabled={busy} onClick={() => onOpen(entry)}>
                    عرض
                  </Button>
                )}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {list.meta.total !== null && list.meta.total > list.meta.per_page && list.search_ref && (
        <RegistryPagination meta={listMeta(list)} onPage={onPage} unit="سجل" />
      )}
    </Panel>
  );
}
