"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { ClipboardPen, Info } from "lucide-react";
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
import { EditDialogFooter, FieldError, FieldLabel, SaveError } from "@/components/shared/edit-dialog-parts";
import { useRecordHouseholdDeclaration } from "@/lib/api/families";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import { todayIso } from "@/lib/schemas/assessment";
import type { FamilyDetail, StaffDeclarationSource } from "@/lib/types/api/family";
import { registrationSourceLabels } from "@/lib/utils/registration-source";

const SOURCES = ["PAPER_FORM", "MANUAL_ENTRY", "VERIFIED_SOURCE"] as const satisfies readonly StaffDeclarationSource[];

const MAX_COUNT = 32767;
const COUNTS = ["householdSize", "livingSons", "livingDaughters"] as const;

// "" = not declared; otherwise a whole number from 0. 0 is a value.
const count = z
  .string()
  .trim()
  .refine((v) => v === "" || (/^\d+$/.test(v) && Number(v) <= MAX_COUNT), "أدخل عددًا صحيحًا من 0 فأكثر");

const isStaffSource = (v: string | null | undefined): v is StaffDeclarationSource => SOURCES.some((s) => s === v);

// UX validation only; Laravel's RecordHouseholdDeclarationRequest and the
// Domain Action are authoritative. No arithmetic between the counts, and
// none with the registered members. The source is checked in the same
// refinement as "at least one value", so both messages show together.
const schema = z
  .object({
    householdSize: count,
    livingSons: count,
    livingDaughters: count,
    declaredAt: z.string().refine((v) => v === "" || v <= todayIso(), "تاريخ الإقرار لا يمكن أن يكون في المستقبل."),
    // An unchecked radio group reads as null.
    source: z.string().nullish(),
  })
  .superRefine((values, ctx) => {
    if (COUNTS.every((key) => values[key].trim() === "")) {
      ctx.addIssue({ code: "custom", path: ["householdSize"], message: "أدخل قيمة معلنة واحدة على الأقل." });
    }
    if (!isStaffSource(values.source)) {
      ctx.addIssue({ code: "custom", path: ["source"], message: "اختر مصدر الإقرار" });
    }
  });
type Values = z.infer<typeof schema>;

const EMPTY: Values = { householdSize: "", livingSons: "", livingDaughters: "", declaredAt: "", source: undefined };

const toCount = (value: string) => (value.trim() === "" ? null : Number(value));

const nf = new Intl.NumberFormat("ar");
const declaredLabel = (value: number | null) => (value == null ? "غير مُعلن" : nf.format(value));

/**
 * Staff recording of a new current household declaration (family.update,
 * RecordHouseholdDeclarationAction). The previous declaration stays as
 * history. Declared figures are source facts: never derived from, or
 * compared with, the registered members.
 *
 * Stale-write protection: the current declaration the screen showed when
 * the dialog opened is sent as the expectation. If another declaration was
 * recorded meanwhile the API refuses (409) and nothing is overwritten; the
 * family is refreshed and the declaration must be reviewed again (close
 * and reopen) before recording.
 */
