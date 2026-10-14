"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertCircle, AlertTriangle, Check, FileClock, Info, Loader2, RotateCw, Search, Send } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { FieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { FamilyRequestsHeader } from "@/components/family/requests/family-request-parts";
import { ApiError } from "@/lib/api/client";
import { changeRequestErrorCode } from "@/lib/api/change-requests";
import { type CoordinatorFamily, hierarchyLabel, useCoordinatorFamiliesQuery, useCoordinatorFamilyQuery } from "@/lib/api/coordinator";
import {
  coordinatorRequestErrorMessage,
  coordinatorSubmitErrorMessage,
  useCoordinatorRelationshipTypesQuery,
  useCoordinatorRequestTypesQuery,
  useSubmitCoordinatorPersonAdd,
} from "@/lib/api/coordinator-requests";
import type { FamilyRelationshipOption } from "@/lib/api/family-change-requests";
import {
  ADD_MEMBER_DEFAULTS,
  ADD_MEMBER_FIELDS,
  type AddMemberInput,
  type AddMemberValues,
  addMemberSchema,
  toAddMemberData,
} from "@/lib/schemas/family-add-member";
import { genderLabels } from "@/lib/utils/family-portal-labels";
import { MARITAL_STATUSES, maritalStatusLabels } from "@/lib/utils/marital-status";

const BACK = { href: "/family/coordinator/requests/new", label: "طلب جديد" };
const TITLE = "إضافة فرد إلى أسرة";

function Required() {
  return (
    <span className="text-danger" aria-hidden>
      *
    </span>
  );
}

function Message({ title, text, field }: { title: string; text: string; field: string }) {
  return (
    <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-add-member={field}>
      <FileClock className="size-6 text-muted-foreground" aria-hidden />
      <h2 className="text-base font-semibold text-foreground">{title}</h2>
      <p className="text-sm text-muted-foreground">{text}</p>
      <Button asChild variant="outline" className="h-10 rounded-xl">
        <Link href="/family/coordinator/requests">الانتقال إلى طلبات التنسيق</Link>
      </Button>
    </section>
  );
}

/**
 * The Family the member is proposed for — chosen ONLY among the Families the
 * server returns for this Coordinator's scope (GET …/coordinator/families).
 * The submission re-checks the scope; a Family outside it is a 404.
 */
function FamilyPicker({ selected, onSelect, disabled }: { selected: CoordinatorFamily | null; onSelect: (family: CoordinatorFamily | null) => void; disabled: boolean }) {
  const [draft, setDraft] = useState("");
  const [search, setSearch] = useState("");
  const families = useCoordinatorFamiliesQuery(search, 1);

  if (selected) {
    return (
      <div className="flex items-center justify-between gap-3 rounded-xl border border-brand-100 bg-brand-50 px-3.5 py-3" data-coordinator-selected-family={selected.family_code}>
        <div className="min-w-0">
          <p className="font-semibold break-words text-foreground">
            <bdi>{selected.head_name ?? "—"}</bdi>
          </p>
          <p className="text-[13px] text-muted-foreground">
            <bdi dir="ltr" className="font-mono">
              {selected.family_code}
            </bdi>{" "}
            · <bdi>{hierarchyLabel(selected)}</bdi>
          </p>
        </div>
        <Button type="button" variant="outline" className="h-10 shrink-0 rounded-xl" onClick={() => onSelect(null)} disabled={disabled}>
          تغيير
        </Button>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex gap-2" role="search">
        <Input
          type="search"
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter") {
              event.preventDefault();
              setSearch(draft.trim());
            }
          }}
          placeholder="رمز الأسرة أو اسم رب الأسرة"
          aria-label="بحث عن أسرة ضمن نطاقك"
          maxLength={100}
          className="h-11 min-w-0 flex-1 rounded-xl"
          disabled={disabled}
        />
        <Button type="button" className="h-11 rounded-xl px-4" onClick={() => setSearch(draft.trim())} aria-label="بحث" disabled={disabled}>
          <Search className="size-4" aria-hidden />
        </Button>
      </div>
      {families.isError ? (
        <p className="text-sm text-danger" role="alert">
          {coordinatorRequestErrorMessage(families.error)}
        </p>
      ) : !families.data ? (
        <Skeleton className="h-24 w-full rounded-xl" />
      ) : families.data.data.length === 0 ? (
        <p className="rounded-xl bg-surface-2 px-3.5 py-3 text-sm text-muted-foreground">لا توجد أسر مطابقة ضمن نطاقك.</p>
      ) : (
        <ul className="max-h-72 divide-y divide-stroke-subtle overflow-y-auto rounded-xl border border-border" aria-label="الأسر ضمن نطاقك" data-coordinator-family-options>
          {families.data.data.map((family) => (
            <li key={family.family_code}>
              <button
                type="button"
                onClick={() => onSelect(family)}
                className="flex w-full items-center gap-3 px-3.5 py-3 text-start transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-ring"
                data-coordinator-family-option={family.family_code}
              >
                <span className="min-w-0 flex-1">
                  <span className="block font-medium break-words text-foreground">
                    <bdi>{family.head_name ?? "—"}</bdi>
                  </span>
                  <span className="block text-[13px] text-muted-foreground">
                    <bdi dir="ltr" className="font-mono">
                      {family.family_code}
                    </bdi>{" "}
                    · <bdi>{hierarchyLabel(family)}</bdi>
                  </span>
                </span>
                <Check className="size-4 text-transparent" aria-hidden />
              </button>
            </li>
          ))}
        </ul>
      )}
      {families.data && families.data.meta.last_page > 1 && <p className="text-xs text-muted-foreground">تظهر أول النتائج فقط؛ ضيّق البحث برمز الأسرة أو الاسم.</p>}
    </div>
  );
}

