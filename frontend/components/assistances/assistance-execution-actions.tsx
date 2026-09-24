"use client";

import { useRef, useState } from "react";
import type { UseMutationResult } from "@tanstack/react-query";
import { CheckCircle2, PackageCheck, ShieldCheck } from "lucide-react";
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
import { Textarea } from "@/components/ui/textarea";
import { FieldError, FieldLabel, SaveError } from "@/components/shared/edit-dialog-parts";
import {
  useMarkNotDelivered,
  useRecordDelivery,
  useRejectNominee,
  useReverseDelivery,
  useVerifyDelivery,
} from "@/lib/api/assistances";
import { ApiError } from "@/lib/api/client";
import type {
  Assistance,
  DeliveryVerification,
  Nominee,
  ReceiptMode,
} from "@/lib/types/api/assistance";
import { formatDateTime } from "@/lib/utils/date";
import { receiptModeLabels, relationshipLabels } from "@/lib/utils/assistance";
import { maritalStatusLabels, type MaritalStatus } from "@/lib/utils/marital-status";

function apiMessage(e: unknown, fallback: string): { message: string; field?: string } {
  if (e instanceof ApiError) {
    if (e.status === 422) {
      const errors = e.validationErrors ?? {};
      const field = Object.keys(errors)[0];
      return { message: (field && errors[field]?.[0]) || e.message422 || fallback, field };
    }
    if (e.status === 409 && e.message422) return { message: e.message422 };
    if (e.status === 403) return { message: "لا تملك صلاحية تنفيذ هذا الإجراء." };
  }
  return { message: fallback };
}

// ---------------------------------------------------------------------------

