"use client";

import Link from "next/link";
import { AlertCircle, ChevronLeft, Info, Loader2, LogOut, Network, RotateCw, Smartphone, UserRound, UsersRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useFamilyUser } from "@/components/family/family-context";
import { useFamilySignOut } from "@/components/family/use-family-sign-out";
import { ApiError } from "@/lib/api/client";
import { scopeLabel, useCoordinatorContextQuery } from "@/lib/api/coordinator";
import { type FamilyAccount, type FamilyMobileTrustState, useFamilyAccountQuery } from "@/lib/api/family-account";
import { isAccessFailure } from "@/lib/api/family-household";
import { formatDateLong } from "@/lib/utils/date";
import { NOT_RECORDED, mobileTrustOwnerLabels } from "@/lib/utils/family-portal-labels";

const card = "rounded-2xl border border-border bg-surface-1 p-4";

function Missing() {
  return <span className="font-normal text-subtle-foreground">{NOT_RECORDED}</span>;
}

function Row({ label, children, field }: { label: string; children: React.ReactNode; field: string }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2.5" data-field={field}>
      <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-end text-sm font-medium text-foreground">{children}</dd>
    </div>
  );
}

function Section({
  id,
  title,
  icon: Icon,
  children,
}: {
  id: string;
  title: string;
  icon: React.ComponentType<{ className?: string }>;
  children: React.ReactNode;
}) {
  return (
    <section className={card} aria-labelledby={`account-${id}-title`} data-account-section={id}>
      <h2 id={`account-${id}-title`} className="flex items-center gap-2 text-base font-semibold text-foreground">
        <Icon className="size-4 text-brand-700" aria-hidden />
        {title}
      </h2>
      {children}
    </section>
  );
}

const trustTone: Record<FamilyMobileTrustState, string> = {
  TRUSTED: "bg-success-soft text-success",
  STALE: "bg-warning-soft text-warning",
  REVOKED: "bg-muted text-muted-foreground",
  UNVERIFIED: "bg-muted text-muted-foreground",
  NO_MOBILE: "bg-muted text-muted-foreground",
  UNAVAILABLE: "bg-muted text-muted-foreground",
};

/** What the owner can do about a state; the Staff registry verifies numbers. */
const trustGuidance: Partial<Record<FamilyMobileTrustState, string>> = {
  STALE: "تغيّر رقم جوالك المسجّل بعد توثيقه. لإعادة توثيقه، يُرجى مراجعة إدارة السجل.",
  REVOKED: "لتوثيق رقم جوالك، يُرجى مراجعة إدارة السجل.",
  UNVERIFIED: "لتوثيق رقم جوالك، يُرجى مراجعة إدارة السجل.",
  NO_MOBILE: "لتسجيل رقم جوال صالح، يُرجى مراجعة إدارة السجل.",
};

/** The activation date, from the ISO timestamp's calendar date. */
function activationDate(activatedAt: string | null) {
  return activatedAt ? <span className="tabular-nums">{formatDateLong(activatedAt.slice(0, 10))}</span> : <Missing />;
}

function AccountRowsSkeleton() {
  return (
    <div className="mt-3 flex items-center justify-between gap-4" aria-hidden>
      <Skeleton className="h-4 w-24" />
      <Skeleton className="h-4 w-32" />
    </div>
  );
}

function AccountIdentity({ account, loading }: { account: FamilyAccount | undefined; loading: boolean }) {
  const user = useFamilyUser();

  return (
    <section className={card} aria-labelledby="account-identity-title" data-account-section="identity">
      <div className="flex items-start justify-between gap-3">
        <h2 id="account-identity-title" className="min-w-0 text-lg leading-snug font-semibold break-words text-foreground">
          {user.display_name ? <bdi>{user.display_name}</bdi> : <Missing />}
        </h2>
        <span className="shrink-0 rounded-md bg-success-soft px-2 py-0.5 text-xs font-semibold text-success" data-account-active>
          الحساب مفعّل
        </span>
      </div>
      <dl className="mt-1 divide-y divide-stroke-subtle">
        <Row label="تاريخ التفعيل" field="activated_at">
          {account ? activationDate(account.activated_at) : loading ? <Skeleton className="h-4 w-28" /> : <Missing />}
        </Row>
        <Row label="رمز الأسرة" field="family_code">
          {user.context.family ? <bdi dir="ltr">{user.context.family.code}</bdi> : <Missing />}
        </Row>
      </dl>
    </section>
  );
}

