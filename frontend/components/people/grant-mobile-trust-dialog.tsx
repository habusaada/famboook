"use client";

import { useForm } from "react-hook-form";
import { useQueryClient } from "@tanstack/react-query";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { ShieldAlert, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { EditDialogFooter, FieldError, SaveError } from "@/components/shared/edit-dialog-parts";
import { refreshMobileTrustAfterRefusal, useGrantMobileTrust } from "@/lib/api/mobile-trust";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import { STAFF_MOBILE_VERIFICATION_METHODS, mobileVerificationMethodLabels } from "@/lib/utils/mobile-trust";

// UX validation only; Laravel's GrantMobileTrustRequest and
// GrantPersonMobileTrustAction are authoritative.
const schema = z.object({
  verificationMethod: z.enum(STAFF_MOBILE_VERIFICATION_METHODS, { message: "اختر طريقة التحقق" }),
  attested: z.literal(true, { message: "يجب تأكيد أن التحقق تم فعلًا قبل التوثيق" }),
});
type Values = z.infer<typeof schema>;

const EMPTY = { verificationMethod: undefined, attested: false } as unknown as Values;

/**
 * Staff grant of TRUSTED status to the Person's CURRENT registered mobile
 * (person-mobile-trust.grant, GrantPersonMobileTrustAction, FU-15). Only the
 * verification method is sent — never a number. Every grant is a NEW trust
 * record: a REVOKED or STALE record stays history and is never revived.
 * SELF_OTP is not a Staff method and is never offered.
 */
export function GrantMobileTrustDialog({
  personCode,
  mobileMasked,
  disabled,
  onGranted,
}: {
  personCode: string;
  mobileMasked: string | null;
  disabled?: boolean;
  onGranted: () => void;
}) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: EMPTY });
  const queryClient = useQueryClient();
  const mutation = useGrantMobileTrust(personCode);
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField: { verification_method: "verificationMethod" },
    setError,
    statusMessages: {
      403: "لا تملك صلاحية توثيق رقم الجوال.",
      404: "لم يعد هذا السجل متاحًا.",
      409: "رقم الجوال الحالي لهذا الشخص موثّق مسبقًا. ستُحدَّث حالة التوثيق عند إغلاق النافذة.",
    },
    onSuccess: onGranted,
  });
  const formId = `grant-mobile-trust-${personCode}`;

  const onOpenChange = (next: boolean) => {
    if (next) {
      reset(EMPTY);
      mutation.reset();
    } else if (!mutation.isPending) {
      refreshMobileTrustAfterRefusal(queryClient, personCode, mutation.error);
    }
    flow.setOpen(next);
  };

  return (
    <Dialog open={flow.open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" disabled={disabled} data-grant-mobile-trust>
          <ShieldCheck className="size-4" />
          توثيق رقم الجوال
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>توثيق رقم الجوال</DialogTitle>
          <DialogDescription>
            توثيق رقم الجوال المسجّل حاليًا للشخص
            {mobileMasked && (
              <>
                {" "}
                (<bdi dir="ltr" className="tracking-wider tabular-nums">{mobileMasked}</bdi>)
              </>
            )}
            .
          </DialogDescription>
        </DialogHeader>

        <Alert>
          <ShieldAlert className="size-4" />
          <AlertTitle>إجراء أمني حساس ومُسجَّل</AlertTitle>
          <AlertDescription>
            <ul className="list-disc ps-4">
              <li>يُوثَّق رقم الجوال المسجّل حاليًا في سجل الشخص فقط، ولا يُدخَل أي رقم في هذا الإجراء.</li>
              <li>بالتوثيق تُقرّ بأنك تحققت فعلًا من أن الرقم يعود لهذا الشخص بالطريقة المختارة.</li>
              <li>يُنشأ سجل توثيق جديد، وتبقى السجلات السابقة (الملغاة أو المتقادمة) كما هي في السجل التاريخي.</li>
              <li>يُسجَّل الإجراء في سجل الأحداث الأمنية باسمك.</li>
            </ul>
          </AlertDescription>
        </Alert>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          className="flex flex-col gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({ verification_method: values.verificationMethod }));
          }}
        >
          <fieldset className="flex flex-col gap-2" aria-describedby={`${formId}-method-error`}>
            <legend className="mb-1 text-sm font-medium">طريقة التحقق</legend>
            {STAFF_MOBILE_VERIFICATION_METHODS.map((method) => (
              <label key={method} className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm has-[:checked]:border-primary">
                <input type="radio" value={method} className="size-4 accent-[var(--primary)]" {...register("verificationMethod")} />
                {mobileVerificationMethodLabels[method]}
              </label>
            ))}
            <div id={`${formId}-method-error`}>
              <FieldError message={errors.verificationMethod?.message} />
            </div>
          </fieldset>

          <div className="flex flex-col gap-1">
            <label className="flex cursor-pointer items-start gap-2.5 text-sm">
              <input type="checkbox" className="mt-0.5 size-4 accent-[var(--primary)]" {...register("attested")} />
              أُقرّ بأن التحقق من ملكية الرقم تم فعلًا بالطريقة المختارة.
            </label>
            <FieldError message={errors.attested?.message} />
          </div>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => onOpenChange(false)}
          submitLabel="توثيق الرقم الحالي"
        />
      </DialogContent>
    </Dialog>
  );
}
