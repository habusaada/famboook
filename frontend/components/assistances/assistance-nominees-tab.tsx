"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { Check, CheckCheck, ListChecks, Search, UserMinus, UserPlus, Users } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
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
import { FieldLabel, SaveError } from "@/components/shared/edit-dialog-parts";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { IconBox } from "@/components/shared/icon-box";
import { Code, SectionHeader } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { NominationSourceTag, NomineeStatusTag } from "@/components/assistances/assistance-case";
import { NeedPriorityTag } from "@/components/needs/need-case";
import {
  DeliveryDialog,
  ExecutionState,
  NotDeliveredDialog,
  RejectNomineeDialog,
  ReverseDeliveryDialog,
} from "@/components/assistances/assistance-execution-actions";
import { NeedPriorityBadge } from "@/components/needs/need-badges";
import {
  useApproveNominee,
  useBulkApprove,
  useNominateFromNeeds,
  useNominateManually,
  useNomineeCandidates,
  useNominees,
  useRemoveNominee,
} from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import { useNeedsQueue } from "@/lib/api/needs";
import { useNeedCategories } from "@/lib/api/reference";
import type { Assistance, AssistanceResponse, Nominee, NomineeSummary } from "@/lib/types/api/assistance";
import type { NeedPriority } from "@/lib/types/api/need";
import { formatDateTime } from "@/lib/utils/date";
import { cn } from "@/lib/utils";
import { FAMILY_TARGET_LABEL, NEED_PRIORITIES, needPriorityLabels } from "@/lib/utils/need";

const fmt = (n: number) => n.toLocaleString("ar");
const head = "h-10 text-xs font-medium text-muted-foreground";

// Every number is derived by the API on read — nothing here is stored.
const COUNTERS: { key: keyof NomineeSummary; label: string }[] = [
  { key: "total", label: "إجمالي المرشحين" },
  { key: "family", label: "على مستوى الأسرة" },
  { key: "person", label: "أفراد محددون" },
  { key: "targeting", label: "من الاستهداف" },
  { key: "need", label: "من الاحتياجات" },
  { key: "manual", label: "إضافة يدوية" },
];

function errorMessage(e: unknown, fallback: string): string {
  if (e instanceof ApiError && (e.status === 422 || e.status === 409) && e.message422) return e.message422;
  if (e instanceof ApiError && e.status === 403) return "لا تملك صلاحية إدارة المرشحين.";
  return fallback;
}

// ---------------------------------------------------------------------------

