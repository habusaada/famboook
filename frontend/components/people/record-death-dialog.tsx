"use client";

import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useQueryClient } from "@tanstack/react-query";
import { z } from "zod";
import { Flower, TriangleAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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
import { ApiError } from "@/lib/api/client";
import { refreshPersonLifeStatus, useRecordPersonDeath } from "@/lib/api/people";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import { todayIso } from "@/lib/schemas/assessment";
import type { LifeStatusVerificationMethod, PersonDetail } from "@/lib/types/api/person";
import { lifeStatusVerificationLabels } from "@/lib/utils/life-status";

const METHODS = ["IN_PERSON", "STAFF_CALLBACK", "AUTHORIZED_RECORD_REVIEW"] as const satisfies readonly LifeStatusVerificationMethod[];

// Known date or explicitly unknown — never inferred from an empty field.
const DATE_CHOICES = ["KNOWN", "UNKNOWN"] as const;

// UX validation only; Laravel's RecordPersonDeathRequest and the Domain
// Action (DeathDate) are authoritative.
function schemaFor(birthDate: string | null) {
  return z
    .object({
      verificationMethod: z.enum(METHODS, { message: "اختر طريقة التحقق" }),
      dateChoice: z.enum(DATE_CHOICES, { message: "أدخل تاريخ الوفاة، أو اختر أن تاريخ الوفاة غير معروف" }),
      deathDate: z.string(),
    })
    .superRefine((values, ctx) => {
      if (values.dateChoice !== "KNOWN") return;
      const issue = (message: string) => ctx.addIssue({ code: "custom", path: ["deathDate"], message });
      if (values.deathDate === "") issue("أدخل تاريخ الوفاة");
      else if (values.deathDate > todayIso()) issue("تاريخ الوفاة لا يمكن أن يكون في المستقبل.");
      else if (birthDate && values.deathDate < birthDate) issue("تاريخ الوفاة لا يمكن أن يسبق تاريخ الميلاد.");
    });
}
type Values = z.infer<ReturnType<typeof schemaFor>>;

const EMPTY = { verificationMethod: undefined, dateChoice: undefined, deathDate: "" } as unknown as Values;

/**
 * Staff recording of an existing Person's death (person.record-death,
 * RecordPersonDeathAction): ALIVE or UNKNOWN → DECEASED. Irreversible in
 * V1 — there is no path back to ALIVE. The death date is chosen
 * explicitly: a known date, or «تاريخ الوفاة غير معروف». For the current
 * household head the household loses Family Portal access and no successor
 * is assigned (FU-01); that is said before anything is sent.
 */
export function RecordDeathDialog({ person }: { person: PersonDetail }) {
  const queryClient = useQueryClient();
  const isHead = person.family_membership?.is_household_head === true;
  const {
    register,
    handleSubmit,
    reset,
    setError,
    control,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schemaFor(person.birth_date)), defaultValues: EMPTY });
  const dateChoice = useWatch({ control, name: "dateChoice" });
  const mutation = useRecordPersonDeath(person.person_code);
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField: { verification_method: "verificationMethod", death_date: "deathDate" },
    setError,
    statusMessages: {
      403: "لا تملك صلاحية تسجيل الوفاة.",
      404: "لم يعد هذا السجل متاحًا.",
      409: "وفاة هذا الشخص مسجّلة مسبقًا. لم يُغيَّر شيء، وستُحدَّث بياناته عند إغلاق النافذة.",
    },
  });
  const formId = `record-death-${person.person_code}`;
  const onOpenChange = (next: boolean) => {
    if (next) {
      reset(EMPTY);
      mutation.reset();
    } else if (mutation.error instanceof ApiError && mutation.error.status === 409) {
      // Refreshed on close: the refreshed (DECEASED) person no longer offers
      // this action, which would unmount the conflict message at once.
      refreshPersonLifeStatus(queryClient, person.person_code);
    }
    flow.setOpen(next);
  };

  return (
    <Dialog open={flow.open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button variant="destructive" size="sm" data-record-death>
          <Flower className="size-4" />
          تسجيل وفاة
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تسجيل وفاة</DialogTitle>
          <DialogDescription>
            تسجيل وفاة <span className="font-medium text-foreground">{person.full_name}</span> في السجل الرسمي.
          </DialogDescription>
        </DialogHeader>

        <Alert variant="destructive">
          <TriangleAlert className="size-4" />
          <AlertTitle>إجراء لا يمكن التراجع عنه</AlertTitle>
          <AlertDescription>
            يغيّر هذا الإجراء الحالة الحياتية الرسمية في السجل إلى «متوفى». لا يمكن التراجع عنه من خلال النظام الحالي، ويُسجَّل في
            سجل نشاط الأسرة مع طريقة التحقق.
          </AlertDescription>
        </Alert>

        {isHead && (
          <Alert variant="destructive" data-head-warning>
            <TriangleAlert className="size-4" />
            <AlertTitle>هذا الشخص هو رب الأسرة الحالي</AlertTitle>
            <AlertDescription>
              <ul className="list-disc ps-4">
                <li>سيتوقف وصول هذه الأسرة إلى بوابة الأسرة.</li>
                <li>لن يُعيَّن رب أسرة بديل تلقائيًا.</li>
                <li>ستحتاج الأسرة إلى مراجعة رب الأسرة من قبل الموظفين.</li>
              </ul>
            </AlertDescription>
          </Alert>
        )}

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          className="flex flex-col gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({
              death_date: values.dateChoice === "KNOWN" ? values.deathDate : null,
              verification_method: values.verificationMethod,
            }));
          }}
        >
          <fieldset className="flex flex-col gap-2" aria-describedby={`${formId}-date-error`}>
            <legend className="mb-1 text-sm font-medium">تاريخ الوفاة</legend>
            <label className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm has-[:checked]:border-primary">
              <input type="radio" value="KNOWN" className="size-4 accent-[var(--primary)]" {...register("dateChoice")} />
              تاريخ الوفاة معروف
            </label>
            {dateChoice === "KNOWN" && (
              <Input
                type="date"
                dir="ltr"
                className="text-end"
                aria-label="تاريخ الوفاة"
                max={todayIso()}
                min={person.birth_date ?? undefined}
                {...register("deathDate")}
              />
            )}
            <label className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm has-[:checked]:border-primary">
              <input type="radio" value="UNKNOWN" className="size-4 accent-[var(--primary)]" {...register("dateChoice")} />
              تاريخ الوفاة غير معروف
            </label>
            <div id={`${formId}-date-error`}>
              <FieldError message={errors.dateChoice?.message ?? errors.deathDate?.message} />
            </div>
          </fieldset>

          <fieldset className="flex flex-col gap-2" aria-describedby={`${formId}-method-error`}>
            <legend className="mb-1 text-sm font-medium">طريقة التحقق</legend>
            {METHODS.map((method) => (
              <label key={method} className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm has-[:checked]:border-primary">
                <input type="radio" value={method} className="size-4 accent-[var(--primary)]" {...register("verificationMethod")} />
                {lifeStatusVerificationLabels[method]}
              </label>
            ))}
            <div id={`${formId}-method-error`}>
              <FieldError message={errors.verificationMethod?.message} />
            </div>
          </fieldset>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => onOpenChange(false)}
          submitLabel="تسجيل الوفاة"
        />
      </DialogContent>
    </Dialog>
  );
}
