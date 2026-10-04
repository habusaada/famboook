"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { ShieldAlert, UserRoundCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { EditDialogFooter, FieldError, SaveError } from "@/components/shared/edit-dialog-parts";
import { useConfirmPersonAlive } from "@/lib/api/people";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import type { LifeStatusVerificationMethod, PersonDetail } from "@/lib/types/api/person";
import { lifeStatusVerificationLabels } from "@/lib/utils/life-status";

const METHODS = ["IN_PERSON", "STAFF_CALLBACK", "AUTHORIZED_RECORD_REVIEW"] as const satisfies readonly LifeStatusVerificationMethod[];

// UX validation only; Laravel's ConfirmPersonAliveRequest is authoritative.
const schema = z.object({
  verificationMethod: z.enum(METHODS, { message: "اختر طريقة التحقق" }),
});
type Values = z.infer<typeof schema>;

/**
 * Staff confirmation that a Person whose life status is UNKNOWN is alive
 * (person.record-death, ConfirmPersonAliveAction). It changes only the
 * registry life status, UNKNOWN → ALIVE, and records how it was verified.
 * There is no "restore deceased" action: a recorded death is never undone
 * here, and the dialog is offered for UNKNOWN only.
 */
export function ConfirmAliveDialog({ person }: { person: PersonDetail }) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { verificationMethod: undefined } });
  const mutation = useConfirmPersonAlive(person.person_code);
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField: { verification_method: "verificationMethod" },
    setError,
    statusMessages: {
      403: "لا تملك صلاحية تأكيد الحالة الحياتية.",
      404: "لم يعد هذا السجل متاحًا.",
      409: "تغيّرت الحالة الحياتية لهذا الشخص منذ فتح الصفحة. أعد تحميل الصفحة.",
    },
  });
  const formId = `confirm-alive-${person.person_code}`;

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) {
          reset({ verificationMethod: undefined });
          mutation.reset();
        }
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" data-confirm-alive>
          <UserRoundCheck className="size-4" />
          تأكيد أنه على قيد الحياة
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تأكيد أنه على قيد الحياة</DialogTitle>
          <DialogDescription>
            تأكيد الحالة الحياتية لـ <span className="font-medium text-foreground">{person.full_name}</span>.
          </DialogDescription>
        </DialogHeader>

        <Alert>
          <ShieldAlert className="size-4" />
          <AlertDescription>
            يغيّر هذا الإجراء الحالة الحياتية في السجل من «غير معروف» إلى «حي» فقط، ويُسجَّل في سجل نشاط الأسرة مع طريقة
            التحقق. لا يُنشئ حسابًا في بوابة الأسرة ولا يغيّر أي بيانات أخرى.
          </AlertDescription>
        </Alert>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({
              verification_method: values.verificationMethod,
            }));
          }}
        >
          <fieldset className="flex flex-col gap-2" aria-describedby={errors.verificationMethod ? `${formId}-error` : undefined}>
            <legend className="mb-1 text-sm font-medium">طريقة التحقق</legend>
            {METHODS.map((method) => (
              <label key={method} className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm has-[:checked]:border-primary">
                <input type="radio" value={method} className="size-4 accent-[var(--primary)]" {...register("verificationMethod")} />
                {lifeStatusVerificationLabels[method]}
              </label>
            ))}
            <div id={`${formId}-error`}>
              <FieldError message={errors.verificationMethod?.message} />
            </div>
          </fieldset>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel="تأكيد الحالة الحياتية"
        />
      </DialogContent>
    </Dialog>
  );
}