function AddMemberForm({ relationships, initialFamily }: { relationships: FamilyRelationshipOption[]; initialFamily: CoordinatorFamily | null }) {
  const router = useRouter();
  const submitMutation = useSubmitCoordinatorPersonAdd();
  const [family, setFamily] = useState<CoordinatorFamily | null>(initialFamily);
  const [familyError, setFamilyError] = useState<string | null>(null);
  const form = useForm<AddMemberInput, unknown, AddMemberValues>({
    resolver: zodResolver(addMemberSchema),
    defaultValues: ADD_MEMBER_DEFAULTS,
  });
  const { register, control, setError, formState } = form;
  const errors = formState.errors;
  const [submitError, setSubmitError] = useState<string | null>(null);
  // One client_reference per proposal (Family + data + reason): the same
  // proposal re-sent after a failure replays on the server; any change
  // starts a new one. Never re-sent automatically.
  const attempt = useRef<{ key: string; reference: string } | null>(null);
  const sending = useRef(false);

  function send(values: AddMemberValues) {
    if (sending.current) return;
    setSubmitError(null);
    if (!family) {
      setFamilyError("اختر الأسرة ضمن نطاقك.");
      return;
    }
    const data = toAddMemberData(values);
    const reason = values.reason.trim() === "" ? null : values.reason.trim();
    const key = JSON.stringify([family.family_code, data, reason]);
    if (attempt.current?.key !== key) attempt.current = { key, reference: crypto.randomUUID() };

    sending.current = true;
    submitMutation.mutate(
      { familyCode: family.family_code, client_reference: attempt.current.reference, reason, data },
      {
        onSettled: () => {
          sending.current = false;
        },
        onSuccess: (outcome) => router.push(`/family/coordinator/requests/${outcome.id}`),
        onError: (error) => {
          if (changeRequestErrorCode(error) === "CHANGE_REQUEST_IDEMPOTENCY_CONFLICT") attempt.current = null;
          const fieldErrors = error instanceof ApiError && error.status === 422 ? error.validationErrors : undefined;
          for (const [field, messages] of Object.entries(fieldErrors ?? {})) {
            const target = ADD_MEMBER_FIELDS[field];
            if (target && messages[0]) setError(target, { type: "server", message: messages[0] });
          }
          setSubmitError(coordinatorSubmitErrorMessage(error));
        },
      }
    );
  }

  const pending = submitMutation.isPending;
  const submit = (event: React.FormEvent) => {
    if (!family) setFamilyError("اختر الأسرة ضمن نطاقك.");
    void form.handleSubmit(send)(event);
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate aria-busy={pending} data-coordinator-add-member="form">
      <p className="flex items-start gap-2.5 rounded-xl bg-brand-50 px-3.5 py-3 text-sm text-brand-800">
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
        <span>يُرسل الطلب للمراجعة، ولا يتغير السجل إلا بعد اعتماده وتطبيقه. يتحقق فريق السجل من هوية الفرد وصلة القرابة قبل الاعتماد.</span>
      </p>

      <fieldset className="flex flex-col gap-3 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
        <legend className="px-1 text-base font-semibold text-foreground">
          الأسرة <Required />
        </legend>
        <FamilyPicker
          selected={family}
          onSelect={(next) => {
            setFamily(next);
            if (next) setFamilyError(null);
          }}
          disabled={pending}
        />
        <FieldError message={familyError ?? undefined} />
      </fieldset>

      <fieldset className="flex flex-col gap-4 rounded-2xl border border-border bg-surface-1 p-4" disabled={pending}>
        <legend className="px-1 text-base font-semibold text-foreground">بيانات الفرد</legend>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-member-full-name">
            الاسم الكامل <Required />
          </FieldLabel>
          <Input id="coordinator-member-full-name" className="h-11 rounded-xl" autoComplete="off" aria-required aria-invalid={Boolean(errors.fullName)} {...register("fullName")} />
          <FieldError message={errors.fullName?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-member-national-id">
            رقم الهوية <Required />
          </FieldLabel>
          <Input
            id="coordinator-member-national-id"
            className="h-11 rounded-xl"
            dir="ltr"
            inputMode="numeric"
            autoComplete="off"
            maxLength={32}
            aria-required
            aria-describedby="coordinator-member-national-id-hint"
            aria-invalid={Boolean(errors.nationalId)}
            {...register("nationalId")}
          />
          <p id="coordinator-member-national-id-hint" className="text-xs text-muted-foreground">
            9 أرقام كما في الوثيقة. لا تُدخل رقمًا تقديريًا.
          </p>
          <FieldError message={errors.nationalId?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-member-gender">
            الجنس <Required />
          </FieldLabel>
          <Controller
            control={control}
            name="gender"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                <SelectTrigger id="coordinator-member-gender" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.gender)}>
                  <SelectValue placeholder="اختر" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="MALE">{genderLabels.MALE}</SelectItem>
                  <SelectItem value="FEMALE">{genderLabels.FEMALE}</SelectItem>
                </SelectContent>
              </Select>
            )}
          />
          <FieldError message={errors.gender?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-member-relationship">
            صلة القرابة برب الأسرة <Required />
          </FieldLabel>
          <Controller
            control={control}
            name="relationship"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                <SelectTrigger id="coordinator-member-relationship" className="h-11! w-full rounded-xl" aria-required aria-invalid={Boolean(errors.relationship)}>
                  <SelectValue placeholder="اختر" />
                </SelectTrigger>
                <SelectContent>
                  {relationships.map((r) => (
                    <SelectItem key={r.code} value={r.code}>
                      {r.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError message={errors.relationship?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-member-birth-date" optional>
            تاريخ الميلاد
          </FieldLabel>
          <Input id="coordinator-member-birth-date" type="date" dir="ltr" className="h-11 rounded-xl" max={new Date().toISOString().slice(0, 10)} aria-invalid={Boolean(errors.birthDate)} {...register("birthDate")} />
          <FieldError message={errors.birthDate?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-member-marital-status" optional>
            الحالة الاجتماعية
          </FieldLabel>
          <Controller
            control={control}
            name="maritalStatus"
            render={({ field }) => (
              <Select value={field.value === "" ? undefined : field.value} onValueChange={field.onChange} disabled={pending}>
                <SelectTrigger id="coordinator-member-marital-status" className="h-11! w-full rounded-xl" aria-invalid={Boolean(errors.maritalStatus)}>
                  <SelectValue placeholder="غير محدد" />
                </SelectTrigger>
                <SelectContent>
                  {MARITAL_STATUSES.map((status) => (
                    <SelectItem key={status} value={status}>
                      {maritalStatusLabels[status]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError message={errors.maritalStatus?.message} />
        </div>

        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor="coordinator-member-mobile" optional>
            رقم الجوال
          </FieldLabel>
          <Input id="coordinator-member-mobile" className="h-11 rounded-xl" dir="ltr" inputMode="tel" autoComplete="off" placeholder="05XXXXXXXX" aria-invalid={Boolean(errors.mobile)} {...register("mobile")} />
          <FieldError message={errors.mobile?.message} />
        </div>
      </fieldset>

      <div className="flex flex-col gap-1.5 rounded-2xl border border-border bg-surface-1 p-4">
        <FieldLabel htmlFor="coordinator-member-reason" optional>
          سبب الطلب
        </FieldLabel>
        <Textarea id="coordinator-member-reason" rows={3} className="rounded-xl" disabled={pending} aria-invalid={Boolean(errors.reason)} {...register("reason")} />
        <FieldError message={errors.reason?.message} />
      </div>

      {submitError && (
        <Alert variant="destructive" data-coordinator-submit-error>
          <AlertTriangle className="size-4" />
          <AlertTitle>لم يُرسل الطلب</AlertTitle>
          <AlertDescription>{submitError}</AlertDescription>
        </Alert>
      )}

      <Button type="submit" className="h-12 w-full gap-2 rounded-xl text-base" disabled={pending} data-coordinator-submit>
        {pending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Send className="size-4" aria-hidden />}
        {pending ? "جارٍ الإرسال…" : "إرسال الطلب للمراجعة"}
      </Button>
    </form>
  );
}

/**
 * «إضافة فرد إلى أسرة» in Coordinator Space (CO-02.6b, PERSON_ADD): offered
 * only while the server lists ADD_FAMILY_MEMBER for this Coordinator. The
 * Family comes from the scoped list (optionally pre-selected by ?family=,
 * which is only a lookup key the server answers or refuses). Nothing here
 * ever says whether the National ID is already known.
 */
export function CoordinatorAddMemberForm() {
  const params = useSearchParams();
  const preset = params.get("family") ?? "";
  const types = useCoordinatorRequestTypesQuery();
  const available = Boolean(types.data?.meta.submission_enabled && types.data.data.some(({ type }) => type === "ADD_FAMILY_MEMBER"));
  const relationships = useCoordinatorRelationshipTypesQuery(available);
  const presetFamily = useCoordinatorFamilyQuery(preset, preset !== "");
  const presetReady = preset === "" || !presetFamily.isPending;

  let body: React.ReactNode;
  const failed = types.isError ? types.error : relationships.isError ? relationships.error : null;
  if (failed) {
    body = (
      <section className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface-1 px-5 py-8 text-center" data-coordinator-add-member="error">
        <AlertCircle className="size-6 text-danger" aria-hidden />
        <p className="text-sm font-medium text-foreground" role="alert">
          {coordinatorRequestErrorMessage(failed)}
        </p>
        <Button
          variant="outline"
          className="h-10 gap-2 px-4"
          onClick={() => {
            void types.refetch();
            if (available) void relationships.refetch();
          }}
        >
          <RotateCw className="size-4" aria-hidden />
          إعادة المحاولة
        </Button>
      </section>
    );
  } else if (types.data && !available) {
    body = <Message field="unavailable" title="طلبات إضافة فرد غير متاحة لك حاليًا" text="يمكنك متابعة طلباتك السابقة من «طلبات التنسيق»." />;
  } else if (types.data && relationships.data && presetReady) {
    body = <AddMemberForm relationships={relationships.data} initialFamily={preset === "" ? null : (presetFamily.data ?? null)} />;
  } else {
    body = (
      <div className="flex flex-col gap-3" data-coordinator-add-member="loading">
        <p role="status" className="sr-only">
          جارٍ التحميل
        </p>
        <Skeleton className="h-24 w-full rounded-2xl" />
        <Skeleton className="h-96 w-full rounded-2xl" />
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-5">
      <FamilyRequestsHeader back={BACK} title={TITLE} description="اقترح إضافة فرد يعيش مع أسرة ضمن نطاقك وغير مسجّل فيها." />
      {body}
    </div>
  );
}
