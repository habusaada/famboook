"use client";

import { useState } from "react";
import { AlertCircle, CircleCheck, History, KeyRound, Loader2, Search, ShieldCheck, UserRound, UsersRound } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyState } from "@/components/shared/empty-state";
import { Code, DetailItem, DetailList, PageHeader, Panel, SectionHeader } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatusBadge, type StatusTone } from "@/components/shared/status-badge";
import { CivilRegistryNav, LoadingBlock, MonitorError } from "@/components/administration/civil-registry/parts";
import { gazaTime, roleLabel } from "@/components/civil-lookup/labels";
import {
  civilLookupAdminErrorMessage,
  civilLookupErrorCode,
  useCivilLookupAccount,
  useCivilLookupChanges,
  useCivilLookupStaffAccounts,
  useCivilLookupUserAccess,
  useSetCivilLookupUserAccess,
  type CivilLookupAccessTarget,
} from "@/lib/api/civil-lookup";
import { usePeople } from "@/lib/api/people";
import type {
  CivilLookupAccessValue,
  CivilLookupAccountSide,
  CivilLookupAccountState,
  CivilLookupContext,
  CivilLookupSide,
} from "@/lib/types/api/civil-lookup";

// FB-CIVIL-02D — Administration → Civil Registry → Lookup access
// (docs/adr/FB-CIVIL-02A-CIVIL-REGISTRY-LOOKUP.md §L-3, 02B §B-4). Individual
// overrides only: REVOKED blocks a user on every side, GRANTED is the explicit
// per-side grant a family-side account needs, DEFAULT removes the override.
// The editor starts from the state the server resolves, always sends the
// full set of sides (GRANTED replaces it) and the state it loaded, so another
// administrator's change is refused (409), never overwritten. Everything here
// is UX: the API authorizes, validates and records every change.

const SIDES: CivilLookupSide[] = ["FAMILY", "COORDINATOR"];

const SIDE_LABELS: Record<CivilLookupSide, string> = { FAMILY: "بوابة الأسرة", COORDINATOR: "مساحة التنسيق" };

const ACCESS_LABELS: Record<CivilLookupAccessValue, string> = {
  GRANTED: "منح فردي صريح",
  REVOKED: "إيقاف صريح",
  DEFAULT: "بدون تجاوز فردي",
};

const ACCESS_TONES: Record<CivilLookupAccessValue, StatusTone> = { GRANTED: "success", REVOKED: "danger", DEFAULT: "neutral" };

const ACCOUNT_LABELS: Record<CivilLookupAccountSide, string> = {
  STAFF: "حساب موظف",
  FAMILY: "حساب بوابة الأسرة",
  INVALID: "حساب بأدوار متعارضة",
  NONE: "حساب بلا دور",
};

const CONTEXT_LABELS: Record<CivilLookupContext, string> = { STAFF: "بوابة الموظفين", FAMILY: "بوابة الأسرة", COORDINATOR: "مساحة التنسيق" };

const REASON_LABELS: Record<string, string> = {
  LOOKUP_DISABLED: "الخدمة أو الجهة غير مفعّلة",
  LOOKUP_NOT_ALLOWED: "لا تتوفر الصلاحية أو المنح أو سياسة الدور",
};

const LINK_LABELS: Record<string, string> = { ACTIVE: "نشط", SUSPENDED: "موقوف" };

const sidesText = (sides: CivilLookupSide[]) => (sides.length === 0 ? "—" : sides.map((s) => SIDE_LABELS[s]).join("، "));

/** The canonical order of the API (FAMILY, COORDINATOR). */
const ordered = (sides: CivilLookupSide[]) => SIDES.filter((s) => sides.includes(s));

type AccessState = { access: CivilLookupAccessValue; sides: CivilLookupSide[] };

const stateText = (s: AccessState) => (s.access === "GRANTED" ? `${ACCESS_LABELS.GRANTED} (${sidesText(s.sides)})` : ACCESS_LABELS[s.access]);