/** A confirmation dialog that requires a free-text reason. */
function ReasonDialog({
  trigger,
  title,
  description,
  label,
  submitLabel,
  destructive,
  mutation,
  field,
}: {
  trigger: React.ReactNode;
  title: string;
  description: string;
  label: string;
  submitLabel: string;
  destructive?: boolean;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  mutation: UseMutationResult<unknown, Error, any>;
  field: string;
}) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const submitting = useRef(false);

  function submit() {
    if (submitting.current) return;
    if (!reason.trim()) {
      setError("السبب مطلوب.");
      return;
    }
    submitting.current = true;
    setError(null);
    mutation.mutate(
      { [field]: reason.trim() },
      {
        onSettled: () => {
          submitting.current = false;
        },
        onSuccess: () => setOpen(false),
        onError: (e) => setError(apiMessage(e, "تعذّر تنفيذ الإجراء.").message),
      }
    );
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (mutation.isPending) return;
        if (next) {
          setReason("");
          setError(null);
        }
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <SaveError message={error} />
        <div className="flex flex-col gap-1.5">
          <FieldLabel htmlFor={`reason-${field}`}>{label}</FieldLabel>
          <Textarea id={`reason-${field}`} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button type="button" variant={destructive ? "destructive" : "default"} onClick={submit} disabled={mutation.isPending}>
            {mutation.isPending ? "جارٍ الحفظ..." : submitLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function RejectNomineeDialog({ assistance, nominee }: { assistance: Assistance; nominee: Nominee }) {
  return (
    <ReasonDialog
      trigger={<Button variant="ghost" size="sm">رفض</Button>}
      title="رفض المرشح"
      description="الرفض نهائي في هذه المرحلة. يُحفظ السبب مع الترشيح ولا يظهر في سجل نشاط الأسرة."
      label="سبب الرفض"
      submitLabel="رفض المرشح"
      destructive
      mutation={useRejectNominee(assistance.id, nominee.id)}
      field="rejection_reason"
    />
  );
}

export function NotDeliveredDialog({ assistance, nominee }: { assistance: Assistance; nominee: Nominee }) {
  return (
    <ReasonDialog
      trigger={<Button variant="ghost" size="sm">لم يُسلَّم</Button>}
      title="تسجيل عدم التسليم"
      description="قرار نهائي بعدم التسليم لهذا المستفيد المعتمد (مثلًا: لم يحضر ضمن فترة التوزيع)."
      label="سبب عدم التسليم"
      submitLabel="تسجيل عدم التسليم"
      destructive
      mutation={useMarkNotDelivered(assistance.id, nominee.id)}
      field="not_delivered_reason"
    />
  );
}

export function ReverseDeliveryDialog({ assistance, deliveryId }: { assistance: Assistance; deliveryId: string }) {
  return (
    <ReasonDialog
      trigger={<Button variant="ghost" size="sm">عكس التسليم</Button>}
      title="عكس التسليم"
      description="يبقى سجل التسليم الأصلي محفوظًا ويُضاف إليه العكس، ويعود المستفيد إلى انتظار التسليم. يلزم تحقق جديد من الهوية لأي تسليم لاحق."
      label="سبب عكس التسليم"
      submitLabel="عكس التسليم"
      destructive
      mutation={useReverseDelivery(assistance.id, deliveryId)}
      field="reversal_reason"
    />
  );
}

// ---------------------------------------------------------------------------

/**
 * "تسجيل التسليم": National IDs are typed, verified by the API, then
 * cleared from the screen. Confirmation re-verifies server-side; IDs are
 * never displayed back.
 */
export function DeliveryDialog({ assistance, nominee }: { assistance: Assistance; nominee: Nominee }) {
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState<ReceiptMode>("PERSONAL");
  const [beneficiaryId, setBeneficiaryId] = useState("");
  const [delegateId, setDelegateId] = useState("");
  const [verified, setVerified] = useState<DeliveryVerification | null>(null);
  const [errors, setErrors] = useState<{ general?: string; beneficiary?: string; delegate?: string }>({});
  const verify = useVerifyDelivery(assistance.id, nominee.id);
  const record = useRecordDelivery(assistance.id, nominee.id);
  const submitting = useRef(false);

  function reset() {
    setMode("PERSONAL");
    setBeneficiaryId("");
    setDelegateId("");
    setVerified(null);
    setErrors({});
  }

  const payload = () => ({
    receipt_mode: mode,
    beneficiary_national_id: beneficiaryId,
    ...(mode === "DELEGATE" ? { delegate_national_id: delegateId } : {}),
  });

  function showError(e: unknown) {
    const { message, field } = apiMessage(e, "تعذّر التحقق. الرجاء المحاولة مرة أخرى.");
    if (field === "beneficiary_national_id") setErrors({ beneficiary: message });
    else if (field === "delegate_national_id") setErrors({ delegate: message });
    else setErrors({ general: message });
  }

  function runVerify() {
    if (verify.isPending) return;
    if (!beneficiaryId.trim()) return setErrors({ beneficiary: "رقم هوية المستفيد مطلوب." });
    if (mode === "DELEGATE" && !delegateId.trim()) {
      return setErrors({ delegate: "رقم هوية المستلم بالنيابة مطلوب مع رقم هوية المستفيد." });
    }
    setErrors({});
    verify.mutate(payload(), {
      onSuccess: (response) => setVerified(response.data),
      onError: showError,
    });
  }

  function confirm() {
    if (submitting.current || !verified) return;
    submitting.current = true;
    record.mutate(payload(), {
      onSettled: () => {
        submitting.current = false;
      },
      onSuccess: () => {
        setOpen(false);
        reset();
      },
      onError: (e) => {
        setVerified(null);
        showError(e);
      },
    });
  }

  const busy = verify.isPending || record.isPending;

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (busy) return;
        reset();
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm">
          <PackageCheck className="size-4" />
          تسجيل التسليم
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>تسجيل التسليم</DialogTitle>
          <DialogDescription>
            {nominee.person?.full_name ?? nominee.family.household_head_name ?? nominee.family.family_code} — تسليم
            الحزمة كاملة بعد التحقق من الهوية.
          </DialogDescription>
        </DialogHeader>
        <SaveError message={errors.general ?? null} />

        {!verified ? (
          <div className="flex flex-col gap-4">
            <div role="radiogroup" aria-label="طريقة الاستلام" className="flex flex-col gap-2">
              {(["PERSONAL", "DELEGATE"] as ReceiptMode[]).map((m) => (
                <label key={m} className="flex items-center gap-2 text-sm">
                  <input
                    type="radio"
                    name="receipt-mode"
                    className="size-4 accent-primary"
                    checked={mode === m}
                    onChange={() => {
                      setMode(m);
                      setErrors({});
                    }}
                  />
                  {receiptModeLabels[m]}
                </label>
              ))}
            </div>

            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="delivery-beneficiary-id">
                {mode === "PERSONAL" ? "رقم هوية المستفيد" : "رقم هوية المستفيد الأصلي"}
              </FieldLabel>
              <Input
                id="delivery-beneficiary-id"
                autoComplete="off"
                inputMode="numeric"
                dir="ltr"
                className="text-end"
                value={beneficiaryId}
                onChange={(e) => setBeneficiaryId(e.target.value)}
              />
              <FieldError message={errors.beneficiary} />
            </div>

            {mode === "DELEGATE" && (
              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="delivery-delegate-id">رقم هوية المستلم بالنيابة</FieldLabel>
                <Input
                  id="delivery-delegate-id"
                  autoComplete="off"
                  inputMode="numeric"
                  dir="ltr"
                  className="text-end"
                  value={delegateId}
                  onChange={(e) => setDelegateId(e.target.value)}
                />
                <FieldError message={errors.delegate} />
                <p className="text-xs text-muted-foreground">
                  يشترط أن يكون المستلم ابنًا أو ابنة غير متزوج/ة للمستفيد، مع حضور الهويتين معًا وقت التسليم.
                </p>
              </div>
            )}
          </div>
        ) : (
          <div className="flex flex-col gap-3" data-verified>
            <p className="flex items-center gap-1.5 rounded-md bg-muted/60 px-3 py-2 text-sm font-medium">
              <ShieldCheck className="size-4 text-primary" />
              تم التحقق من الهوية ({receiptModeLabels[verified.receipt_mode]})
            </p>
            <dl className="grid grid-cols-2 gap-3 text-sm">
              <div>
                <dt className="text-xs text-muted-foreground">المستفيد</dt>
                <dd>
                  {verified.beneficiary.full_name}
                  <span className="block text-xs text-muted-foreground" dir="ltr">{verified.beneficiary.person_code}</span>
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">المستلم</dt>
                <dd>
                  {verified.recipient.full_name}
                  <span className="block text-xs text-muted-foreground" dir="ltr">{verified.recipient.person_code}</span>
                </dd>
              </div>
              {verified.relationship && (
                <div>
                  <dt className="text-xs text-muted-foreground">صلة القرابة</dt>
                  <dd>{relationshipLabels[verified.relationship]}</dd>
                </div>
              )}
              {verified.recipient_marital_status && (
                <div>
                  <dt className="text-xs text-muted-foreground">الحالة الاجتماعية للمستلم</dt>
                  <dd>{maritalStatusLabels[verified.recipient_marital_status as MaritalStatus]}</dd>
                </div>
              )}
            </dl>
            <div className="rounded-md border p-3">
              <p className="mb-1.5 text-xs text-muted-foreground">الحزمة (تُسلَّم كاملة)</p>
              <ul className="flex flex-col gap-1 text-sm">
                {verified.package.map((item) => (
                  <li key={item.item_name}>
                    {item.item_name}
                    {item.quantity_per_beneficiary && ` — ${item.quantity_per_beneficiary}${item.unit ? ` ${item.unit}` : ""}`}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => (verified ? setVerified(null) : setOpen(false))} disabled={busy}>
            {verified ? "رجوع" : "إلغاء"}
          </Button>
          {verified ? (
            <Button type="button" onClick={confirm} disabled={busy}>
              <CheckCircle2 className="size-4" />
              {record.isPending ? "جارٍ التسجيل..." : "تأكيد التسليم"}
            </Button>
          ) : (
            <Button type="button" onClick={runVerify} disabled={busy}>
              <ShieldCheck className="size-4" />
              {verify.isPending ? "جارٍ التحقق..." : "تحقق من الهوية"}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------

/** Execution state of one beneficiary, per execution mode. Never implies delivery for EXTERNAL. */
export function ExecutionState({ assistance, nominee }: { assistance: Assistance; nominee: Nominee }) {
  if (nominee.status === "REJECTED") {
    return <span className="text-xs text-muted-foreground">{nominee.rejection_reason}</span>;
  }
  if (nominee.status === "NOT_DELIVERED") {
    return <span className="text-xs text-muted-foreground">لم يُسلَّم: {nominee.not_delivered_reason}</span>;
  }
  if (nominee.status !== "APPROVED") return <span className="text-muted-foreground">—</span>;

  if (assistance.execution_mode === "EXTERNAL") {
    return nominee.listed_in.length > 0 ? (
      <span className="flex flex-wrap items-center gap-1 text-xs">
        تم إصداره في كشف:
        {nominee.listed_in.map((l) => (
          <Badge key={l.list_id} variant="outline" dir="ltr">{l.list_number}</Badge>
        ))}
      </span>
    ) : (
      <span className="text-xs text-muted-foreground">لم يُدرج في كشف بعد</span>
    );
  }

  const d = nominee.active_delivery;
  return (
    <span className="flex flex-col gap-0.5 text-xs">
      {d ? (
        <>
          <Badge variant="secondary" className="w-fit">تم التسليم</Badge>
          <span className="text-muted-foreground">
            {formatDateTime(d.delivered_at)} — {receiptModeLabels[d.receipt_mode]}
            {d.receipt_mode === "DELEGATE" && d.recipient ? `: ${d.recipient.full_name}` : ""}
          </span>
        </>
      ) : (
        <Badge variant="outline" className="w-fit">بانتظار التسليم</Badge>
      )}
      {nominee.reversed_deliveries.length > 0 && (
        <span className="text-muted-foreground">تسليم معكوس: {nominee.reversed_deliveries.length}</span>
      )}
    </span>
  );
}
