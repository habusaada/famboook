"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { Check, CheckCheck, ListChecks, Search, UserMinus, UserPlus, Users } from "lucide-react";
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
import { NominationSourceBadge } from "@/components/assistances/assistance-badges";
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
import { nomineeStatusLabels } from "@/lib/utils/assistance";
import { FAMILY_TARGET_LABEL, NEED_PRIORITIES, needPriorityLabels } from "@/lib/utils/need";

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
    <Button variant="ghost" size="sm" disabled={mutation.isPending} onClick={() => mutation.mutate(nominee.id)}>
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

  if (isError) {
    return (
      <p className="rounded-lg border border-dashed p-12 text-center text-sm text-muted-foreground">
        {error instanceof ApiError && error.status === 403 ? "لا تملك صلاحية عرض المرشحين." : "تعذّر تحميل المرشحين."}
      </p>
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

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        {COUNTERS.map((c) => (
          <Card key={c.key} size="sm" data-counter={c.key}>
            <CardHeader className="pb-1">
              <CardDescription>{c.label}</CardDescription>
              <CardTitle className="text-2xl font-semibold tabular-nums">
                {data ? data.summary[c.key] : <Skeleton className="h-7 w-8" />}
              </CardTitle>
            </CardHeader>
          </Card>
        ))}
      </div>

      <Card size="sm">
        <CardHeader>
          <CardTitle>المرشحون</CardTitle>
          <CardDescription>
            المرشح مستفيد محتمل فقط — الترشيح ليس إثباتًا لاستلام المساعدة.
            {!canNominate && assistance.status === "DRAFT" && " افتح المساعدة لإضافة مرشحين."}
          </CardDescription>
          {(canNominate || abilities.approve) && (
            <CardAction className="flex flex-wrap items-start gap-2">
              {abilities.approve && (
                <BulkApproveButton assistance={assistance} selected={[...selected]} onDone={() => setSelected(new Set())} />
              )}
              {canNominate && <ManualNomineeDialog assistance={assistance} />}
              {canNominate && <NeedsNomineeDialog assistance={assistance} />}
            </CardAction>
          )}
        </CardHeader>
        <CardContent className="flex flex-col gap-3 p-0">
          <label className="flex w-fit items-center gap-2 px-4 text-sm text-muted-foreground">
            <input
              type="checkbox"
              className="size-4 accent-primary"
              checked={includeRemoved}
              onChange={(e) => {
                setIncludeRemoved(e.target.checked);
                setPage(1);
              }}
            />
            إظهار الترشيحات المُزالة ({data?.summary.removed ?? 0})
          </label>

          {isLoading ? (
            <Skeleton className="mx-4 mb-4 h-24" />
          ) : nominees.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 border-t p-12 text-center">
              <Users className="size-8 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">لا يوجد مرشحون بعد.</p>
            </div>
          ) : (
            <div className="overflow-x-auto border-t">
              <Table>
                <TableHeader>
                  <TableRow>
                    {abilities.approve && (
                      <TableHead className="w-0">
                        <input
                          type="checkbox"
                          aria-label="تحديد المرشحين في هذه الصفحة"
                          className="size-4 accent-primary"
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
                    <TableHead>المرشح</TableHead>
                    <TableHead>الأسرة</TableHead>
                    <TableHead>نوع الترشيح</TableHead>
                    <TableHead>المصدر</TableHead>
                    <TableHead>الاحتياج المرتبط</TableHead>
                    <TableHead>بواسطة</TableHead>
                    <TableHead>التاريخ</TableHead>
                    <TableHead>الحالة</TableHead>
                    <TableHead>{assistance.execution_mode === "EXTERNAL" ? "الكشوف" : "التسليم"}</TableHead>
                    {hasActions && <TableHead className="w-0" />}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {nominees.map((n) => (
                    <TableRow key={n.id} data-nominee-id={n.id} className={n.status === "REMOVED" ? "opacity-60" : undefined}>
                      {abilities.approve && (
                        <TableCell>
                          {n.status === "NOMINATED" && (
                            <input
                              type="checkbox"
                              aria-label={`تحديد ${n.person?.full_name ?? n.family.family_code}`}
                              className="size-4 accent-primary"
                              checked={selected.has(n.id)}
                              onChange={() => toggle(n.id)}
                            />
                          )}
                        </TableCell>
                      )}
                      <TableCell className="font-medium">
                        {n.person ? (
                          <Link href={`/people/${encodeURIComponent(n.person.person_code)}`} className="hover:underline">
                            {n.person.full_name}
                          </Link>
                        ) : (
                          n.family.household_head_name ?? FAMILY_TARGET_LABEL
                        )}
                      </TableCell>
                      <TableCell>
                        <Link href={`/families/${encodeURIComponent(n.family.family_code)}`} className="hover:underline" dir="ltr">
                          {n.family.family_code}
                        </Link>
                      </TableCell>
                      <TableCell>{n.person ? "فرد محدد" : "الأسرة كاملة"}</TableCell>
                      <TableCell>
                        <NominationSourceBadge source={n.nomination_source} />
                      </TableCell>
                      <TableCell>
                        {n.source_need ? (
                          <Link href={`/needs/${n.source_need.id}`} className="inline-flex items-center gap-1 hover:underline">
                            {n.source_need.title}
                            <NeedPriorityBadge priority={n.source_need.priority} />
                          </Link>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell>{n.nominated_by?.name ?? "—"}</TableCell>
                      <TableCell className="text-xs text-muted-foreground">{formatDateTime(n.nominated_at)}</TableCell>
                      <TableCell>
                        <Badge variant={n.status === "APPROVED" ? "default" : n.status === "NOMINATED" ? "secondary" : "outline"}>
                          {nomineeStatusLabels[n.status]}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <ExecutionState assistance={assistance} nominee={n} />
                      </TableCell>
                      {hasActions && (
                        <TableCell>
                          <div className="flex items-center justify-end gap-1">
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
                            {abilities.reverse && n.active_delivery && (
                              <ReverseDeliveryDialog assistance={assistance} deliveryId={n.active_delivery.id} />
                            )}
                            {canNominate && n.status === "NOMINATED" && <RemoveNomineeButton assistance={assistance} nominee={n} />}
                          </div>
                        </TableCell>
                      )}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}

          {data && data.meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-2 border-t p-3 text-xs text-muted-foreground">
              <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                السابق
              </Button>
              <span>
                صفحة {data.meta.current_page} من {data.meta.last_page}
              </span>
              <Button type="button" variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>
                التالي
              </Button>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
