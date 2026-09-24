"use client";

import { useRef, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { CheckCircle2, XCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  EditDialogFooter,
  FieldError,
  FieldLabel,
  SaveError,
} from "@/components/shared/edit-dialog-parts";
import { ApiError } from "@/lib/api/client";
import { useCloseNeed, useFulfillNeed } from "@/lib/api/needs";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import { closeNeedSchema, type CloseNeedValues } from "@/lib/schemas/need";
import type { Need } from "@/lib/types/api/need";

const RESOLVE_STATUS_MESSAGES: Record<number, string> = {
  403: "لا تملك صلاحية إغلاق الاحتياجات.",
  409: "تم إغلاق هذا الاحتياج مسبقًا.",
};

/** OPEN → FULFILLED after confirmation. Creates no Assistance record. */
export function FulfillNeedDialog({ need }: { need: Need }) {
  const mutation = useFulfillNeed(need.family.family_code, need.id);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const submitting = useRef(false);

  function confirm() {
    if (submitting.current) return;
    submitting.current = true;
    setError(null);
    mutation.mutate(undefined, {
      onSettled: () => {
        submitting.current = false;
      },
      onSuccess: () => setOpen(false),
      onError: (e) => {
        const status = e instanceof ApiError ? e.status : 0;
        setError(RESOLVE_STATUS_MESSAGES[status] ?? "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.");
      },
    });
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!mutation.isPending) setOpen(next);
        if (next) setError(null);
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm">
          <CheckCircle2 className="size-4" />
          تمت التلبية
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تمت التلبية</DialogTitle>
          <DialogDescription>
            هل أنت متأكد من أن هذا الاحتياج تمت تلبيته؟ بعد التأكيد يصبح الاحتياج سجلًا تاريخيًا
            للقراءة فقط.
          </DialogDescription>
        </DialogHeader>
        <SaveError message={error} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button type="button" onClick={confirm} disabled={mutation.isPending}>
            {mutation.isPending ? "جارٍ الحفظ..." : "تأكيد التلبية"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** OPEN → CLOSED with a required free-text reason. */
export function CloseNeedDialog({ need }: { need: Need }) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<CloseNeedValues>({
    resolver: zodResolver(closeNeedSchema),
    defaultValues: { closureReason: "" },
  });
  const flow = useGuardedSave({
    mutation: useCloseNeed(need.family.family_code, need.id),
    apiFieldToFormField: { closure_reason: "closureReason" },
    setError,
    statusMessages: RESOLVE_STATUS_MESSAGES,
  });
  const formId = `close-need-${need.id}`;

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset({ closureReason: "" });
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <XCircle className="size-4" />
          إغلاق الاحتياج
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إغلاق الاحتياج</DialogTitle>
          <DialogDescription>
            يُغلق الاحتياج دون تلبيته (مثلًا: لم يعد قائمًا، أو وُفِّر من مصدر آخر، أو سُجِّل
            بالخطأ). لا يمكن إعادة فتحه بعد ذلك.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({ closure_reason: values.closureReason.trim() }));
          }}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${formId}-reason`}>سبب الإغلاق</FieldLabel>
            <Textarea id={`${formId}-reason`} rows={3} {...register("closureReason")} />
            <FieldError message={errors.closureReason?.message} />
          </div>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel="إغلاق الاحتياج"
        />
      </DialogContent>
    </Dialog>
  );
}