function ManualNomineeDialog({ assistance }: { assistance: Assistance }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [familyCode, setFamilyCode] = useState<string | null>(null);
  const [personCode, setPersonCode] = useState<string>("");
  const [target, setTarget] = useState<"family" | "person">("family");
  const [error, setError] = useState<string | null>(null);
  const candidates = useNomineeCandidates(assistance.id, open ? search : "");
  const mutation = useNominateManually(assistance.id);
  const submitting = useRef(false);

  const family = candidates.data?.data.find((f) => f.family_code === familyCode);

  function reset() {
    setSearch("");
    setFamilyCode(null);
    setPersonCode("");
    setTarget("family");
    setError(null);
  }

  function submit() {
    if (!familyCode || submitting.current) return;
    if (target === "person" && !personCode) {
      setError("اختر فردًا من الأسرة.");
      return;
    }
    submitting.current = true;
    setError(null);
    mutation.mutate(
      { family_code: familyCode, person_code: target === "person" ? personCode : null },
      {
        onSettled: () => {
          submitting.current = false;
        },
        onSuccess: () => {
          setOpen(false);
          reset();
        },
        onError: (e) => setError(errorMessage(e, "تعذّرت إضافة المرشح.")),
      }
    );
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (mutation.isPending) return;
        if (next) reset();
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant="outline">
          <UserPlus className="size-4" />
          إضافة مرشح يدويًا
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>إضافة مرشح يدويًا</DialogTitle>
          <DialogDescription>ابحث برقم الأسرة أو رقم الشخص أو الاسم، ثم اختر الأسرة كاملة أو فردًا محددًا.</DialogDescription>
        </DialogHeader>
        <SaveError message={error} />
        <div className="flex flex-col gap-3">
          <div className="relative">
            <Search className="absolute top-1/2 start-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              aria-label="بحث عن أسرة"
              placeholder="مثال: FAM-000013 أو اسم"
              className="ps-8"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setFamilyCode(null);
              }}
            />
          </div>
          {search.trim().length >= 2 && (
            <ul className="max-h-48 divide-y overflow-y-auto rounded-md border" aria-label="نتائج البحث">
              {candidates.isLoading && <li className="p-3 text-sm text-muted-foreground">جارٍ البحث...</li>}
              {candidates.data?.data.length === 0 && (
                <li className="p-3 text-sm text-muted-foreground">لا توجد نتائج.</li>
              )}
              {candidates.data?.data.map((f) => (
                <li key={f.family_code}>
                  <button
                    type="button"
                    data-candidate={f.family_code}
                    onClick={() => {
                      setFamilyCode(f.family_code);
                      setPersonCode("");
                    }}
                    className={`flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-muted ${familyCode === f.family_code ? "bg-muted" : ""}`}
                  >
                    <span>
                      <span dir="ltr" className="font-medium">{f.family_code}</span>
                      {f.household_head_name && <span className="ms-2 text-muted-foreground">{f.household_head_name}</span>}
                    </span>
                    {f.family_nominated && <Badge variant="outline">الأسرة مرشحة</Badge>}
                  </button>
                </li>
              ))}
            </ul>
          )}
          {family && (
            <div className="flex flex-col gap-2 rounded-md border p-3">
              <FieldLabel htmlFor="manual-target">المرشح</FieldLabel>
              <Select value={target} onValueChange={(v) => setTarget(v as "family" | "person")}>
                <SelectTrigger id="manual-target">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="family" disabled={family.family_nominated}>
                    الأسرة كاملة
                  </SelectItem>
                  <SelectItem value="person">فرد محدد</SelectItem>
                </SelectContent>
              </Select>
              {target === "person" && (
                <Select value={personCode} onValueChange={setPersonCode}>
                  <SelectTrigger aria-label="فرد الأسرة">
                    <SelectValue placeholder="اختر فردًا" />
                  </SelectTrigger>
                  <SelectContent>
                    {family.members.map((m) => (
                      <SelectItem key={m.person_code} value={m.person_code} disabled={m.nominated}>
                        {m.full_name}
                        {m.nominated ? " (مرشح)" : ""}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </div>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button type="button" onClick={submit} disabled={!familyCode || mutation.isPending}>
            {mutation.isPending ? "جارٍ الإضافة..." : "إضافة المرشح"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------

const ALL = "ALL";

function NeedsNomineeDialog({ assistance }: { assistance: Assistance }) {
  const [open, setOpen] = useState(false);
  const [category, setCategory] = useState<string | undefined>();
  const [priority, setPriority] = useState<NeedPriority | undefined>();
  const [family, setFamily] = useState("");
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const categories = useNeedCategories().data?.data ?? [];
  const needs = useNeedsQueue({ status: "OPEN", category, priority, family: family.trim() || undefined });
  const mutation = useNominateFromNeeds(assistance.id);
  const rows = needs.data?.pages.flatMap((p) => p.data) ?? [];

  function toggle(id: string) {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelected(next);
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (mutation.isPending) return;
        if (next) {
          setSelected(new Set());
          setResult(null);
          setError(null);
        }
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant="outline">
          <ListChecks className="size-4" />
          ترشيح من الاحتياجات
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>ترشيح من الاحتياجات المفتوحة</DialogTitle>
          <DialogDescription>
            تُرشَّح الأسرة (أو الفرد في احتياج خاص بفرد) ويُسجَّل الاحتياج مصدرًا للترشيح. لا تتغير حالة
            الاحتياج.
          </DialogDescription>
        </DialogHeader>
        <SaveError message={error} />
        {result && <p className="rounded-md bg-muted px-3 py-2 text-sm">{result}</p>}
        <div className="flex flex-wrap gap-2">
          <Select value={category ?? ALL} onValueChange={(v) => setCategory(v === ALL ? undefined : v)}>
            <SelectTrigger size="sm" aria-label="التصنيف" className="min-w-36">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>التصنيف: الكل</SelectItem>
              {categories.map((c) => (
                <SelectItem key={c.code} value={c.code}>
                  {c.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={priority ?? ALL} onValueChange={(v) => setPriority(v === ALL ? undefined : (v as NeedPriority))}>
            <SelectTrigger size="sm" aria-label="الأولوية" className="min-w-36">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>الأولوية: الكل</SelectItem>
              {[...NEED_PRIORITIES].reverse().map((p) => (
                <SelectItem key={p} value={p}>
                  {needPriorityLabels[p]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Input
            aria-label="رقم الأسرة"
            placeholder="رقم الأسرة (مطابق)"
            dir="ltr"
            className="h-8 w-44 text-end"
            value={family}
            onChange={(e) => setFamily(e.target.value)}
          />
        </div>
        <div className="max-h-80 overflow-y-auto rounded-md border">
          {needs.isLoading ? (
            <Skeleton className="m-3 h-20" />
          ) : rows.length === 0 ? (
            <p className="p-6 text-center text-sm text-muted-foreground">لا توجد احتياجات مفتوحة مطابقة.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-0" />
                  <TableHead>الاحتياج</TableHead>
                  <TableHead>الأسرة</TableHead>
                  <TableHead>المستفيد</TableHead>
                  <TableHead>الأولوية</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((n) => (
                  <TableRow key={n.id} data-need-id={n.id}>
                    <TableCell>
                      <input
                        type="checkbox"
                        aria-label={`تحديد ${n.title}`}
                        className="size-4 accent-primary"
                        checked={selected.has(n.id)}
                        onChange={() => toggle(n.id)}
                      />
                    </TableCell>
                    <TableCell>
                      {n.title}
                      <span className="block text-xs text-muted-foreground">{n.category.name}</span>
                    </TableCell>
                    <TableCell dir="ltr" className="text-end">{n.family.family_code}</TableCell>
                    <TableCell>{n.person?.full_name ?? FAMILY_TARGET_LABEL}</TableCell>
                    <TableCell>
                      <NeedPriorityBadge priority={n.priority} />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إغلاق
          </Button>
          <Button
            type="button"
            disabled={selected.size === 0 || mutation.isPending}
            onClick={() =>
              mutation.mutate([...selected], {
                onSuccess: (response) => {
                  const { created, skipped_duplicates } = response.data;
                  setResult(
                    `تمت إضافة ${created} مرشح` + (skipped_duplicates ? `، وتم تخطي ${skipped_duplicates} مرشح مسبقًا.` : ".")
                  );
                  setSelected(new Set());
                  setError(null);
                },
                onError: (e) => setError(errorMessage(e, "تعذّر الترشيح من الاحتياجات.")),
              })
            }
          >
            {mutation.isPending ? "جارٍ الترشيح..." : `ترشيح المحدد (${selected.size})`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------

function RemoveNomineeButton({ assistance, nominee }: { assistance: Assistance; nominee: Nominee }) {
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const mutation = useRemoveNominee(assistance.id);

  return (
    <Dialog open={open} onOpenChange={(next) => !mutation.isPending && setOpen(next)}>
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm" aria-label="إزالة الترشيح" onClick={() => setError(null)}>
          <UserMinus className="size-4" />
          إزالة
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إزالة الترشيح</DialogTitle>
          <DialogDescription>
            يُزال {nominee.person?.full_name ?? `الأسرة ${nominee.family.family_code}`} من قائمة المرشحين لهذه
            المساعدة. يبقى الترشيح محفوظًا في السجل ولا تُحذف أي بيانات للأسرة.
          </DialogDescription>
        </DialogHeader>
        <SaveError message={error} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button
            type="button"
            variant="destructive"
            disabled={mutation.isPending}
            onClick={() =>
              mutation.mutate(nominee.id, {
                onSuccess: () => setOpen(false),
                onError: (e) => setError(errorMessage(e, "تعذّرت إزالة الترشيح.")),
              })
            }
          >
            {mutation.isPending ? "جارٍ الإزالة..." : "إزالة الترشيح"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------

function BulkApproveButton({ assistance, selected, onDone }: { assistance: Assistance; selected: string[]; onDone: () => void }) {
  const mutation = useBulkApprove(assistance.id);
  const [error, setError] = useState<string | null>(null);

  return (
    <div className="flex flex-col items-end gap-1">
      <Button
        size="sm"
        disabled={selected.length === 0 || mutation.isPending}
        onClick={() =>
          mutation.mutate(selected, {
            onSuccess: () => {
              setError(null);
              onDone();
            },
            onError: (e) => setError(errorMessage(e, "تعذّر اعتماد المحددين.")),
          })
        }
      >
        <CheckCheck className="size-4" />
        {mutation.isPending ? "جارٍ الاعتماد..." : `اعتماد المحدد (${selected.length})`}
      </Button>
      {error && <span className="text-xs text-destructive">{error}</span>}
    </div>
  );
}

function ApproveButton({ assistance, nominee }: { assistance: Assistance; nominee: Nominee }) {
  const mutation = useApproveNominee(assistance.id);
  return (
    <Button size="sm" className="h-8" disabled={mutation.isPending} onClick={() => mutation.mutate(nominee.id)}>
      <Check className="size-4" />
      اعتماد
    </Button>
  );
}

export function AssistanceNomineesTab({
  assistance,
  abilities,
}: {
  assistance: Assistance;
  abilities: AssistanceResponse["abilities"];
}) {
  const canNominate = abilities.nominate;
  const [includeRemoved, setIncludeRemoved] = useState(false);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const { data, isLoading, isError, error } = useNominees(assistance.id, includeRemoved, page);
  const external = assistance.execution_mode === "EXTERNAL";

  if (isError) {
    return (
      <AppCard padded={false}>
        <EmptyState
          icon={Users}
          title={error instanceof ApiError && error.status === 403 ? "لا تملك صلاحية عرض المرشحين." : "تعذّر تحميل المرشحين."}
        />
      </AppCard>
    );
  }

  const nominees = data?.data ?? [];
  const approvable = nominees.filter((n) => n.status === "NOMINATED");
  const hasActions = canNominate || abilities.approve || abilities.deliver || abilities.reverse;
  const toggle = (id: string) => {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelected(next);
  };

  /** The row's actions, per the API abilities and the nominee's state (unchanged rules). */
  const actions = (n: Nominee) => (
    <>
      {abilities.approve && n.status === "NOMINATED" && (
        <>
          <ApproveButton assistance={assistance} nominee={n} />
          <RejectNomineeDialog assistance={assistance} nominee={n} />
        </>
      )}
      {abilities.deliver && n.status === "APPROVED" && !n.active_delivery && (
        <>
          <DeliveryDialog assistance={assistance} nominee={n} />
          <NotDeliveredDialog assistance={assistance} nominee={n} />
        </>
      )}
      {abilities.reverse && n.active_delivery && <ReverseDeliveryDialog assistance={assistance} deliveryId={n.active_delivery.id} />}
      {canNominate && n.status === "NOMINATED" && <RemoveNomineeButton assistance={assistance} nominee={n} />}
    </>
  );
  const rowHasActions = (n: Nominee) =>
    (abilities.approve && n.status === "NOMINATED") ||
    (abilities.deliver && n.status === "APPROVED" && !n.active_delivery) ||
    (abilities.reverse && !!n.active_delivery) ||
    (canNominate && n.status === "NOMINATED");

  const who = (n: Nominee, linked = true) =>
    n.person ? (
      linked ? (
        <Link
          href={`/people/${encodeURIComponent(n.person.person_code)}`}
          className="rounded-sm font-semibold text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
        >
          {n.person.full_name}
        </Link>
      ) : (
        <span className="font-semibold text-foreground">{n.person.full_name}</span>
      )
    ) : (
      <span className="font-semibold text-foreground">{n.family.household_head_name ?? FAMILY_TARGET_LABEL}</span>
    );

  const selectBox = (n: Nominee) =>
    abilities.approve && n.status === "NOMINATED" ? (
      <input
        type="checkbox"
        aria-label={`تحديد ${n.person?.full_name ?? n.family.family_code}`}
        className="size-4 accent-brand-700"
        checked={selected.has(n.id)}
        onChange={() => toggle(n.id)}
      />
    ) : null;

  return (
    <div className="flex flex-col gap-4">
      {/* Nomination breakdown: server-derived summary, never a delivery count. */}
      <AppCard padded={false} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-6 sm:px-5" data-nominee-summary>
        <div className="flex items-center gap-3">
          <IconBox icon={Users} size="sm" />
          <div className="flex items-baseline gap-2">
            {data ? (
              <bdi className="text-xl font-bold tabular-nums">{fmt(data.summary.total)}</bdi>
            ) : (
              <Skeleton className="h-6 w-8" />
            )}
            <span className="text-[13px] text-muted-foreground">إجمالي المرشحين</span>
          </div>
        </div>
        <div className="hidden h-6 w-px bg-stroke-subtle sm:block" aria-hidden />
        <dl className="flex flex-wrap items-center gap-x-5 gap-y-1 text-[13px]">
          {COUNTERS.filter((c) => c.key !== "total").map((c) => (
            <div key={c.key} className="flex items-center gap-1.5" data-counter={c.key}>
              <dt className="text-muted-foreground">{c.label}</dt>
              <dd className="font-semibold tabular-nums text-foreground">{data ? fmt(data.summary[c.key]) : "…"}</dd>
            </div>
          ))}
        </dl>
      </AppCard>

      <AppCard padded={false} className="overflow-hidden" aria-labelledby="nominees-title">
        <div className="flex flex-col gap-3 px-4 pt-4 pb-3 sm:flex-row sm:items-start sm:justify-between sm:px-5">
          <SectionHeader
            title={<span id="nominees-title">{external ? "المرشحون والاعتماد" : "المرشحون والتسليم"}</span>}
            description={
              <>
                المرشح مستفيد محتمل فقط — الترشيح ليس إثباتًا لاستلام المساعدة.
                {!canNominate && assistance.status === "DRAFT" && " افتح المساعدة لإضافة مرشحين."}
              </>
            }
          />
          {(canNominate || abilities.approve) && (
            <div className="flex shrink-0 flex-wrap items-start gap-2" data-nominee-actions>
              {canNominate && <ManualNomineeDialog assistance={assistance} />}
              {canNominate && <NeedsNomineeDialog assistance={assistance} />}
              {abilities.approve && (
                <BulkApproveButton assistance={assistance} selected={[...selected]} onDone={() => setSelected(new Set())} />
              )}
            </div>
          )}
        </div>

        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-stroke-subtle bg-surface-2 px-4 py-2 text-[13px] sm:px-5">
          <label className="flex items-center gap-2 text-muted-foreground">
            <input
              type="checkbox"
              className="size-4 accent-brand-700"
              checked={includeRemoved}
              onChange={(e) => {
                setIncludeRemoved(e.target.checked);
                setPage(1);
              }}
            />
            إظهار الترشيحات المُزالة ({fmt(data?.summary.removed ?? 0)})
          </label>
          {abilities.approve && approvable.length > 0 && (
            <label className="flex items-center gap-2 text-muted-foreground lg:hidden">
              <input
                type="checkbox"
                className="size-4 accent-brand-700"
                checked={approvable.every((n) => selected.has(n.id))}
                onChange={(e) => {
                  const next = new Set(selected);
                  for (const n of approvable) {
                    if (e.target.checked) next.add(n.id);
                    else next.delete(n.id);
                  }
                  setSelected(next);
                }}
              />
              تحديد مرشحي هذه الصفحة
            </label>
          )}
        </div>

        {isLoading ? (
          <div className="border-t border-stroke-subtle p-4">
            <Skeleton className="h-24" />
          </div>
        ) : nominees.length === 0 ? (
          <EmptyState
            icon={Users}
            title="لا يوجد مرشحون بعد"
            description={
              canNominate
                ? "أضف مرشحين من تبويب الاستهداف، أو يدويًا، أو من الاحتياجات المفتوحة."
                : assistance.status === "DRAFT"
                  ? "يُضاف المرشحون بعد فتح المساعدة."
                  : undefined
            }
            className="border-t border-stroke-subtle"
          />
        ) : (
          <>
            {/* Desktop (≥ lg): semantic table */}
            <Table className="hidden border-t border-stroke-subtle lg:table">
              <TableHeader className="bg-surface-1">
                <TableRow className="border-stroke-subtle hover:bg-transparent">
                  {abilities.approve && (
                    <TableHead className="w-0 ps-5">
                      <input
                        type="checkbox"
                        aria-label="تحديد المرشحين في هذه الصفحة"
                        className="size-4 accent-brand-700"
                        disabled={approvable.length === 0}
                        checked={approvable.length > 0 && approvable.every((n) => selected.has(n.id))}
                        onChange={(e) => {
                          const next = new Set(selected);
                          for (const n of approvable) {
                            if (e.target.checked) next.add(n.id);
                            else next.delete(n.id);
                          }
                          setSelected(next);
                        }}
                      />
                    </TableHead>
                  )}
                  <TableHead className={`${head} ${abilities.approve ? "" : "ps-5"}`}>المرشح</TableHead>
                  <TableHead className={head}>المصدر</TableHead>
                  <TableHead className={head}>الحالة</TableHead>
                  <TableHead className={head}>{external ? "الكشوف" : "التسليم"}</TableHead>
                  {hasActions && (
                    <TableHead className={`${head} pe-5`}>
                      <span className="sr-only">الإجراءات</span>
                    </TableHead>
                  )}
                </TableRow>
              </TableHeader>
              <TableBody>
                {nominees.map((n) => (
                  <TableRow
                    key={n.id}
                    data-nominee-id={n.id}
                    data-nominee-status={n.status}
                    className={cn("border-stroke-subtle align-top", n.status === "REMOVED" && "opacity-70")}
                  >
                    {abilities.approve && <TableCell className="ps-5 pt-4">{selectBox(n)}</TableCell>}
                    <TableCell className={cn("max-w-72 py-3", !abilities.approve && "ps-5")}>
                      <div className="flex min-w-0 flex-col gap-0.5">
                        {who(n)}
                        <span className="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                          <Link
                            href={`/families/${encodeURIComponent(n.family.family_code)}`}
                            className="rounded-sm font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                            data-family-link
                          >
                            <Code>{n.family.family_code}</Code>
                          </Link>
                          <span aria-hidden>·</span>
                          <span>{n.person ? "فرد محدد" : "الأسرة كاملة"}</span>
                        </span>
                      </div>
                    </TableCell>
                    <TableCell className="max-w-56 py-3">
                      <div className="flex flex-col items-start gap-1">
                        <NominationSourceTag source={n.nomination_source} />
                        {n.source_need && (
                          <Link
                            href={`/needs/${n.source_need.id}`}
                            className="inline-flex max-w-full items-center gap-1 truncate rounded-sm text-xs text-foreground hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                          >
                            {n.source_need.title}
                            <NeedPriorityTag priority={n.source_need.priority} />
                          </Link>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="py-3">
                      <div className="flex flex-col items-start gap-1">
                        <NomineeStatusTag status={n.status} />
                        <span className="text-xs text-muted-foreground">
                          {n.nominated_by?.name ?? "—"} · <time dateTime={n.nominated_at}>{formatDateTime(n.nominated_at)}</time>
                        </span>
                      </div>
                    </TableCell>
                    <TableCell className="max-w-64 py-3">
                      <ExecutionState assistance={assistance} nominee={n} />
                    </TableCell>
                    {hasActions && (
                      <TableCell className="pe-5 py-2.5">
                        <div className="flex flex-wrap items-center justify-end gap-1">{actions(n)}</div>
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            {/* Tablet and phone (< lg): compact work items */}
            <ul className="divide-y divide-stroke-subtle border-t border-stroke-subtle lg:hidden" aria-label="قائمة المرشحين">
              {nominees.map((n) => (
                <li
                  key={n.id}
                  className={cn("flex gap-3 px-4 py-3 sm:px-5", n.status === "REMOVED" && "opacity-70")}
                  data-nominee-id={n.id}
                >
                  {abilities.approve && <div className="pt-1">{selectBox(n)}</div>}
                  <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                    <div className="flex items-start justify-between gap-2">
                      <span className="min-w-0">{who(n)}</span>
                      <NomineeStatusTag status={n.status} />
                    </div>
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                      <Link
                        href={`/families/${encodeURIComponent(n.family.family_code)}`}
                        className="rounded-sm font-medium text-brand-800 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                      >
                        <Code>{n.family.family_code}</Code>
                      </Link>
                      <span aria-hidden>·</span>
                      <span>{n.person ? "فرد محدد" : "الأسرة كاملة"}</span>
                      <span aria-hidden>·</span>
                      <NominationSourceTag source={n.nomination_source} />
                    </span>
                    {n.status !== "NOMINATED" && n.status !== "REMOVED" && (
                      <div className="text-xs">
                        <ExecutionState assistance={assistance} nominee={n} />
                      </div>
                    )}
                    {rowHasActions(n) && <div className="flex flex-wrap items-center gap-1 pt-0.5">{actions(n)}</div>}
                  </div>
                </li>
              ))}
            </ul>
          </>
        )}

        {data && nominees.length > 0 && (
          <div className="border-t border-stroke-subtle">
            <RegistryPagination meta={data.meta} onPage={setPage} unit="مرشحين" />
          </div>
        )}
      </AppCard>
    </div>
  );
}