/** Human access types only — never a role or permission string. */
function AccessType() {
  const user = useFamilyUser();

  return (
    <Section id="access" title="نوع الدخول" icon={UserRound}>
      <ul className="mt-3 flex flex-col gap-2" data-account-access>
        <li className="flex flex-col gap-0.5 rounded-xl bg-surface-2 px-3 py-2.5">
          <span className="text-sm font-semibold text-foreground">رب الأسرة</span>
          <span className="text-[13px] text-muted-foreground">الاطلاع على بيانات أسرتك المسجّلة.</span>
        </li>
        {user.coordinator && (
          <li className="flex flex-col gap-0.5 rounded-xl bg-brand-50 px-3 py-2.5" data-account-access-coordinator>
            <span className="text-sm font-semibold text-brand-900">صلاحية التنسيق</span>
            <span className="text-[13px] text-brand-800">متابعة الأسر ضمن نطاق التنسيق المعيّن لك.</span>
          </li>
        )}
      </ul>
    </Section>
  );
}

/** The owner's summary of the CURRENT mobile trust: no history, no controls. */
function MobileTrust({ account }: { account: FamilyAccount }) {
  const { state, masked } = account.mobile;
  const guidance = trustGuidance[state];

  return (
    <>
      <dl className="mt-1 divide-y divide-stroke-subtle">
        <Row label="حالة التوثيق" field="mobile_state">
          <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${trustTone[state]}`} data-account-mobile-state={state}>
            {mobileTrustOwnerLabels[state]}
          </span>
        </Row>
        <Row label="رقم الجوال المسجّل" field="mobile">
          {masked ? (
            <bdi dir="ltr" className="tracking-wider tabular-nums">
              {masked}
            </bdi>
          ) : (
            <Missing />
          )}
        </Row>
      </dl>
      <p className="mt-2 flex items-start gap-2 text-[13px] leading-relaxed text-muted-foreground" data-account-mobile-note>
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        يُستخدم رقم الجوال الموثّق لاستلام رموز التحقق، ومنها رمز استعادة الوصول إلى حسابك.
      </p>
      {guidance && (
        <p className="mt-2 text-[13px] leading-relaxed text-foreground" data-account-mobile-guidance>
          {guidance}
        </p>
      )}
    </>
  );
}

function AccountError({ onRetry, retrying }: { onRetry: () => void; retrying: boolean }) {
  return (
    <div className="mt-3 flex flex-col items-center gap-3 py-2 text-center" data-account-error>
      <AlertCircle className="size-6 text-danger" aria-hidden />
      <p className="text-sm font-medium text-foreground" role="alert">
        تعذّر تحميل بيانات الحساب
      </p>
      <Button variant="outline" className="h-10 gap-2 px-4" onClick={onRetry} disabled={retrying}>
        <RotateCw className={`size-4 ${retrying ? "animate-spin" : ""}`} aria-hidden />
        إعادة المحاولة
      </Button>
    </div>
  );
}

/**
 * The effective scope, asked of the authoritative coordinator context every
 * time — /family/me only says whether Coordinator Space is open. The client
 * never derives or sends a scope.
 */
function CoordinatorScope() {
  const context = useCoordinatorContextQuery();

  if (context.isError) {
    const forbidden = context.error instanceof ApiError && context.error.status === 403;
    return (
      <p className="mt-3 text-sm text-muted-foreground" role={forbidden ? undefined : "alert"} data-account-coordinator-unavailable>
        {forbidden ? "لا يوجد نطاق تنسيق فعّال حاليًا." : "تعذّر تحميل نطاق التنسيق."}
      </p>
    );
  }

  if (!context.data) {
    return (
      <div className="mt-3 flex justify-center py-2">
        <Loader2 className="size-5 animate-spin text-brand-700" aria-label="جارٍ تحميل نطاق التنسيق" />
      </div>
    );
  }

  return (
    <>
      <ul className="mt-3 flex flex-wrap gap-2" data-account-coordinator-scopes>
        {context.data.scopes.map((scope) => (
          <li
            key={`${scope.type}:${scope.code}`}
            className="rounded-lg border border-brand-100 bg-brand-50 px-2.5 py-1.5 text-[13px] font-medium text-brand-900"
          >
            <bdi>{scopeLabel(scope)}</bdi>
          </li>
        ))}
      </ul>
      <p className="mt-3 flex items-center gap-2 text-sm text-muted-foreground" data-account-coordinator-count>
        <UsersRound className="size-4 text-brand-700" aria-hidden />
        عدد الأسر في النطاق: <span className="font-semibold text-foreground">{context.data.family_count}</span>
      </p>
      <Button asChild className="mt-4 h-11 w-full rounded-xl text-sm font-semibold hover:bg-[var(--family-primary-hover)]">
        <Link href="/family/coordinator" data-account-coordinator-entry>
          فتح مساحة التنسيق
        </Link>
      </Button>
    </>
  );
}

/** Coordinator accounts only; the scope is the server's, never the client's. */
function Coordinator() {
  const user = useFamilyUser();
  if (!user.coordinator) return null;

  return (
    <Section id="coordinator" title="مساحة التنسيق" icon={Network}>
      {user.coordinator_space ? (
        <CoordinatorScope />
      ) : (
        <p className="mt-3 text-sm text-muted-foreground" data-account-coordinator-none>
          لا يوجد نطاق تنسيق فعّال حاليًا.
        </p>
      )}
    </Section>
  );
}

function SignOut() {
  const { signOut, pending } = useFamilySignOut();

  return (
    <Button
      type="button"
      variant="outline"
      onClick={signOut}
      disabled={pending}
      className="h-12 w-full gap-2 rounded-xl text-sm font-semibold text-danger hover:text-danger"
      data-account-logout
    >
      {pending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <LogOut className="size-4" aria-hidden />}
      تسجيل الخروج
    </Button>
  );
}

/**
 * «حسابي» (PWA-3B.5, docs/11 §23a): the signed-in household head's ACCOUNT —
 * who is signed in, activation, the access types, the current mobile trust
 * summary, the Coordinator entry (coordinator accounts only), the way to
 * «بياناتي الشخصية» and sign-out. Not a registry view: the Person record
 * stays on /family/account/me. Account Recovery (FU-14) is a login-side flow
 * and is deliberately not offered here; there is no authenticated password
 * change yet. A 401 or 403 is handled by the session/access flow.
 */
export function AccountView() {
  const query = useFamilyAccountQuery();
  const account = query.data;

  return (
    <div className="flex flex-col gap-5">
      <header>
        <h1 className="text-2xl leading-snug font-bold text-foreground">حسابي</h1>
      </header>

      <div className="flex flex-col gap-4" aria-busy={query.isPending} data-account>
        <AccountIdentity account={account} loading={query.isPending} />
        <AccessType />

        <Section id="mobile" title="توثيق رقم الجوال" icon={Smartphone}>
          {account ? (
            <MobileTrust account={account} />
          ) : query.isError ? (
            !isAccessFailure(query.error) && <AccountError onRetry={() => query.refetch()} retrying={query.isFetching} />
          ) : (
            <>
              <p role="status" className="sr-only">
                جارٍ تحميل بيانات الحساب
              </p>
              <AccountRowsSkeleton />
              <AccountRowsSkeleton />
            </>
          )}
        </Section>

        <Coordinator />

        <Link
          href="/family/account/me"
          className={`${card} flex items-center gap-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring`}
          data-account-my-data
        >
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700" aria-hidden>
            <UserRound className="size-5" />
          </span>
          <span className="min-w-0 flex-1">
            <span className="block text-base font-semibold text-foreground">بياناتي الشخصية</span>
            <span className="mt-0.5 block text-[13px] text-muted-foreground">بياناتك المسجّلة في سجل الأسرة.</span>
          </span>
          <ChevronLeft className="size-5 shrink-0 text-muted-foreground" aria-hidden />
        </Link>

        <SignOut />
      </div>
    </div>
  );
}