export function RecordHouseholdDeclarationDialog({ family }: { family: FamilyDetail }) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: EMPTY });
  // Captured on open, not read live: a refresh after a 409 must not turn a
  // resubmission of the same form into an overwrite.
  const [expectedId, setExpectedId] = useState<number | null>(family.current_declaration_id);
  const mutation = useRecordHouseholdDeclaration(family.family_code);
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField: {
      declared_household_size: "householdSize",
      declared_living_sons: "livingSons",
      declared_living_daughters: "livingDaughters",
      declared_at: "declaredAt",
      source: "source",
    },
    setError,
    statusMessages: {
      403: "لا تملك صلاحية تسجيل إقرار لهذه الأسرة.",
      404: "لم يعد هذا السجل متاحًا.",
      409: "تغيّر إقرار الأسرة منذ فتح هذه النافذة، ولم يُحفظ شيء. أغلق النافذة وراجع الإقرار المحدَّث قبل تسجيل إقرار جديد.",
    },
  });
  const formId = `record-declaration-${family.family_code}`;
  const hasCurrent = family.current_declaration_id != null;

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) {
          reset(EMPTY);
          mutation.reset();
          setExpectedId(family.current_declaration_id);
        }
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant="outline" size="sm" data-record-declaration>
          <ClipboardPen className="size-4" />
          تسجيل إقرار أسرة
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تسجيل إقرار أسرة</DialogTitle>
          <DialogDescription>
            عدد أفراد الأسرة والأبناء والبنات كما أعلنه المصدر. يصبح هذا الإقرار هو الحالي، ويبقى الإقرار السابق في السجل.
          </DialogDescription>
        </DialogHeader>

        <div className="rounded-lg bg-surface-2 p-3 text-sm" data-current-declaration>
          <p className="mb-1.5 text-[13px] font-medium text-muted-foreground">الإقرار الحالي (المعلن)</p>
          {hasCurrent ? (
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
              <dt className="text-muted-foreground">عدد أفراد الأسرة</dt>
              <dd className="tabular-nums">{declaredLabel(family.declared_household_size)}</dd>
              <dt className="text-muted-foreground">الأبناء الذكور</dt>
              <dd className="tabular-nums">{declaredLabel(family.declared_living_sons)}</dd>
              <dt className="text-muted-foreground">البنات</dt>
              <dd className="tabular-nums">{declaredLabel(family.declared_living_daughters)}</dd>
              <dt className="text-muted-foreground">تاريخ الإقرار</dt>
              <dd>{family.declared_at ? <bdi dir="ltr">{family.declared_at}</bdi> : "غير معروف"}</dd>
              <dt className="text-muted-foreground">المصدر</dt>
              <dd>{family.declaration_source ? registrationSourceLabels[family.declaration_source] : "غير مسجّل"}</dd>
            </dl>
          ) : (
            <p className="text-subtle-foreground">لا يوجد إقرار مسجّل لهذه الأسرة.</p>
          )}
        </div>

        <Alert>
          <Info className="size-4" />
          <AlertDescription>
            الأرقام المعلنة مستقلة عن الأفراد المسجّلين تفصيليًا: لا تُنشئ أفرادًا، ولا تُقارن بعددهم. اترك الحقل فارغًا إذا لم يُعلن
            المصدر قيمته؛ الصفر قيمة معلنة.
          </AlertDescription>
        </Alert>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          className="flex flex-col gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({
              declared_household_size: toCount(values.householdSize),
              declared_living_sons: toCount(values.livingSons),
              declared_living_daughters: toCount(values.livingDaughters),
              declared_at: values.declaredAt === "" ? null : values.declaredAt,
              // Checked by the schema.
              source: values.source as StaffDeclarationSource,
              expected_current_declaration_id: expectedId,
            }));
          }}
        >
          <div className="grid grid-cols-3 gap-3">
            {(
              [
                ["householdSize", "عدد أفراد الأسرة"],
                ["livingSons", "الأبناء الذكور"],
                ["livingDaughters", "البنات"],
              ] as const
            ).map(([field, label]) => (
              <div key={field} className="flex flex-col gap-1.5">
                <FieldLabel htmlFor={`${formId}-${field}`} optional>
                  {label}
                </FieldLabel>
                <Input
                  id={`${formId}-${field}`}
                  type="number"
                  inputMode="numeric"
                  min={0}
                  max={MAX_COUNT}
                  step={1}
                  dir="ltr"
                  className="text-end tabular-nums"
                  {...register(field)}
                />
                <FieldError message={errors[field]?.message} />
              </div>
            ))}
          </div>

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${formId}-declaredAt`} optional>
              تاريخ الإقرار
            </FieldLabel>
            <Input id={`${formId}-declaredAt`} type="date" dir="ltr" className="text-end" max={todayIso()} {...register("declaredAt")} />
            <FieldError message={errors.declaredAt?.message} />
          </div>

          <fieldset className="flex flex-col gap-2" aria-describedby={`${formId}-source-error`}>
            <legend className="mb-1 text-sm font-medium">مصدر الإقرار</legend>
            <div className="grid grid-cols-3 gap-2">
              {SOURCES.map((source) => (
                <label key={source} className="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm has-[:checked]:border-primary">
                  <input type="radio" value={source} className="size-4 accent-[var(--primary)]" {...register("source")} />
                  {registrationSourceLabels[source]}
                </label>
              ))}
            </div>
            <div id={`${formId}-source-error`}>
              <FieldError message={errors.source?.message} />
            </div>
          </fieldset>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel="تسجيل الإقرار"
        />
      </DialogContent>
    </Dialog>
  );
}
