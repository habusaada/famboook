"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { AlertCircle, CircleCheck, Loader2, MapPinned, Search, ShieldOff, UserCheck, UserRound, UsersRound } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { Code, PageHeader, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { CoordinatorScopeForm } from "@/components/administration/coordinators/coordinator-scope-form";
import {
  coordinatorErrorMessage,
  refreshCoordinatorAfterRefusal,
  useGrantCoordinator,
  usePersonCoordinator,
  useRevokeCoordinator,
  useRevokeCoordinatorScope,
} from "@/lib/api/coordinator-admin";
import { usePeople } from "@/lib/api/people";
import type { CoordinatorAssignment, CoordinatorRevokeReason, PersonCoordinatorState } from "@/lib/types/api/coordinator-admin";
import type { PersonSummary } from "@/lib/types/api/person";
import { formatDateTime } from "@/lib/utils/date";
import { COORDINATOR_REVOKE_REASONS, coordinatorRevokeReasonLabels, coordinatorScopeTarget, coordinatorScopeTypeLabels } from "@/lib/utils/coordinator-admin";

type Selected = { personCode: string; name: string; familyCode: string | null };

function When({ iso }: { iso: string | null }) {
  if (!iso) return <span className="text-subtle-foreground">—</span>;
  return (
    <bdi dir="ltr" className="tabular-nums">
      {formatDateTime(iso)}
    </bdi>
  );
}

// ------------------------------------------------------------------ search

/**
 * Finds a household head on the existing People search (person code or
 * name). The API has no "heads only" filter: rows are filtered here for
 * selection only — the server decides eligibility when the role is granted.
 */
function PersonSearch({ onSelect }: { onSelect: (person: Selected) => void }) {
  const [draft, setDraft] = useState("");
  const [term, setTerm] = useState("");
  const people = usePeople({ search: term, page: 1 });
  const rows: PersonSummary[] = (people.data?.data ?? []).filter((p) => p.family?.is_household_head);

  return (
    <AppCard className="flex flex-col gap-4" data-coordinator-search>
      <SectionHeader title="اختيار رب الأسرة" description="ابحث بالاسم أو رمز الشخص. يُعرض أرباب الأسر فقط؛ يتحقق الخادم من الأهلية عند التعيين." />
      <form
        className="flex flex-col gap-2 sm:flex-row"
        onSubmit={(event) => {
          event.preventDefault();
          setTerm(draft.trim());
        }}
      >
        <Label htmlFor="coordinator-search" className="sr-only">
          البحث عن رب أسرة
        </Label>
        <Input id="coordinator-search" value={draft} onChange={(e) => setDraft(e.target.value)} placeholder="اسم رب الأسرة أو رمز الشخص" className="sm:flex-1" />
        <Button type="submit" variant="outline" disabled={draft.trim().length < 2}>
          <Search className="size-4" aria-hidden />
          بحث
        </Button>
      </form>

      {term === "" ? null : people.isPending ? (
        <div className="flex flex-col gap-2" data-coordinator-search-loading>
          <p role="status" className="sr-only">
            جارٍ البحث
          </p>
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : people.isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertDescription>{coordinatorErrorMessage(people.error)}</AlertDescription>
        </Alert>
      ) : rows.length === 0 ? (
        <EmptyState icon={UsersRound} title="لا يوجد أرباب أسر مطابقون" description="جرّب اسمًا أو رمزًا آخر." />
      ) : (
        <ul className="divide-y divide-stroke-subtle rounded-lg border border-stroke-subtle" aria-label="نتائج البحث" data-coordinator-results>
          {rows.map((p) => (
            <li key={p.person_code} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
              <div className="flex min-w-0 flex-col">
                <span className="truncate font-medium text-foreground">{p.full_name}</span>
                <span className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                  <Code>{p.person_code}</Code>
                  {p.family && (
                    <span>
                      الأسرة <Code>{p.family.family_code}</Code>
                      {p.family.branch_name ? ` · ${p.family.branch_name}` : ""}
                    </span>
                  )}
                </span>
              </div>
              <Button size="sm" variant="outline" onClick={() => onSelect({ personCode: p.person_code, name: p.full_name, familyCode: p.family?.family_code ?? null })}>
                اختيار
              </Button>
            </li>
          ))}
        </ul>
      )}
    </AppCard>
  );
}

// ------------------------------------------------------------------ dialogs

