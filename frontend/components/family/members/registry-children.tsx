"use client";

import { useEffect, useId, useRef, useState } from "react";
import Link from "next/link";
import { AlertCircle, AlertTriangle, CheckCircle2, Info, Loader2, RotateCw, UserPlus, UsersRound } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatusBadge, type StatusTone } from "@/components/shared/status-badge";
import { FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { ApiError } from "@/lib/api/client";
import { isAccessFailure, useFamilyAccessFailure } from "@/lib/api/family-household";
import {
  type RegistryAdmissionDeclaration,
  type RegistryChild,
  type RegistryChildStatus,
  registryAdmissionErrorCode,
  toPaginationMeta,
  usableDeclaration,
  useAdmitRegistryChild,
  useFamilyRegistryChildrenQuery,
} from "@/lib/api/family-registry-admission";

const card = "rounded-2xl border border-border bg-surface-1 p-4";
const ADD_MEMBER_REQUEST = "/family/requests/new/add-family-member";
const UNNAMED = "الاسم غير متاح";

/**
 * Each documented public status with a safe explanation. NOT_ELIGIBLE is
 * one answer for every reason, and nothing here tells them apart (ADR §8);
 * an undocumented status is shown as NOT_ELIGIBLE and is never admissible.
 */
const STATUSES: Record<RegistryChildStatus, { label: string; tone: StatusTone; text: string }> = {
  ELIGIBLE: { label: "متاح للإضافة", tone: "brand", text: "يمكن إضافته / إضافتها إلى أفراد الأسرة بعد الإقرار." },
  ALREADY_MEMBER: { label: "ضمن أفراد الأسرة", tone: "success", text: "مسجّل / مسجّلة ضمن أفراد أسرتك." },
  REQUEST_OPEN: { label: "طلب قيد المراجعة", tone: "info", text: "لدى أسرتك طلب مفتوح لإضافته / إضافتها. تابعه من «طلباتي»." },
  NOT_ELIGIBLE: {
    label: "لا تتوفر إضافة مباشرة",
    tone: "neutral",
    text: "لا يمكن إضافته / إضافتها من هنا. يمكنك تقديم طلب «إضافة فرد إلى الأسرة» ليراجعه فريق السجل.",
  },
};

const statusOf = (child: RegistryChild) => STATUSES[child.status] ?? STATUSES.NOT_ELIGIBLE;

type Notice = { tone: "success" | "info" | "warning"; message: string };

/** A safe message for a failed discovery read; 401 / 403 belong to the access flow. */
function discoveryFailure(error: unknown): { title: string; message: string; retry: boolean } {
  const code = registryAdmissionErrorCode(error);
  if (code === "ADMISSION_UNAVAILABLE" || (error instanceof ApiError && error.status === 404)) {
    return { title: "الخدمة غير متاحة", message: "إضافة الأبناء والبنات من السجل المدني غير متاحة لأسرتك حاليًا.", retry: false };
  }
  if (code === "RESULT_SET_TOO_BROAD") {
    return { title: "تعذّر عرض القائمة", message: "تعذّر عرض الأبناء والبنات المقترحين لهذه الأسرة. يرجى مراجعة المكتب.", retry: false };
  }
  if (error instanceof ApiError && error.status === 429) {
    return { title: "محاولات كثيرة", message: "محاولات كثيرة خلال وقت قصير. انتظر قليلًا ثم أعد المحاولة.", retry: true };
  }
  if (!(error instanceof ApiError)) {
    return { title: "تعذّر الاتصال", message: "تحقق من الاتصال بالإنترنت ثم أعد المحاولة.", retry: true };
  }

  return { title: "تعذّر عرض القائمة", message: "تعذّر عرض الأبناء والبنات المقترحين الآن، حاول لاحقًا.", retry: true };
}

/**
 * What a failed admission means for the attempt. `keep`: the dialog stays
 * open and a retry re-sends the SAME client_reference (nothing changed on
 * the server). Otherwise the attempt ends; `fresh` re-reads the list,
 * because the reference or the child's state is no longer current.
 */
type AdmissionFailure = { message: string; keep: boolean; newKey?: boolean; fresh?: boolean };

function admissionFailure(error: unknown): AdmissionFailure {
  switch (registryAdmissionErrorCode(error)) {
    case "ADMISSION_IDEMPOTENCY_CONFLICT":
      return { message: "تعذّر إتمام الإضافة. أعد المحاولة.", keep: true, newKey: true };
    case "RELEASE_CHANGED":
      return { message: "تغيّر السجل المدني. اختر الاسم من القائمة المحدّثة.", keep: false, fresh: true };
    case "REFERENCE_INVALID":
      return { message: "انتهت صلاحية الاختيار. اختر الاسم من القائمة المحدّثة.", keep: false, fresh: true };
    case "NOT_ELIGIBLE":
      return { message: "لا يمكن إتمام هذه الإضافة مباشرة. يمكنك تقديم طلب «إضافة فرد إلى الأسرة».", keep: false, fresh: true };
    case "DECLARATION_REQUIRED":
      return { message: "تعذّر قبول الإقرار. لم يتغير شيء، حاول لاحقًا.", keep: false };
    case "ADMISSION_UNAVAILABLE":
      return { message: "إضافة الأبناء والبنات من السجل المدني غير متاحة لأسرتك حاليًا.", keep: false, fresh: true };
  }
  if (!(error instanceof ApiError)) {
    return { message: "تعذّر الاتصال. تحقق من الإنترنت ثم أعد المحاولة؛ لن تتكرر الإضافة.", keep: true };
  }
  switch (error.status) {
    case 401:
      return { message: "انتهت الجلسة. سجّل الدخول مرة أخرى.", keep: false };
    case 403:
      return { message: "لا يمكنك إتمام هذه الإضافة.", keep: false };
    case 429:
      return { message: "محاولات كثيرة خلال وقت قصير. انتظر قليلًا ثم أعد المحاولة.", keep: true };
    case 503:
      return { message: "تعذّر إتمام الإضافة حاليًا. لم يتغير شيء، أعد المحاولة لاحقًا.", keep: true };
    default:
      return { message: "حدث خطأ غير متوقع. أعد المحاولة؛ لن تتكرر الإضافة.", keep: true };
  }
}

function ChildrenSkeleton() {
  return (
    <div className="flex flex-col gap-3" data-registry-children-loading>
      <p role="status" className="sr-only">
        جارٍ تحميل الأبناء والبنات المقترحين
      </p>
      {[0, 1, 2].map((i) => (
        <div key={i} className={card} aria-hidden>
          <Skeleton className="h-5 w-32" />
          <Skeleton className="mt-2 h-4 w-24" />
          <Skeleton className="mt-2.5 h-3.5 w-56" />
        </div>
      ))}
    </div>
  );
}

function DiscoveryError({ error, onRetry, retrying }: { error: unknown; onRetry: () => void; retrying: boolean }) {
  const failure = discoveryFailure(error);

  return (
    <section className={`${card} flex flex-col items-center gap-3 py-6 text-center`} data-registry-children-error={failure.retry ? "retryable" : "final"}>
      <AlertCircle className="size-6 text-muted-foreground" aria-hidden />
      <h2 className="text-base font-semibold text-foreground">{failure.title}</h2>
      <p className="text-sm text-muted-foreground" role="alert">
        {failure.message}
      </p>
      {failure.retry ? (
        <Button variant="outline" className="h-10 gap-2 px-4" onClick={onRetry} disabled={retrying}>
          <RotateCw className={`size-4 ${retrying ? "animate-spin" : ""}`} aria-hidden />
          إعادة المحاولة
        </Button>
      ) : (
        <Button asChild variant="outline" className="h-10 rounded-xl">
          <Link href="/family/members">العودة إلى أفراد الأسرة</Link>
        </Button>
      )}
    </section>
  );
}

function ChildRow({ child, admissible, onAdmit }: { child: RegistryChild; admissible: boolean; onAdmit: () => void }) {
  const status = statusOf(child);
  const name = child.display_name ?? UNNAMED;
  const noteId = useId();
  // ELIGIBLE, yet nothing usable to admit with (no reference from the server, or no approved declaration).
  const blocked = child.status === "ELIGIBLE" && !admissible;

  return (
    <li className={`${card} flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between`} data-registry-child={child.status}>
      <div className="flex min-w-0 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-base font-semibold break-words text-foreground">{name}</span>
          <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
        </div>
        {child.birth_year !== null && (
          <span className="text-sm text-muted-foreground">
            سنة الميلاد: <bdi className="tabular-nums">{child.birth_year}</bdi>
          </span>
        )}
        <p id={noteId} className="text-sm text-muted-foreground" data-registry-child-note>
          {blocked ? "لا يمكن إتمام الإضافة من هنا حاليًا. حاول لاحقًا." : status.text}
        </p>
      </div>
      {child.status === "ELIGIBLE" && (
        <Button
          type="button"
          className="h-11 w-full shrink-0 gap-2 rounded-xl sm:w-auto"
          onClick={onAdmit}
          disabled={!admissible}
          aria-describedby={noteId}
          data-registry-child-admit
        >
          <UserPlus className="size-4" aria-hidden />
          إضافة إلى الأسرة
        </Button>
      )}
    </li>
  );
}

function AdmissionDialog({
  child,
  declaration,
  accepted,
  onAccept,
  lifeAccepted,
  onLifeAccept,
  error,
  pending,
  onSubmit,
  onClose,
}: {
  child: RegistryChild | null;
  declaration: RegistryAdmissionDeclaration | null;
  accepted: boolean;
  onAccept: (accepted: boolean) => void;
  lifeAccepted: boolean;
  onLifeAccept: (accepted: boolean) => void;
  error: string | null;
  pending: boolean;
  onSubmit: () => void;
  onClose: () => void;
}) {
  return (
    <Dialog open={child !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent showCloseButton={!pending} data-registry-admission-dialog>
        <DialogHeader>
          <DialogTitle>إضافة إلى أفراد الأسرة</DialogTitle>
          <DialogDescription>
            ستتم إضافة <bdi className="font-semibold text-foreground">{child?.display_name ?? UNNAMED}</bdi>
            {child?.birth_year != null && (
              <>
                {" "}
                (مواليد <bdi className="tabular-nums">{child.birth_year}</bdi>)
              </>
            )}{" "}
            إلى أفراد أسرتك مباشرةً بعد إقرارك. الإقرار تصريح منك على مسؤوليتك، وليس تحققًا من الجهة.
          </DialogDescription>
        </DialogHeader>

        {declaration ? (
          <label className="flex items-start gap-2.5 rounded-xl bg-surface-2 px-3.5 py-3 text-sm text-foreground" data-registry-declaration>
            <input
              type="checkbox"
              className="mt-0.5 size-4 shrink-0 accent-brand-600"
              checked={accepted}
              onChange={(event) => onAccept(event.target.checked)}
              disabled={pending}
              aria-required
            />
            <span>{declaration.text}</span>
          </label>
        ) : (
          <p className="flex items-start gap-2 rounded-xl bg-surface-2 px-3.5 py-3 text-sm text-muted-foreground" data-registry-declaration="unavailable">
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
            نص الإقرار غير متاح حاليًا، فلا يمكن إتمام الإضافة.
          </p>
        )}
        {/* RR-02A: the separate statement of current life, confirmed on its own. */}
        {declaration?.life_text && (
          <label className="flex items-start gap-2.5 rounded-xl bg-surface-2 px-3.5 py-3 text-sm text-foreground" data-registry-life-declaration>
            <input
              type="checkbox"
              className="mt-0.5 size-4 shrink-0 accent-brand-600"
              checked={lifeAccepted}
              onChange={(event) => onLifeAccept(event.target.checked)}
              disabled={pending}
              aria-required
            />
            <span>{declaration.life_text}</span>
          </label>
        )}

        {error && (
          <Alert variant="destructive" data-registry-admission-error>
            <AlertTriangle className="size-4" />
            <AlertTitle>لم تتم الإضافة</AlertTitle>
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={pending}>
            رجوع
          </Button>
          <Button
            type="button"
            onClick={onSubmit}
            disabled={!declaration || !accepted || (declaration.life_text !== null && !lifeAccepted) || pending}
            data-registry-admission-confirm
          >
            {pending && <Loader2 className="size-4 animate-spin" aria-hidden />}
            {pending ? "جارٍ الإضافة…" : error ? "إعادة المحاولة" : "تأكيد الإضافة"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * «الأبناء والبنات في السجل المدني» (FB-FAMILY-ADD-01 ADD-WP-08): the
 * signed-in head's suggested children, exactly as the server lists them,
 * and a direct admission of ONE eligible child at a time after its own
 * declaration (ADR §6, §7).
 *
 * - Admission is offered only for an ELIGIBLE child with a reference from
 *   the server and an approved declaration delivered with the list; otherwise
 *   the button is disabled. Today the server issues no reference and no declaration is
 *   approved, so nothing can be submitted.
 * - One client_reference per attempt (the child's reference and the
 *   declaration version), in memory only: kept for every retry of an
 *   uncertain attempt, even after another child was tried in between, never
 *   used for another child, and dropped after a success, a final refusal or
 *   a key conflict. A reload starts over with a new key: the old reference
 *   may be gone too, and the server answers a child admitted meanwhile as
 *   ALREADY_MEMBER (or NOT_ELIGIBLE), never with a second membership.
 * - At most one send in flight, never retried automatically. Nothing is
 *   logged, put in a URL, or kept in browser storage.
 */
export function RegistryChildren() {
  const [page, setPage] = useState(1);
  const query = useFamilyRegistryChildrenQuery(page);
  const data = query.data;
  // The approved declaration as the server delivered it with this page (RR-02); none = disabled.
  const declaration = usableDeclaration(data?.declaration);

  const admit = useAdmitRegistryChild();
  useFamilyAccessFailure(admit.error);
  const [candidate, setCandidate] = useState<RegistryChild | null>(null);
  const [accepted, setAccepted] = useState(false);
  const [lifeAccepted, setLifeAccepted] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const noticeRef = useRef<HTMLDivElement>(null);
  // Attempt key (child reference + declaration version) → client_reference.
  const attempts = useRef(new Map<string, string>());
  const sending = useRef(false);
  // References already admitted, so a second send cannot start before the list is re-read.
  const [admitted, setAdmitted] = useState<ReadonlySet<string>>(() => new Set());

  useEffect(() => {
    if (notice) noticeRef.current?.focus();
  }, [notice]);

  const admissible = (child: RegistryChild) =>
    child.status === "ELIGIBLE" && typeof child.ref === "string" && child.ref !== "" && declaration !== null && !admitted.has(child.ref);

  function open(child: RegistryChild) {
    if (!admissible(child)) return;
    setNotice(null);
    setDialogError(null);
    setAccepted(false); // never preselected
    setLifeAccepted(false);
    setCandidate(child);
  }

  function close() {
    if (sending.current) return;
    setCandidate(null);
    setDialogError(null);
  }

  function submit() {
    const lifeRequired = declaration?.life_text != null;
    if (sending.current || !candidate || !admissible(candidate) || !declaration || !accepted || (lifeRequired && !lifeAccepted)) return;
    const ref = candidate.ref as string;
    const name = candidate.display_name ?? UNNAMED;
    const key = JSON.stringify([ref, declaration.version, lifeRequired]);
    const reference = attempts.current.get(key) ?? crypto.randomUUID();
    attempts.current.set(key, reference);

    sending.current = true;
    setDialogError(null);
    admit.mutate(
      {
        ref,
        client_reference: reference,
        declaration_version: declaration.version,
        declaration_accepted: true,
        ...(lifeRequired ? { life_declaration_accepted: true as const } : {}),
      },
      {
        onSettled: () => {
          sending.current = false;
        },
        onSuccess: (outcome) => {
          attempts.current.delete(key);
          setAdmitted((done) => new Set(done).add(ref));
          setCandidate(null);
          setNotice(
            outcome.replayed
              ? { tone: "info", message: `سبق إتمام إضافة ${name} إلى أفراد الأسرة، ولم يتغير شيء.` }
              : { tone: "success", message: `تمت إضافة ${name} إلى أفراد الأسرة.` }
          );
        },
        onError: (error) => {
          const failure = admissionFailure(error);
          if (failure.keep) {
            if (failure.newKey) attempts.current.delete(key);
            setDialogError(failure.message);
            return;
          }
          attempts.current.delete(key);
          setCandidate(null);
          if (!isAccessFailure(error)) setNotice({ tone: "warning", message: failure.message });
          if (failure.fresh) void query.refetch();
        },
      }
    );
  }

  const meta = data ? toPaginationMeta(data.meta, data.children.length) : null;

  return (
    <div className="flex flex-col gap-5">
      <FamilyRequestsHeader
        back={{ href: "/family/members", label: "أفراد الأسرة" }}
        title="الأبناء والبنات في السجل المدني"
        description="أبناؤك وبناتك كما يظهرون في السجل المدني المنشور، وحالة كل منهم بالنسبة لأسرتك."
      />

      <p className="flex items-start gap-2.5 rounded-xl bg-brand-50 px-3.5 py-3 text-sm text-brand-800">
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span>تُعرض هنا أقل البيانات اللازمة للتمييز بين الأسماء. لا يُضاف أحد إلى أسرتك إلا بعد إقرارك وتأكيدك، ولكل اسم إقراره الخاص.</span>
      </p>

      {notice && (
        <Alert
          ref={noticeRef}
          tabIndex={-1}
          role={notice.tone === "warning" ? "alert" : "status"}
          className="outline-none focus-visible:ring-2 focus-visible:ring-ring"
          data-registry-admission-notice={notice.tone}
        >
          {notice.tone === "warning" ? <AlertTriangle className="size-4" /> : <CheckCircle2 className="size-4 text-success" />}
          <AlertTitle>{notice.tone === "warning" ? "لم تتم الإضافة" : "تمت الإضافة"}</AlertTitle>
          <AlertDescription>
            <p>{notice.message}</p>
            {notice.tone !== "warning" && (
              <Link href="/family/members" className="font-semibold text-brand-700 underline-offset-4 hover:underline">
                عرض أفراد الأسرة
              </Link>
            )}
          </AlertDescription>
        </Alert>
      )}

      <div aria-busy={query.isFetching} data-registry-children>
        {data ? (
          data.children.length === 0 ? (
            <section className={`${card} flex flex-col items-center gap-2 py-8 text-center`} data-registry-children-empty>
              <UsersRound className="size-6 text-subtle-foreground" aria-hidden />
              <p className="text-sm text-muted-foreground">لا يوجد أبناء أو بنات مقترحون لأسرتك في السجل المدني المنشور.</p>
            </section>
          ) : (
            <div className="flex flex-col gap-3">
              <ul className="flex flex-col gap-3" aria-label="الأبناء والبنات المقترحون">
                {/* Server order, never re-sorted; the reference never reaches the DOM. */}
                {data.children.map((child, i) => (
                  <ChildRow key={`${data.meta.page}-${i}`} child={child} admissible={admissible(child)} onAdmit={() => open(child)} />
                ))}
              </ul>
              {meta && meta.last_page > 1 && (
                <div className="rounded-2xl border border-border bg-surface-1">
                  <RegistryPagination meta={meta} unit="اسمًا" onPage={(next) => setPage(next)} />
                </div>
              )}
            </div>
          )
        ) : query.isError ? (
          !isAccessFailure(query.error) && <DiscoveryError error={query.error} onRetry={() => query.refetch()} retrying={query.isFetching} />
        ) : (
          <ChildrenSkeleton />
        )}
      </div>

      <p className="text-sm text-muted-foreground">
        لم تجد الاسم، أو لا تتوفر إضافته مباشرة؟{" "}
        <Link href={ADD_MEMBER_REQUEST} className="font-semibold text-brand-700 underline-offset-4 hover:underline">
          قدّم طلب «إضافة فرد إلى الأسرة»
        </Link>
      </p>

      <AdmissionDialog
        child={candidate}
        declaration={declaration}
        accepted={accepted}
        onAccept={setAccepted}
        lifeAccepted={lifeAccepted}
        onLifeAccept={setLifeAccepted}
        error={dialogError}
        pending={admit.isPending}
        onSubmit={submit}
        onClose={close}
      />
    </div>
  );
}
