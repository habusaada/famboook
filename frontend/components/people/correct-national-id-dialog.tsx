"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { IdCard, ShieldAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  Dialog,
  DialogContent,
  DialogDescription,
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
import { NationalIdDuplicateNotice } from "@/components/shared/national-id-duplicate";
import { duplicateMatches, useCorrectNationalId } from "@/lib/api/people";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import type { PersonDetail } from "@/lib/types/api/person";

// UX validation only; Laravel's CorrectNationalIdRequest is authoritative.
const schema = z
  .object({
    nationalId: z.string().trim().min(1, "رقم الهوية البديل مطلوب").max(50, "رقم الهوية طويل جدًا"),
    confirmation: z.string().trim(),
  })
  .refine((v) => v.nationalId === v.confirmation, {
    path: ["confirmation"],
    message: "تأكيد رقم الهوية غير مطابق",
  });
type Values = z.infer<typeof schema>;

// Both fields always start blank: the stored National ID is never sent to
// the browser, so it can never be pre-filled.
const BLANK: Values = { nationalId: "", confirmation: "" };

/**
 * Administrative National ID correction (person.national-id.update,
 * AUTH-ADR-059). The replacement is typed twice and is never shown back:
 * after saving, only the masked value is displayed. There is no "clear"
 * action — a blank value is refused.
 */
export function CorrectNationalIdDialog({ person }: { person: PersonDetail }) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: BLANK });
  const mutation = useCorrectNationalId(person.person_code);
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField: { national_id: "nationalId", national_id_confirmation: "confirmation" },
    setError,
    statusMessages: {
      403: "لا تملك صلاحية تصحيح رقم الهوية.",
      429: "محاولات كثيرة. حاول مجددًا بعد دقيقة.",
    },
    // Nothing typed stays in the form once saved.
    onSuccess: () => reset(BLANK),
  });
  const formId = `correct-national-id-${person.person_code}`;
  // A duplicate refusal lists the existing record(s) — never the value.
  const matches = duplicateMatches(mutation.error) ?? [];

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) {
          reset(BLANK);
          mutation.reset();
        }
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" data-correct-national-id>
          <IdCard className="size-4" />
          تصحيح رقم الهوية
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تصحيح رقم الهوية</DialogTitle>
          <DialogDescription>
            إجراء إداري لتصحيح رقم هوية <span className="font-medium text-foreground">{person.full_name}</span>.
          </DialogDescription>
        </DialogHeader>

        <Alert>
          <ShieldAlert className="size-4" />
          <AlertDescription>
            الرقم المسجّل حاليًا: <span dir="ltr" className="inline-block font-mono">{person.national_id_masked ?? "غير مسجّل"}</span>.
            لا يُعرض الرقم الكامل، ولا يُعبَّأ مسبقًا. أدخل الرقم الصحيح كاملًا مرتين.
          </AlertDescription>
        </Alert>

        <SaveError message={matches.length > 0 ? null : flow.submitError} />
        <NationalIdDuplicateNotice
          matches={matches}
          description="لا يمكن إعطاء هذا الشخص رقم هوية مسجلًا لشخص آخر. لم يتغير شيء. راجع السجل الموجود:"
        />

        <form
          id={formId}
          autoComplete="off"
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({
              national_id: values.nationalId.trim(),
              national_id_confirmation: values.confirmation.trim(),
            }));
          }}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${formId}-value`}>رقم الهوية الصحيح</FieldLabel>
            <Input
              id={`${formId}-value`}
              dir="ltr"
              className="text-end"
              autoComplete="off"
              inputMode="text"
              {...register("nationalId")}
            />
            <FieldError message={errors.nationalId?.message} />
          </div>
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${formId}-confirm`}>تأكيد رقم الهوية</FieldLabel>
            <Input
              id={`${formId}-confirm`}
              dir="ltr"
              className="text-end"
              autoComplete="off"
              onPaste={(e) => e.preventDefault()}
              {...register("confirmation")}
            />
            <FieldError message={errors.confirmation?.message} />
          </div>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel="حفظ رقم الهوية"
        />
      </DialogContent>
    </Dialog>
  );
}