function ReasonSelect({ id, value, onChange }: { id: string; value: CoordinatorRevokeReason | ""; onChange: (r: CoordinatorRevokeReason) => void }) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor={id}>سبب الإلغاء</Label>
      <Select value={value === "" ? undefined : value} onValueChange={(v) => onChange(v as CoordinatorRevokeReason)}>
        <SelectTrigger id={id} className="w-full">
          <SelectValue placeholder="اختر السبب" />
        </SelectTrigger>
        <SelectContent>
          {COORDINATOR_REVOKE_REASONS.map((r) => (
            <SelectItem key={r} value={r}>
              {coordinatorRevokeReasonLabels[r]}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

function GrantDialog({ person, onDone }: { person: Selected; onDone: (message: string) => void }) {
  const queryClient = useQueryClient();
  const grant = useGrantCoordinator(person.personCode);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);

  return (
    <>
      <Button onClick={() => (setError(null), setOpen(true))} data-action="grant-coordinator">
        <UserCheck className="size-4" aria-hidden />
        تعيين منسقًا
      </Button>
      <Dialog open={open} onOpenChange={(next) => !grant.isPending && setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>تعيين منسق</DialogTitle>
            <DialogDescription>
              سيُمنح حساب بوابة الأسرة الخاص بـ «{person.name}» دور المنسق. لا تُفتح مساحة التنسيق إلا بعد إضافة نطاق تنظيمي واحد على الأقل.
            </DialogDescription>
          </DialogHeader>
          {error && (
            <Alert variant="destructive" data-dialog-error>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={grant.isPending}>
              إلغاء
            </Button>
            <Button
              onClick={() =>
                grant.mutate(undefined, {
                  onSuccess: () => {
                    setOpen(false);
                    onDone("أُسند دور المنسق. أضف الآن نطاقًا تنظيميًا.");
                  },
                  onError: (e) => {
                    setError(coordinatorErrorMessage(e));
                    refreshCoordinatorAfterRefusal(queryClient, person.personCode, e);
                  },
                })
              }
              disabled={grant.isPending}
              data-confirm="grant-coordinator"
            >
              {grant.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />}
              تأكيد التعيين
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function RevokeRoleDialog({ person, onDone }: { person: Selected; onDone: (message: string) => void }) {
  const queryClient = useQueryClient();
  const revoke = useRevokeCoordinator(person.personCode);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState<CoordinatorRevokeReason | "">("");
  const [error, setError] = useState<string | null>(null);

  return (
    <>
      <Button variant="outline" className="text-danger hover:text-danger" onClick={() => (setError(null), setReason(""), setOpen(true))} data-action="revoke-coordinator">
        <ShieldOff className="size-4" aria-hidden />
        إلغاء دور المنسق
      </Button>
      <Dialog open={open} onOpenChange={(next) => !revoke.isPending && setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>إلغاء دور المنسق</DialogTitle>
            <DialogDescription>سيُلغى دور المنسق عن «{person.name}».</DialogDescription>
          </DialogHeader>
          <Alert data-revoke-warning>
            <AlertCircle className="size-4" />
            <AlertDescription>
              ستُغلق مساحة التنسيق لهذا الحساب فورًا، وتُلغى جميع نطاقاته النشطة. يبقى حسابه في بوابة الأسرة كرب أسرة كما هو.
            </AlertDescription>
          </Alert>
          <ReasonSelect id="revoke-role-reason" value={reason} onChange={setReason} />
          {error && (
            <Alert variant="destructive" data-dialog-error>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={revoke.isPending}>
              تراجع
            </Button>
            <Button
              variant="destructive"
              disabled={reason === "" || revoke.isPending}
              onClick={() =>
                reason !== "" &&
                revoke.mutate(reason, {
                  onSuccess: () => {
                    setOpen(false);
                    onDone("أُلغي دور المنسق وجميع نطاقاته النشطة.");
                  },
                  onError: (e) => {
                    setError(coordinatorErrorMessage(e));
                    refreshCoordinatorAfterRefusal(queryClient, person.personCode, e);
                  },
                })
              }
              data-confirm="revoke-coordinator"
            >
              {revoke.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />}
              تأكيد الإلغاء
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function RevokeScopeDialog({ personCode, assignment, onDone }: { personCode: string; assignment: CoordinatorAssignment; onDone: (message: string) => void }) {
  const queryClient = useQueryClient();
  const revoke = useRevokeCoordinatorScope(personCode);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState<CoordinatorRevokeReason | "">("");
  const [error, setError] = useState<string | null>(null);
  const target = coordinatorScopeTarget(assignment);

  return (
    <>
      <Button size="sm" variant="ghost" className="text-danger hover:text-danger" onClick={() => (setError(null), setReason(""), setOpen(true))} aria-label={`إلغاء النطاق ${target}`} data-action="revoke-scope">
        إلغاء
      </Button>
      <Dialog open={open} onOpenChange={(next) => !revoke.isPending && setOpen(next)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>إلغاء نطاق تنظيمي</DialogTitle>
            <DialogDescription>
              سيُلغى النطاق «{coordinatorScopeTypeLabels[assignment.scope_type]}: {target}». يبقى التعيين في السجل كتاريخ.
            </DialogDescription>
          </DialogHeader>
          <ReasonSelect id="revoke-scope-reason" value={reason} onChange={setReason} />
          {error && (
            <Alert variant="destructive" data-dialog-error>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={revoke.isPending}>
              تراجع
            </Button>
            <Button
              variant="destructive"
              disabled={reason === "" || revoke.isPending}
              onClick={() =>
                reason !== "" &&
                revoke.mutate(
                  { id: assignment.id, reason },
                  {
                    onSuccess: () => {
                      setOpen(false);
                      onDone("أُلغي النطاق.");
                    },
                    onError: (e) => {
                      setError(coordinatorErrorMessage(e));
                      refreshCoordinatorAfterRefusal(queryClient, personCode, e);
                    },
                  }
                )
              }
              data-confirm="revoke-scope"
            >
              {revoke.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />}
              تأكيد الإلغاء
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

// ------------------------------------------------------------------ overview

function AssignmentRow({ personCode, assignment, onDone }: { personCode: string; assignment: CoordinatorAssignment; onDone: (m: string) => void }) {
  return (
    <li className="flex flex-col gap-1.5 px-3 py-3 sm:flex-row sm:items-start sm:justify-between" data-assignment={assignment.active ? "active" : "revoked"}>
      <div className="flex min-w-0 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge tone={assignment.active ? "success" : "neutral"}>{assignment.active ? "نشط" : "ملغى"}</StatusBadge>
          <span className="text-xs font-medium text-muted-foreground">{coordinatorScopeTypeLabels[assignment.scope_type]}</span>
        </div>
        <span className="font-medium text-foreground">{coordinatorScopeTarget(assignment)}</span>
        <dl className="flex flex-col gap-0.5 text-xs text-muted-foreground">
          <div className="flex flex-wrap gap-x-1.5">
            <dt>عُيّن:</dt>
            <dd>
              <When iso={assignment.assigned_at} />
              {assignment.assigned_by ? ` · ${assignment.assigned_by}` : ""}
            </dd>
          </div>
          {!assignment.active && (
            <div className="flex flex-wrap gap-x-1.5">
              <dt>أُلغي:</dt>
              <dd>
                <When iso={assignment.revoked_at} />
                {assignment.revoked_by ? ` · ${assignment.revoked_by}` : ""}
                {assignment.revoke_reason ? ` · ${coordinatorRevokeReasonLabels[assignment.revoke_reason]}` : ""}
              </dd>
            </div>
          )}
        </dl>
      </div>
      {assignment.active && <RevokeScopeDialog personCode={personCode} assignment={assignment} onDone={onDone} />}
    </li>
  );
}

function CoordinatorOverview({ person, state, onNotice }: { person: Selected; state: PersonCoordinatorState; onNotice: (m: string) => void }) {
  const active = state.assignments.filter((a) => a.active);
  const revoked = state.assignments.filter((a) => !a.active);

  return (
    <div className="flex flex-col gap-4">
      <AppCard className="flex flex-col gap-4" data-coordinator-overview>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex min-w-0 flex-col gap-1">
            <h2 className="flex items-center gap-2 text-lg font-semibold text-foreground">
              <UserRound className="size-5 text-muted-foreground" aria-hidden />
              {person.name}
            </h2>
            <span className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
              <Code>{state.person_code}</Code>
              {person.familyCode && (
                <span>
                  الأسرة <Code>{person.familyCode}</Code>
                </span>
              )}
            </span>
          </div>
          <div className="flex flex-wrap gap-2" data-coordinator-status>
            <StatusBadge tone={state.coordinator ? "success" : "neutral"}>{state.coordinator ? "منسق" : "غير منسق"}</StatusBadge>
            {state.coordinator && (
              <StatusBadge tone={state.coordinator_space ? "success" : "warning"}>{state.coordinator_space ? "مساحة التنسيق مفعّلة" : "مساحة التنسيق غير مفعّلة"}</StatusBadge>
            )}
          </div>
        </div>

        {!state.has_family_account ? (
          <Alert data-no-family-account>
            <AlertCircle className="size-4" />
            <AlertTitle>لا يوجد حساب بوابة أسرة</AlertTitle>
            <AlertDescription>لا يمكن تعيين هذا الشخص منسقًا قبل أن يفعّل حسابه في بوابة الأسرة كرب أسرة.</AlertDescription>
          </Alert>
        ) : state.coordinator && !state.coordinator_space ? (
          <p className="text-sm text-muted-foreground">
            {active.length === 0 ? "أضف نطاقًا تنظيميًا واحدًا على الأقل لتفعيل مساحة التنسيق." : "مساحة التنسيق غير مفعّلة: قد يكون النطاق غير نشط أو لم يعد صاحب الحساب رب أسرة مؤهلًا."}
          </p>
        ) : null}

        <div className="flex flex-wrap gap-2">
          {state.has_family_account && !state.coordinator && <GrantDialog person={person} onDone={onNotice} />}
          {state.coordinator && <RevokeRoleDialog person={person} onDone={onNotice} />}
        </div>
      </AppCard>

      {state.coordinator && (
        <AppCard className="flex flex-col gap-4" data-coordinator-assign>
          <SectionHeader title="إضافة نطاق تنظيمي" description="العشيرة كاملة، أو مجموعة فروع، أو فرع محدد." />
          <CoordinatorScopeForm personCode={person.personCode} assignments={state.assignments} onAssigned={onNotice} />
        </AppCard>
      )}

      <AppCard className="flex flex-col gap-3" data-coordinator-assignments>
        <SectionHeader title="النطاقات التنظيمية" description={`${active.length.toLocaleString("ar")} نشط · ${revoked.length.toLocaleString("ar")} ملغى`} />
        {state.assignments.length === 0 ? (
          <EmptyState icon={MapPinned} title="لا توجد نطاقات" description="لم يُعيَّن لهذا الشخص أي نطاق تنظيمي." />
        ) : (
          <ul className="divide-y divide-stroke-subtle rounded-lg border border-stroke-subtle">
            {[...active, ...revoked].map((a) => (
              <AssignmentRow key={a.id} personCode={person.personCode} assignment={a} onDone={onNotice} />
            ))}
          </ul>
        )}
      </AppCard>
    </div>
  );
}

function SelectedCoordinator({ person, onNotice }: { person: Selected; onNotice: (m: string) => void }) {
  const query = usePersonCoordinator(person.personCode);

  if (query.isPending) {
    return (
      <div className="flex flex-col gap-3" data-coordinator-loading>
        <p role="status" className="sr-only">
          جارٍ تحميل حالة المنسق
        </p>
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-40 w-full" />
      </div>
    );
  }
  if (query.isError) {
    return (
      <Alert variant="destructive" data-coordinator-error>
        <AlertCircle className="size-4" />
        <AlertTitle>تعذّر تحميل حالة المنسق</AlertTitle>
        <AlertDescription className="flex flex-col items-start gap-2">
          {coordinatorErrorMessage(query.error)}
          <Button size="sm" variant="outline" onClick={() => void query.refetch()}>
            إعادة المحاولة
          </Button>
        </AlertDescription>
      </Alert>
    );
  }

  return <CoordinatorOverview person={person} state={query.data.data} onNotice={onNotice} />;
}

/**
 * «إدارة المنسقين» (CO-01B): Staff search an eligible household head, grant
 * or revoke the existing COORDINATOR role and manage the organizational
 * scopes, entirely on the existing Staff coordinator API. Gated by
 * coordinator-scope.manage in the page; the routes and Domain Actions are
 * the authority.
 */
export function CoordinatorAdmin() {
  const [selected, setSelected] = useState<Selected | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  return (
    <div className="flex flex-col gap-5" data-coordinator-admin>
      <PageHeader title="إدارة المنسقين" description="تعيين أرباب الأسر منسقين وإدارة نطاقاتهم التنظيمية: العشيرة، مجموعة الفروع أو الفرع." />
      <PersonSearch
        onSelect={(person) => {
          setNotice(null);
          setSelected(person);
        }}
      />
      {notice && (
        <Alert data-coordinator-notice>
          <CircleCheck className="size-4" />
          <AlertDescription>{notice}</AlertDescription>
        </Alert>
      )}
      {selected ? (
        <SelectedCoordinator key={selected.personCode} person={selected} onNotice={setNotice} />
      ) : (
        <EmptyState icon={UsersRound} title="اختر رب أسرة" description="ابحث عن رب الأسرة ثم اختره لعرض حالته كمنسق ونطاقاته." />
      )}
    </div>
  );
}