const sameState = (a: AccessState, b: AccessState) => a.access === b.access && ordered(a.sides).join() === ordered(b.sides).join();

function AccessBadge({ access }: { access: CivilLookupAccessValue }) {
  return (
    <span className="inline-flex" data-access-state={access}>
      <StatusBadge tone={ACCESS_TONES[access]}>{ACCESS_LABELS[access]}</StatusBadge>
    </span>
  );
}

function When({ iso }: { iso: string | null | undefined }) {
  return <span className="tabular-nums">{gazaTime(iso)}</span>;
}

// ------------------------------------------------------------------ account search

/**
 * A family-side account is found through its Person (People search by name
 * or person code). Being a household head does not mean having an account:
 * the server resolves the linked account when a Person is selected.
 */
function FamilyAccountSearch({ onSelect }: { onSelect: (target: CivilLookupAccessTarget) => void }) {
  const [draft, setDraft] = useState("");
  const [term, setTerm] = useState("");
  const people = usePeople({ search: term, page: 1 });
  const rows = term === "" ? [] : (people.data?.data ?? []);

  return (
    <div className="flex flex-col gap-3" data-family-search>
      <form
        className="flex flex-col gap-2 sm:flex-row"
        onSubmit={(e) => {
          e.preventDefault();
          setTerm(draft.trim());
        }}
      >
        <Label htmlFor="lookup-access-family-search" className="sr-only">
          البحث عن شخص بالاسم أو رمز الشخص
        </Label>
        <Input
          id="lookup-access-family-search"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          placeholder="الاسم الكامل أو رمز الشخص"
          autoComplete="off"
          className="sm:flex-1"
        />
        <Button type="submit" variant="outline" disabled={draft.trim().length < 2}>
          <Search className="size-4" aria-hidden />
          بحث
        </Button>
      </form>
      <p className="text-[13px] text-muted-foreground">يتحقق الخادم عند الاختيار من وجود حساب بوابة أسرة مرتبط بالشخص حاليًا.</p>
      {term === "" ? null : people.isPending ? (
        <LoadingBlock rows={2} />
      ) : people.isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertDescription>{civilLookupAdminErrorMessage(people.error)}</AlertDescription>
        </Alert>
      ) : rows.length === 0 ? (
        <EmptyState icon={UsersRound} title="لا يوجد أشخاص مطابقون" description="جرّب اسمًا أو رمزًا آخر." />
      ) : (
        <ul className="divide-y divide-stroke-subtle rounded-lg border border-stroke-subtle" aria-label="نتائج البحث عن الأشخاص" data-family-results>
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
              <Button size="sm" variant="outline" onClick={() => onSelect({ person_code: p.person_code })}>
                اختيار
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function StaffAccountSearch({ onSelect }: { onSelect: (target: CivilLookupAccessTarget) => void }) {
  const [draft, setDraft] = useState("");
  const [term, setTerm] = useState("");
  const [page, setPage] = useState(1);
  const accounts = useCivilLookupStaffAccounts(term, page);

  return (
    <div className="flex flex-col gap-3" data-staff-search>
      <form
        className="flex flex-col gap-2 sm:flex-row"
        onSubmit={(e) => {
          e.preventDefault();
          setPage(1);
          setTerm(draft.trim());
        }}
      >
        <Label htmlFor="lookup-access-staff-search" className="sr-only">
          البحث عن موظف بالاسم أو البريد الإلكتروني
        </Label>
        <Input
          id="lookup-access-staff-search"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          placeholder="الاسم أو البريد الإلكتروني"
          autoComplete="off"
          className="sm:flex-1"
        />
        <Button type="submit" variant="outline" disabled={draft.trim().length < 2}>
          <Search className="size-4" aria-hidden />
          بحث
        </Button>
      </form>
      {term === "" ? null : accounts.isPending ? (
        <LoadingBlock rows={2} />
      ) : accounts.isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertDescription>{civilLookupAdminErrorMessage(accounts.error)}</AlertDescription>
        </Alert>
      ) : accounts.data.data.length === 0 ? (
        <EmptyState icon={UsersRound} title="لا يوجد موظفون مطابقون" description="جرّب اسمًا أو بريدًا آخر." />
      ) : (
        <>
          <ul className="divide-y divide-stroke-subtle rounded-lg border border-stroke-subtle" aria-label="نتائج البحث عن الموظفين" data-staff-results>
            {accounts.data.data.map((u) => (
              <li key={u.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                <div className="flex min-w-0 flex-col">
                  <span className="flex items-center gap-2 truncate font-medium text-foreground">
                    {u.name}
                    {!u.is_active && <StatusBadge tone="warning">غير نشط</StatusBadge>}
                  </span>
                  <span className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                    {u.email && <bdi dir="ltr">{u.email}</bdi>}
                    <span>{u.roles.map(roleLabel).join("، ")}</span>
                  </span>
                </div>
                <Button size="sm" variant="outline" disabled={!u.email} onClick={() => u.email && onSelect({ email: u.email })}>
                  اختيار
                </Button>
              </li>
            ))}
          </ul>
          {accounts.data.meta.last_page > 1 && (
            <RegistryPagination
              meta={{
                ...accounts.data.meta,
                from: (accounts.data.meta.current_page - 1) * accounts.data.meta.per_page + 1,
                to: (accounts.data.meta.current_page - 1) * accounts.data.meta.per_page + accounts.data.data.length,
                path: "",
              }}
              onPage={setPage}
              unit="حساب"
            />
          )}
        </>
      )}
    </div>
  );
}

function AccountSearch({ onSelect }: { onSelect: (target: CivilLookupAccessTarget) => void }) {
  const [category, setCategory] = useState<"FAMILY" | "STAFF">("FAMILY");

  return (
    <Panel data-account-search>
      <SectionHeader
        title="اختيار الحساب"
        description="حسابات بوابة الأسرة تُحدَّد عبر الشخص المرتبط بها، وحسابات الموظفين بالاسم أو البريد الإلكتروني."
        icon={UserRound}
        className="mb-3"
      />
      <div className="mb-3 flex flex-wrap gap-1.5" role="group" aria-label="فئة الحساب">
        {(["FAMILY", "STAFF"] as const).map((c) => (
          <Button
            key={c}
            size="sm"
            variant={category === c ? "default" : "outline"}
            aria-pressed={category === c}
            onClick={() => setCategory(c)}
            data-account-category={c}
          >
            {c === "FAMILY" ? "حسابات بوابة الأسرة والمنسقين" : "حسابات الموظفين"}
          </Button>
        ))}
      </div>
      {category === "FAMILY" ? <FamilyAccountSearch onSelect={onSelect} /> : <StaffAccountSearch onSelect={onSelect} />}
    </Panel>
  );
}

// ------------------------------------------------------------------ selected account

function AccountDetails({ account }: { account: CivilLookupAccountState }) {
  const isFamily = account.user.account_side === "FAMILY";
  const latest = account.changed_at ? { changed_by: account.changed_by, changed_at: account.changed_at } : account.last_change;
  const effective = Object.entries(account.effective) as [CivilLookupContext, { allowed: boolean; reason: string | null }][];

  return (
    <DetailList>
      <DetailItem label="المستخدم">
        <span className="flex flex-wrap items-center gap-2" data-account-name>
          {account.person?.full_name ?? account.user.name}
          {!account.user.is_active && <StatusBadge tone="warning">الحساب غير نشط</StatusBadge>}
        </span>
      </DetailItem>
      <DetailItem label="فئة الحساب">
        <span data-account-side={account.user.account_side}>{ACCOUNT_LABELS[account.user.account_side]}</span>
      </DetailItem>
      {isFamily || account.person ? (
        <DetailItem label="رمز الشخص المرتبط">
          {account.person ? (
            <span className="flex flex-wrap items-center gap-2">
              <Code>{account.person.person_code}</Code>
              <span className="text-xs text-muted-foreground">الارتباط: {LINK_LABELS[account.person.link_status] ?? account.person.link_status}</span>
            </span>
          ) : (
            "—"
          )}
        </DetailItem>
      ) : (
        <DetailItem label="البريد الإلكتروني">{account.user.email ? <bdi dir="ltr">{account.user.email}</bdi> : "—"}</DetailItem>
      )}
      <DetailItem label="الأدوار">{account.user.roles.length === 0 ? "—" : account.user.roles.map(roleLabel).join("، ")}</DetailItem>
      <DetailItem label="التجاوز الفردي الحالي">
        <AccessBadge access={account.access} />
      </DetailItem>
      <DetailItem label="الجهات الممنوحة">
        <span data-current-sides>{account.access === "GRANTED" ? sidesText(account.sides) : "—"}</span>
      </DetailItem>
      <DetailItem label="سياسة الدور">
        <span data-role-policy>
          {account.role_policy === null
            ? "لا توجد سياسة مفعّلة لأدوار هذا الحساب"
            : `${roleLabel(account.role_policy.role)} — ${account.role_policy.daily_limit === null ? "غير محدود" : `${account.role_policy.daily_limit.toLocaleString("ar")} يوميًا`}`}
        </span>
      </DetailItem>
      <DetailItem label="الأهلية الفعلية الآن">
        {effective.length === 0 ? (
          "—"
        ) : (
          <ul className="flex flex-col gap-1" data-effective>
            {effective.map(([context, result]) => (
              <li key={context} data-effective-context={context}>
                {CONTEXT_LABELS[context]}:{" "}
                {result.allowed ? "متاح" : `غير متاح${result.reason ? ` (${REASON_LABELS[result.reason] ?? result.reason})` : ""}`}
              </li>
            ))}
          </ul>
        )}
      </DetailItem>
      <DetailItem label="آخر تعديل (بتوقيت غزة)">
        <span data-last-change>
          {latest?.changed_at ? (
            <>
              <When iso={latest.changed_at} /> — {latest.changed_by ?? "—"}
            </>
          ) : (
            "لم يُعدَّل بعد"
          )}
        </span>
      </DetailItem>
    </DetailList>
  );
}

/**
 * The editor. Starts from the server state; the proposal is normalized
 * (GRANTED with no side left = DEFAULT, never REVOKED). A side can always be
 * removed, but added only while the server says it may be granted.
 */
function AccessEditor({
  account,
  target,
  busy,
  onDone,
}: {
  account: CivilLookupAccountState;
  target: CivilLookupAccessTarget;
  busy: boolean;
  onDone: (notice: { tone: "success" | "conflict"; text: string }) => void;
}) {
  const setAccess = useSetCivilLookupUserAccess();
  const isFamily = account.user.account_side === "FAMILY";
  const current: AccessState = { access: account.access, sides: ordered(account.sides) };
  const [mode, setMode] = useState<CivilLookupAccessValue>(account.access);
  const [sides, setSides] = useState<CivilLookupSide[]>(ordered(account.sides));
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const proposedSides = ordered(sides);
  const proposed: AccessState =
    mode !== "GRANTED" ? { access: mode, sides: [] } : proposedSides.length === 0 ? { access: "DEFAULT", sides: [] } : { access: "GRANTED", sides: proposedSides };
  const changed = !sameState(current, proposed);
  const unavailable = proposed.access === "GRANTED" ? proposed.sides.filter((s) => !account.grantable_sides.includes(s)) : [];
  const grantOffered = isFamily && (account.grantable_sides.length > 0 || account.access === "GRANTED");

  const sideHint = (side: CivilLookupSide) =>
    account.grantable_sides.includes(side)
      ? null
      : side === "COORDINATOR" && !account.user.roles.includes("COORDINATOR")
        ? "هذا الحساب لا يحمل دور المنسّق"
        : "الجهة غير مفعّلة حاليًا";

  const submit = () => {
    if (setAccess.isPending) return;
    setError(null);
    setAccess.mutate(
      { target, access: proposed.access, sides: proposed.access === "GRANTED" ? proposed.sides : undefined, expected: current },
      {
        onSuccess: () => {
          setConfirming(false);
          onDone({ tone: "success", text: `حُفظ التصريح: ${stateText(proposed)}.` });
        },
        onError: (e) => {
          if (civilLookupErrorCode(e) === "ACCESS_CHANGED") {
            setConfirming(false);
            onDone({ tone: "conflict", text: civilLookupAdminErrorMessage(e) });
            return;
          }
          setError(civilLookupAdminErrorMessage(e));
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-4" data-access-editor>
      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1 text-sm font-medium">التصريح الفردي</legend>
        {(["DEFAULT", "GRANTED", "REVOKED"] as const)
          .filter((v) => v !== "GRANTED" || grantOffered)
          .map((value) => (
            <label key={value} className="flex items-start gap-2 text-sm">
              <input type="radio" name="lookup-access-mode" className="mt-1" value={value} checked={mode === value} onChange={() => setMode(value)} />
              <span className="flex flex-col">
                <span className="font-medium">{ACCESS_LABELS[value]}</span>
                <span className="text-xs text-muted-foreground">
                  {value === "DEFAULT" &&
                    (isFamily
                      ? "يزيل التجاوز الفردي. لا يُنشئ وصولًا لحساب أسرة؛ تحتاج بوابة الأسرة ومساحة التنسيق منحًا صريحًا."
                      : "يزيل التجاوز الفردي؛ يعود الاستعلام لسياسة أدوار الحساب.")}
                  {value === "GRANTED" && "منح صريح لكل جهة على حدة. لا يتجاوز تفعيل الجهة أو سياسة الدور أو الرصيد اليومي."}
                  {value === "REVOKED" && "يمنع الحساب من الاستعلام في كل الجهات، دون تغيير أدواره أو هويته أو صلاحياته الأخرى."}
                </span>
              </span>
            </label>
          ))}
        {!isFamily && <p className="text-xs text-muted-foreground">المنح الفردي متاح لحسابات بوابة الأسرة فقط.</p>}
      </fieldset>

      {mode === "GRANTED" && grantOffered && (
        <fieldset className="flex flex-col gap-2" data-grant-sides>
          <legend className="mb-1 text-sm font-medium">الجهات الممنوحة</legend>
          {SIDES.map((side) => {
            const checked = sides.includes(side);
            const hint = sideHint(side);
            return (
              <label key={side} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={checked}
                  // A side can always be removed; added only while the server allows it.
                  disabled={!checked && hint !== null}
                  onChange={(e) => setSides((cur) => (e.target.checked ? [...cur, side] : cur.filter((s) => s !== side)))}
                />
                {SIDE_LABELS[side]}
                {hint && <span className="text-xs text-muted-foreground">({hint})</span>}
              </label>
            );
          })}
          {proposedSides.length === 0 && (
            <p className="text-xs text-muted-foreground" data-no-sides>
              لم تبقَ جهة محددة: سيُطبَّق «{ACCESS_LABELS.DEFAULT}».
            </p>
          )}
        </fieldset>
      )}

      <div className="grid gap-2 rounded-lg border border-stroke-subtle p-3 text-sm sm:grid-cols-2" data-access-diff>
        <div>
          <span className="text-muted-foreground">الحالي: </span>
          <span data-diff-current>{stateText(current)}</span>
        </div>
        <div>
          <span className="text-muted-foreground">المقترح: </span>
          <span data-diff-proposed>{stateText(proposed)}</span>
        </div>
      </div>

      {changed && unavailable.length > 0 && (
        <Alert variant="destructive" data-unavailable-sides>
          <AlertCircle className="size-4" />
          <AlertDescription>
            لا يمكن حفظ منح يتضمن جهة غير متاحة حاليًا ({sidesText(unavailable)}). أزلها، أو اختر «{ACCESS_LABELS.DEFAULT}» أو «{ACCESS_LABELS.REVOKED}».
          </AlertDescription>
        </Alert>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <Button
          onClick={() => (setError(null), setConfirming(true))}
          disabled={!changed || unavailable.length > 0 || busy || setAccess.isPending}
          data-action="save-access"
        >
          <ShieldCheck className="size-4" aria-hidden />
          حفظ التغيير
        </Button>
        {changed && (
          <Button
            variant="outline"
            onClick={() => {
              setMode(account.access);
              setSides(ordered(account.sides));
            }}
            disabled={setAccess.isPending}
          >
            تراجع
          </Button>
        )}
        {!changed && <span className="text-xs text-muted-foreground">لا توجد تغييرات للحفظ.</span>}
      </div>

      <Dialog open={confirming} onOpenChange={(next) => !setAccess.isPending && setConfirming(next)}>
        <DialogContent data-confirm-dialog>
          <DialogHeader>
            <DialogTitle>{proposed.access === "REVOKED" ? "تأكيد إيقاف الاستعلام" : "تأكيد تغيير تصريح الاستعلام"}</DialogTitle>
            <DialogDescription>
              الحساب «{account.person?.full_name ?? account.user.name}»: من «{stateText(current)}» إلى «{stateText(proposed)}». يُسجَّل التغيير في سجل
              التدقيق ولا يمكن حذفه.
            </DialogDescription>
          </DialogHeader>
          {proposed.access === "REVOKED" && (
            <Alert variant="destructive" data-revoke-warning>
              <AlertTitle>سيُمنع هذا الحساب من الاستعلام في كل الجهات</AlertTitle>
              <AlertDescription>لا تتغير أدواره أو هويته أو صلاحياته الأخرى. يمكن إلغاء الإيقاف لاحقًا باختيار «{ACCESS_LABELS.DEFAULT}».</AlertDescription>
            </Alert>
          )}
          {isFamily && current.access === "GRANTED" && proposed.access === "DEFAULT" && (
            <p className="text-sm text-muted-foreground">إزالة المنح تُغلق الاستعلام على هذا الحساب في بوابة الأسرة ومساحة التنسيق.</p>
          )}
          {error && (
            <Alert variant="destructive" data-dialog-error>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirming(false)} disabled={setAccess.isPending}>
              إلغاء
            </Button>
            <Button
              variant={proposed.access === "REVOKED" ? "destructive" : "default"}
              onClick={submit}
              disabled={setAccess.isPending}
              data-confirm="save-access"
            >
              {setAccess.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />}
              تأكيد
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function SelectedAccount({ target }: { target: CivilLookupAccessTarget }) {
  const account = useCivilLookupAccount(target);
  const [notice, setNotice] = useState<{ tone: "success" | "conflict"; text: string } | null>(null);

  return (
    <Panel data-selected-account>
      <SectionHeader title="الحساب المحدد" description="التجاوز الفردي غير الأهلية الفعلية: الأخيرة تعتمد أيضًا على تفعيل الخدمة والجهات وسياسات الأدوار والرصيد." icon={KeyRound} className="mb-3" />
      {notice && (
        <Alert variant={notice.tone === "success" ? "default" : "destructive"} className="mb-3" data-access-notice={notice.tone}>
          {notice.tone === "success" ? <CircleCheck className="size-4" /> : <AlertCircle className="size-4" />}
          <AlertDescription>{notice.text}</AlertDescription>
        </Alert>
      )}
      {account.isPending ? (
        <div className="flex flex-col gap-2" data-account-loading>
          <p role="status" className="sr-only">
            جارٍ تحميل الحالة الحالية
          </p>
          <Skeleton className="h-9 w-full" />
          <Skeleton className="h-9 w-full" />
        </div>
      ) : account.isError ? (
        <Alert variant="destructive" data-account-error>
          <AlertCircle className="size-4" />
          <AlertDescription className="flex flex-wrap items-center gap-2">
            {civilLookupAdminErrorMessage(account.error)}
            <Button size="sm" variant="outline" onClick={() => account.refetch()}>
              إعادة المحاولة
            </Button>
          </AlertDescription>
        </Alert>
      ) : (
        <div className="flex flex-col gap-5">
          <AccountDetails account={account.data} />
          <AccessEditor
            // A new server state (after a save or a conflict) restarts the editor from it.
            key={`${account.data.user.id}|${account.data.access}|${account.data.sides.join()}|${account.data.changed_at ?? ""}`}
            account={account.data}
            target={target}
            busy={account.isFetching}
            onDone={setNotice}
          />
        </div>
      )}
    </Panel>
  );
}

// ------------------------------------------------------------------ lists

function OverridesTable({ onSelect }: { onSelect: (target: CivilLookupAccessTarget) => void }) {
  const list = useCivilLookupUserAccess(true);

  return (
    <Panel flush data-overrides>
      <div className="px-4 pt-4 sm:px-5">
        <SectionHeader
          title="التجاوزات الفردية الحالية"
          description={
            list.data
              ? `تُعرض أحدث ${list.data.meta.limit.toLocaleString("ar")} تجاوزات من أصل ${list.data.meta.total.toLocaleString("ar")}. غياب حساب من هذه القائمة لا يعني أنه بلا تجاوز؛ ابحث عنه أعلاه للتحقق.`
              : "غياب حساب من هذه القائمة لا يعني أنه بلا تجاوز؛ ابحث عنه أعلاه للتحقق."
          }
        />
      </div>
      {list.isPending ? (
        <div className="p-4">
          <LoadingBlock rows={3} />
        </div>
      ) : list.isError ? (
        <div className="p-4">
          <MonitorError error={list.error} onRetry={() => list.refetch()} />
        </div>
      ) : list.data.data.length === 0 ? (
        <EmptyState icon={KeyRound} title="لا توجد تجاوزات فردية." />
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>المستخدم</TableHead>
              <TableHead>نوع الحساب</TableHead>
              <TableHead>الحالة</TableHead>
              <TableHead>الجهات الممنوحة</TableHead>
              <TableHead>بواسطة</TableHead>
              <TableHead>الوقت (بتوقيت غزة)</TableHead>
              <TableHead>
                <span className="sr-only">إجراء</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.data.data.map((row) => {
              const target: CivilLookupAccessTarget | null = row.person_code
                ? { person_code: row.person_code }
                : row.account_side !== "FAMILY" && row.user.email
                  ? { email: row.user.email }
                  : null;
              return (
                <TableRow key={row.user.id ?? `${row.user.email}`} data-access-row={row.access}>
                  <TableCell>
                    <div className="flex flex-col">
                      <span className="font-medium">{row.user.name ?? "—"}</span>
                      {row.person_code ? (
                        <Code>{row.person_code}</Code>
                      ) : row.user.email ? (
                        <bdi dir="ltr" className="text-xs text-muted-foreground">
                          {row.user.email}
                        </bdi>
                      ) : null}
                    </div>
                  </TableCell>
                  <TableCell>{row.account_side ? ACCOUNT_LABELS[row.account_side] : "—"}</TableCell>
                  <TableCell>
                    <AccessBadge access={row.access} />
                  </TableCell>
                  <TableCell>{row.access === "GRANTED" ? sidesText(row.sides) : "—"}</TableCell>
                  <TableCell>{row.changed_by ?? "—"}</TableCell>
                  <TableCell>
                    <When iso={row.changed_at} />
                  </TableCell>
                  <TableCell className="text-end">
                    {target ? (
                      <Button size="sm" variant="outline" onClick={() => onSelect(target)}>
                        عرض / تعديل
                      </Button>
                    ) : (
                      <span className="text-xs text-muted-foreground">لا يوجد ارتباط حالي</span>
                    )}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}
    </Panel>
  );
}

function AccessHistory({ userId }: { userId: number | null }) {
  const [page, setPage] = useState(1);
  const [onlySelected, setOnlySelected] = useState(true);
  const filterUser = onlySelected && userId !== null ? userId : undefined;
  const changes = useCivilLookupChanges(page, true, { subject: "USER", userId: filterUser });

  return (
    <Panel flush data-access-history>
      <div className="flex flex-wrap items-end justify-between gap-2 px-4 pt-4 sm:px-5">
        <SectionHeader title="سجل تغييرات التصاريح الفردية" description="القيمة السابقة والجديدة، ومن غيّرها ومتى. السجل غير قابل للتعديل أو الحذف." icon={History} />
        {userId !== null && (
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={onlySelected}
              onChange={(e) => {
                setOnlySelected(e.target.checked);
                setPage(1);
              }}
            />
            الحساب المحدد فقط
          </label>
        )}
      </div>
      {changes.isPending ? (
        <div className="p-4">
          <LoadingBlock rows={3} />
        </div>
      ) : changes.isError ? (
        <div className="p-4">
          <MonitorError error={changes.error} onRetry={() => changes.refetch()} />
        </div>
      ) : changes.data.data.length === 0 ? (
        <EmptyState icon={History} title="لا توجد تغييرات بعد." />
      ) : (
        <>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>المستخدم</TableHead>
                <TableHead>الحالة السابقة</TableHead>
                <TableHead>الحالة الجديدة</TableHead>
                <TableHead>الجهات السابقة</TableHead>
                <TableHead>الجهات الجديدة</TableHead>
                <TableHead>بواسطة</TableHead>
                <TableHead>الوقت (بتوقيت غزة)</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {changes.data.data.map((c) => (
                <TableRow key={c.id} data-access-change={c.id}>
                  <TableCell>{c.user?.name ?? "—"}</TableCell>
                  <TableCell>
                    <AccessBadge access={(c.old_access ?? "DEFAULT") as CivilLookupAccessValue} />
                  </TableCell>
                  <TableCell>
                    <AccessBadge access={(c.new_access ?? "DEFAULT") as CivilLookupAccessValue} />
                  </TableCell>
                  <TableCell>{sidesText(c.old_sides ?? [])}</TableCell>
                  <TableCell>{sidesText(c.new_sides ?? [])}</TableCell>
                  <TableCell>{c.changed_by ?? "—"}</TableCell>
                  <TableCell>
                    <When iso={c.changed_at} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <RegistryPagination
            meta={{
              ...changes.data.meta,
              from: (changes.data.meta.current_page - 1) * changes.data.meta.per_page + 1,
              to: (changes.data.meta.current_page - 1) * changes.data.meta.per_page + changes.data.data.length,
              path: "",
            }}
            onPage={setPage}
            unit="تغيير"
          />
        </>
      )}
    </Panel>
  );
}

// ------------------------------------------------------------------ page

export function CivilLookupAccess() {
  const [target, setTarget] = useState<CivilLookupAccessTarget | null>(null);
  // The same query as SelectedAccount (shared cache): its user id filters the history.
  const account = useCivilLookupAccount(target);
  const select = (next: CivilLookupAccessTarget) => {
    setTarget(next);
    document.querySelector("[data-selected-account-anchor]")?.scrollIntoView?.({ block: "start" });
  };

  return (
    <div className="flex flex-col gap-5" data-lookup-access>
      <PageHeader
        title="تصاريح الاستعلام المدني"
        description="إدارة التجاوزات الفردية للاستعلام عن السجل المدني: الإيقاف لأي حساب، والمنح الصريح لكل جهة لحسابات بوابة الأسرة والمنسقين. الكوتة وسياسات الأدوار في صفحة «كوتة الاستعلامات»."
      />
      <CivilRegistryNav />
      <AccountSearch onSelect={select} />
      <div data-selected-account-anchor />
      {target && <SelectedAccount key={JSON.stringify(target)} target={target} />}
      <OverridesTable onSelect={select} />
      <AccessHistory key={account.data?.user.id ?? "all"} userId={account.data?.user.id ?? null} />
    </div>
  );
}
