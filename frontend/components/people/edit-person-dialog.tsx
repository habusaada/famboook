"use client";

import { Controller, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
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
import { useUpdatePerson } from "@/lib/api/people";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import {
  editPersonSchema,
  personApiFieldToFormField,
  toUpdatePersonPayload,
  type EditPersonValues,
} from "@/lib/schemas/edit-person";
import type { PersonDetail } from "@/lib/types/api/person";
import { lifeStatusLabel } from "@/lib/utils/life-status";

function formValues(person: PersonDetail): EditPersonValues {
  return {
    fullName: person.full_name,
    gender: person.gender,
    birthDate: person.birth_date ?? "",
    mobile: person.mobile ?? "",
    alternateMobile: person.alternate_mobile ?? "",
    alternateMobileOwnerRelation: person.alternate_mobile_owner_relation ?? "",
  };
}

/**
 * Basic Person correction through PATCH /api/v1/people/{person}. Used on
 * the Person Profile and, for the current household head, on the Family
 * Profile — it never changes who the head is or any membership.
 *
 * Not editable here, by design:
 * - National ID: needs person.national-id.update (docs/06 §39), which no
 *   role holds yet, and the API does not expose it — so it isn't shown.
 * - Life status: recording death is a controlled operation (docs/03 §30,
 *   §16), so it is shown read-only.
 */
export function EditPersonDialog({
  person,
  title = "تعديل بيانات الشخص",
  description = "تعديل البيانات الأساسية فقط. لا يشمل تغيير رب الأسرة أو نقل العضوية.",
}: {
  person: PersonDetail;
  title?: string;
  description?: string;
}) {
  const {
    register,
    control,
    handleSubmit,
    reset,
    setValue,
    setError,
    formState: { errors },
  } = useForm<EditPersonValues>({
    resolver: zodResolver(editPersonSchema),
    defaultValues: formValues(person),
  });
  const flow = useGuardedSave({
    mutation: useUpdatePerson(person.person_code),
    apiFieldToFormField: personApiFieldToFormField,
    setError,
    statusMessages: { 403: "لا تملك صلاحية تعديل بيانات هذا الشخص." },
  });
  const hasAlternateMobile = Boolean(
    useWatch({ control, name: "alternateMobile" })?.trim()
  );
  const formId = `edit-person-form-${person.person_code}`;

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        // Always start from the current saved data.
        if (next) reset(formValues(person));
        flow.setOpen(next);
      }}
    >
      <EditTrigger />
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, toUpdatePersonPayload);
          }}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="edit-fullName">الاسم الرباعي</FieldLabel>
            <Input id="edit-fullName" {...register("fullName")} />
            <FieldError message={errors.fullName?.message} />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-gender">الجنس</FieldLabel>
              <Controller
                control={control}
                name="gender"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="edit-gender">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="MALE">ذكر</SelectItem>
                      <SelectItem value="FEMALE">أنثى</SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-birthDate">تاريخ الميلاد</FieldLabel>
              <Input
                id="edit-birthDate"
                type="date"
                dir="ltr"
                className="text-end"
                {...register("birthDate")}
              />
              <FieldError message={errors.birthDate?.message} />
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="edit-lifeStatus">الحالة</FieldLabel>
            <Input
              id="edit-lifeStatus"
              value={lifeStatusLabel(person.life_status)}
              readOnly
              disabled
            />
            <p className="text-xs text-muted-foreground">
              تغيير الحالة (تسجيل الوفاة) إجراء مستقل يتطلب مراجعة، ولا يتم من هذه
              النافذة.
            </p>
          </div>

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="edit-mobile" optional>
              الجوال الأساسي
            </FieldLabel>
            <Input
              id="edit-mobile"
              dir="ltr"
              className="text-end"
              {...register("mobile")}
            />
            <FieldError message={errors.mobile?.message} />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="edit-alternateMobile" optional>
                الجوال البديل
              </FieldLabel>
              <Input
                id="edit-alternateMobile"
                dir="ltr"
                className="text-end"
                {...register("alternateMobile", {
                  onChange: (e) => {
                    // No alternate number → no owner/relation to describe.
                    if (!String(e.target.value).trim()) {
                      setValue("alternateMobileOwnerRelation", "");
                    }
                  },
                })}
              />
              <FieldError message={errors.alternateMobile?.message} />
            </div>

            {hasAlternateMobile && (
              <div className="flex flex-col gap-1.5">
                <FieldLabel htmlFor="edit-alternateMobileOwnerRelation" optional>
                  صاحب الرقم البديل / صلته
                </FieldLabel>
                <Input
                  id="edit-alternateMobileOwnerRelation"
                  placeholder="مثال: أحمد محمد – أخ"
                  {...register("alternateMobileOwnerRelation")}
                />
                <FieldError message={errors.alternateMobileOwnerRelation?.message} />
              </div>
            )}
          </div>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
        />
      </DialogContent>
    </Dialog>
  );
}
