"use client";

import { useCallback } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  EditDialogFooter,
  EditTrigger,
  FieldError,
  FieldLabel,
  SaveError,
} from "@/components/shared/edit-dialog-parts";
import { ClanBranchFields } from "@/components/shared/clan-branch-fields";
import { useUpdateFamily } from "@/lib/api/families";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import {
  editFamilyRegistrationSchema,
  familyApiFieldToFormField,
  familyRegistrationFormValues,
  toUpdateFamilyPayload,
  type EditFamilyRegistrationValues,
} from "@/lib/schemas/edit-family";
import type { FamilyDetail } from "@/lib/types/api/family";

/**
 * Correction of basic registration metadata and the Family's Clan/Branch
 * (PATCH /api/v1/families/{family}). Family code, status and registration
 * source are not editable here.
 */
export function EditFamilyRegistrationDialog({ family }: { family: FamilyDetail }) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    setValue,
    control,
    formState: { errors },
  } = useForm<EditFamilyRegistrationValues>({
    resolver: zodResolver(editFamilyRegistrationSchema),
    defaultValues: familyRegistrationFormValues(family),
  });
  const flow = useGuardedSave({
    mutation: useUpdateFamily(family.family_code),
    apiFieldToFormField: familyApiFieldToFormField,
    setError,
    statusMessages: { 403: "لا تملك صلاحية تعديل بيانات هذه الأسرة." },
  });
  const clanCode = useWatch({ control, name: "clanCode" });
  const branchCode = useWatch({ control, name: "branchCode" });
  const setClanCode = useCallback(
    (code: string) => setValue("clanCode", code, { shouldDirty: true }),
    [setValue]
  );

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset(familyRegistrationFormValues(family));
        flow.setOpen(next);
      }}
    >
      <EditTrigger />
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تعديل معلومات التسجيل</DialogTitle>
          <DialogDescription>
            تصحيح بيانات تسجيل الأسرة. لا يتغيّر رقم الأسرة.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id="edit-family-registration-form"
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, toUpdateFamilyPayload);
          }}
          className="flex flex-col gap-4"
        >
          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-registrationDate">تاريخ التسجيل</FieldLabel>
              <Input
                id="edit-registrationDate"
                type="date"
                dir="ltr"
                className="text-end"
                {...register("registrationDate")}
              />
              <FieldError message={errors.registrationDate?.message} />
            </div>

            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-paperFormNo" optional>
                رقم الاستمارة الورقية
              </FieldLabel>
              <Input
                id="edit-paperFormNo"
                dir="ltr"
                className="text-end"
                {...register("paperFormNo")}
              />
              <FieldError message={errors.paperFormNo?.message} />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <ClanBranchFields
              idPrefix="edit"
              clanCode={clanCode}
              branchCode={branchCode}
              onClanChange={setClanCode}
              onBranchChange={(code) => setValue("branchCode", code, { shouldDirty: true })}
              clanError={errors.clanCode?.message}
              branchError={errors.branchCode?.message}
              current={{ clan: family.clan, branch: family.branch }}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="edit-notes" optional>
              ملاحظات
            </FieldLabel>
            <Textarea id="edit-notes" rows={3} {...register("notes")} />
            <FieldError message={errors.notes?.message} />
          </div>
        </form>

        <EditDialogFooter
          formId="edit-family-registration-form"
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
        />
      </DialogContent>
    </Dialog>
  );
}
