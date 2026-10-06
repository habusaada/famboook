"use client";

import { useForm } from "react-hook-form";
import { useQueryClient } from "@tanstack/react-query";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { ShieldOff, TriangleAlert } from "lucide-react";
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
import { refreshMobileTrustAfterRefusal, useRevokeMobileTrust } from "@/lib/api/mobile-trust";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import { MOBILE_TRUST_REVOKE_REASONS, mobileTrustRevokeReasonLabels } from "@/lib/utils/mobile-trust";

// UX validation only; Laravel's RevokeMobileTrustRequest is authoritative.
const schema = z.object({
  reason: z.enum(MOBILE_TRUST_REVOKE_REASONS, { message: "اختر سبب الإلغاء" }),
});
type Values = z.infer<typeof schema>;

/**
 * Staff revocation of the Person's trusted mobile
 * (person-mobile-trust.revoke, RevokePersonMobileTrustAction, FU-15):
 * TRUSTED → REVOKED with a reason code. The record stays as history and is
 * never restored; trusting the number again is a new grant. It does not
 * deactivate the Family account or end its sessions.
 */
export function RevokeMobileTrustDialog({ personCode, onRevoked }: { personCode: string; onRevoked: () => void }) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { reason: undefined } });
  const queryClient = useQueryClient();
  const mutation = useRevokeMobileTrust(personCode);
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField: { reason: "reason" },
    setError,
    statusMessages: {
      403: "لا تملك صلاحية إلغاء توثيق رقم الجوال.",
      404: "لم يعد هذا السجل متاحًا.",
      409: "لا يوجد توثيق جوال ساري لهذا الشخص. ستُحدَّث حالة التوثيق عند إغلاق النافذة.",
    },
    onSuccess: onRevoked,
  });
  const formId = `revoke-mobile-trust-${personCode}`;

  const onOpenChange = (next: boolean) => {
    if (next) {
      reset({ reason: undefined });
      mutation.reset();
    } else if (!mutation.isPending) {
      refreshMobileTrustAfterRefusal(queryClient, personCode, mutation.error);
    }
    flow.setOpen(next);
  };

  return (
    <Dialog open={flow.open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button variant="destructive" size="sm" data-revoke-mobile-trust>
          <ShieldOff className="size-4" />
          إلغاء التوثيق
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إلغاء توثيق رقم الجوال</DialogTitle>
          <DialogDescription>إلغاء التوثيق الساري لرقم الجوال المسجّل حاليًا للشخص.</DialogDescription>
        </DialogHeader>

        <Alert variant="destructive">
          <TriangleAlert className="size-4" />
          <AlertTitle>إجراء أمني حساس ومُسجَّل</AlertTitle>
          <AlertDescription>
            <ul className="list-disc ps-4">
              <li>لن يُستخدم هذا الرقم بعد الآن في العمليات الأمنية مثل استعادة كلمة المرور.</li>
              <li>لا يعطّل حساب الأسرة، ولا يُنهي بذاته جلسات الدخول القائمة.</li>
              <li>يبقى السجل الملغى في السجل التاريخي ولا يُعاد تفعيله؛ يتطلب التوثيق لاحقًا عملية توثيق جديدة.</li>
            </ul>
          </AlertDescription>
        </Alert>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({ reason: values.reason }));
          }}
        >
          <fieldset className="flex flex-col gap-2" aria-describedby={`${formId}-error`}>
            <legend className="mb-1 text-sm font-medium">سبب الإلغاء</legend>
            {MOBILE_TRUST_REVOKE_REASONS.map((reason) => (
              <label key={reason} className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm has-[:checked]:border-primary">
                <input type="radio" value={reason} className="size-4 accent-[var(--primary)]" {...register("reason")} />
                {mobileTrustRevokeReasonLabels[reason]}
              </label>
            ))}
            <div id={`${formId}-error`}>
              <FieldError message={errors.reason?.message} />
            </div>
          </fieldset>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => onOpenChange(false)}
          submitLabel="تأكيد الإلغاء"
        />
      </DialogContent>
    </Dialog>
  );
}
