"use client";

import { Controller, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { CircleCheck, Pencil, Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
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
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  EditDialogFooter,
  FieldError,
  FieldLabel,
  SaveError,
} from "@/components/shared/edit-dialog-parts";
import {
  useCloseHealthRecord,
  useCreateHealthRecord,
  useUpdateHealthRecord,
} from "@/lib/api/health";
import { useDisabilityTypes } from "@/lib/api/reference";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import {
  addHealthApiFieldToFormField,
  addHealthRecordSchema,
  closeHealthApiFieldToFormField,
  closeHealthRecordSchema,
  editHealthApiFieldToFormField,
  editHealthRecordFormValues,
  editHealthRecordSchema,
  EMPTY_ADD_HEALTH_RECORD,
  toCreateHealthRecordPayload,
  toUpdateHealthRecordPayload,
  type AddHealthRecordValues,
  type CloseHealthRecordValues,
  type EditHealthRecordValues,
} from "@/lib/schemas/health-record";
import type { FamilyMemberDetail } from "@/lib/types/api/family";
import type { HealthRecord, HealthRecordType } from "@/lib/types/api/health";
import { healthRecordTypeLabels, isMaternalType } from "@/lib/utils/health";

const HEALTH_STATUS_MESSAGES = {
  403: "لا تملك صلاحية تعديل البيانات الصحية.",
};

const RECORD_TYPES = Object.keys(healthRecordTypeLabels) as HealthRecordType[];

function DisabilityTypeSelect({
  id,
  value,
  onChange,
  keepOption,
}: {
  id: string;
  value: string | undefined;
  onChange: (value: string) => void;
  // A deactivated type already on the record stays selectable for display.
  keepOption?: { id: number; name: string } | null;
}) {
  const { data, isLoading } = useDisabilityTypes();
  const options = data?.data ?? [];
  const showKept = keepOption && !options.some((o) => o.id === keepOption.id);

  return (
    <Select value={value ?? ""} onValueChange={onChange} disabled={isLoading}>
      <SelectTrigger id={id}>
        <SelectValue placeholder={isLoading ? "جارٍ التحميل..." : "اختر نوع الإعاقة"} />
      </SelectTrigger>
      <SelectContent>
        {showKept && (
          <SelectItem value={String(keepOption.id)}>{keepOption.name}</SelectItem>
        )}
        {options.map((option) => (
          <SelectItem key={option.id} value={String(option.id)}>
            {option.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

// ---------------------------------------------------------------------------

export function AddHealthRecordDialog({
  familyCode,
  members,
}: {
  familyCode: string;
  members: FamilyMemberDetail[];
}) {
  const {
    register,
    control,
    handleSubmit,
    reset,
    setValue,
    setError,
    formState: { errors },
  } = useForm<AddHealthRecordValues>({
    resolver: zodResolver(addHealthRecordSchema),
    defaultValues: EMPTY_ADD_HEALTH_RECORD,
  });
  const flow = useGuardedSave({
    mutation: useCreateHealthRecord(familyCode),
    apiFieldToFormField: addHealthApiFieldToFormField,
    setError,
    statusMessages: HEALTH_STATUS_MESSAGES,
  });

  const type = useWatch({ control, name: "type" });
  const personCode = useWatch({ control, name: "personCode" });
  const activeMembers = members.filter((m) => m.is_active);
  const selectedPerson = activeMembers.find((m) => m.person_code === personCode);
  // Pregnancy/breastfeeding: only FEMALE members can be picked.
  const pickableMembers = isMaternalType(type)
    ? activeMembers.filter((m) => m.gender === "FEMALE")
    : activeMembers;

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset(EMPTY_ADD_HEALTH_RECORD);
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm">
          <Plus className="size-4" />
          إضافة حالة صحية
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إضافة حالة صحية</DialogTitle>
          <DialogDescription>
            تُسجَّل الحالة لفرد من أفراد الأسرة، وتُحسب مؤشرات الأسرة تلقائيًا.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id="add-health-record-form"
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, toCreateHealthRecordPayload);
          }}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="health-person">الشخص</FieldLabel>
            <Controller
              control={control}
              name="personCode"
              render={({ field }) => (
                <Select value={field.value ?? ""} onValueChange={field.onChange}>
                  <SelectTrigger id="health-person">
                    <SelectValue placeholder="اختر فردًا من الأسرة" />
                  </SelectTrigger>
                  <SelectContent>
                    {pickableMembers.map((member) => (
                      <SelectItem key={member.person_code} value={member.person_code}>
                        {member.full_name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError message={errors.personCode?.message} />
          </div>

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="health-type">نوع الحالة</FieldLabel>
            <Controller
              control={control}
              name="type"
              render={({ field }) => (
                <Select
                  value={field.value ?? ""}
                  onValueChange={(value) => {
                    const next = value as HealthRecordType;
                    field.onChange(next);
                    // Never carry fields of another type into this one.
                    setValue("disabilityTypeId", "");
                    setValue("conditionName", "");
                    if (isMaternalType(next) && selectedPerson && selectedPerson.gender !== "FEMALE") {
                      setValue("personCode", "");
                    }
                  }}
                >
                  <SelectTrigger id="health-type">
                    <SelectValue placeholder="اختر نوع الحالة" />
                  </SelectTrigger>
                  <SelectContent>
                    {RECORD_TYPES.map((recordType) => (
                      <SelectItem
                        key={recordType}
                        value={recordType}
                        disabled={isMaternalType(recordType) && selectedPerson?.gender === "MALE"}
                      >
                        {healthRecordTypeLabels[recordType]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError message={errors.type?.message} />
          </div>

          {type === "DISABILITY" && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="health-disabilityType">نوع الإعاقة</FieldLabel>
              <Controller
                control={control}
                name="disabilityTypeId"
                render={({ field }) => (
                  <DisabilityTypeSelect
                    id="health-disabilityType"
                    value={field.value}
                    onChange={field.onChange}
                  />
                )}
              />
              <FieldError message={errors.disabilityTypeId?.message} />
            </div>
          )}

          {type === "CHRONIC_DISEASE" && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="health-conditionName">اسم المرض المزمن</FieldLabel>
              <Input
                id="health-conditionName"
                placeholder="مثال: السكري"
                {...register("conditionName")}
              />
              <FieldError message={errors.conditionName?.message} />
            </div>
          )}

          {isMaternalType(type) && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="health-startedAt" optional>
                تاريخ البداية
              </FieldLabel>
              <Input
                id="health-startedAt"
                type="date"
                dir="ltr"
                className="text-end"
                {...register("startedAt")}
              />
              <FieldError message={errors.startedAt?.message} />
            </div>
          )}

          {type && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="health-details" optional>
                {isMaternalType(type) ? "ملاحظات" : "التفاصيل"}
              </FieldLabel>
              <Textarea id="health-details" rows={2} {...register("details")} />
              <FieldError message={errors.details?.message} />
            </div>
          )}
        </form>

        <EditDialogFooter
          formId="add-health-record-form"
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel="حفظ"
        />
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------

export function EditHealthRecordDialog({
  familyCode,
  record,
}: {
  familyCode: string;
  record: HealthRecord;
}) {
  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<EditHealthRecordValues>({
    resolver: zodResolver(editHealthRecordSchema),
    defaultValues: editHealthRecordFormValues(record),
  });
  const flow = useGuardedSave({
    mutation: useUpdateHealthRecord(familyCode, record.id),
    apiFieldToFormField: editHealthApiFieldToFormField,
    setError,
    statusMessages: HEALTH_STATUS_MESSAGES,
  });
  const formId = `edit-health-record-${record.id}`;

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset(editHealthRecordFormValues(record));
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm" aria-label="تعديل السجل">
          <Pencil className="size-4" />
          تعديل
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تعديل حالة صحية</DialogTitle>
          <DialogDescription>
            {healthRecordTypeLabels[record.type]} — {record.person.full_name}. لا يمكن
            تغيير الشخص أو نوع الحالة.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => toUpdateHealthRecordPayload(record, values));
          }}
          className="flex flex-col gap-4"
        >
          {record.type === "DISABILITY" && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor={`${formId}-disabilityType`}>نوع الإعاقة</FieldLabel>
              <Controller
                control={control}
                name="disabilityTypeId"
                render={({ field }) => (
                  <DisabilityTypeSelect
                    id={`${formId}-disabilityType`}
                    value={field.value}
                    onChange={field.onChange}
                    keepOption={record.disability_type}
                  />
                )}
              />
              <FieldError message={errors.disabilityTypeId?.message} />
            </div>
          )}

          {record.type === "CHRONIC_DISEASE" && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor={`${formId}-conditionName`}>اسم المرض المزمن</FieldLabel>
              <Input id={`${formId}-conditionName`} {...register("conditionName")} />
              <FieldError message={errors.conditionName?.message} />
            </div>
          )}

          {isMaternalType(record.type) && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor={`${formId}-startedAt`} optional>
                تاريخ البداية
              </FieldLabel>
              <Input
                id={`${formId}-startedAt`}
                type="date"
                dir="ltr"
                className="text-end"
                {...register("startedAt")}
              />
              <FieldError message={errors.startedAt?.message} />
            </div>
          )}

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${formId}-details`} optional>
              {isMaternalType(record.type) ? "ملاحظات" : "التفاصيل"}
            </FieldLabel>
            <Textarea id={`${formId}-details`} rows={2} {...register("details")} />
            <FieldError message={errors.details?.message} />
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

// ---------------------------------------------------------------------------

export function CloseHealthRecordDialog({
  familyCode,
  record,
}: {
  familyCode: string;
  record: HealthRecord;
}) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<CloseHealthRecordValues>({
    resolver: zodResolver(closeHealthRecordSchema),
    defaultValues: { endedAt: "" },
  });
  const flow = useGuardedSave({
    mutation: useCloseHealthRecord(familyCode, record.id),
    apiFieldToFormField: closeHealthApiFieldToFormField,
    setError,
    statusMessages: HEALTH_STATUS_MESSAGES,
  });
  const formId = `close-health-record-${record.id}`;

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset({ endedAt: "" });
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm" aria-label="إغلاق السجل">
          <CircleCheck className="size-4" />
          إغلاق
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إغلاق السجل</DialogTitle>
          <DialogDescription>
            {healthRecordTypeLabels[record.type]} — {record.person.full_name}. يُسجَّل
            تاريخ انتهاء الحالة، ويبقى السجل محفوظًا ولا يُحذف.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({ ended_at: values.endedAt || null }));
          }}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${formId}-endedAt`} optional>
              تاريخ الانتهاء
            </FieldLabel>
            <Input
              id={`${formId}-endedAt`}
              type="date"
              dir="ltr"
              className="text-end"
              {...register("endedAt")}
            />
            <p className="text-xs text-muted-foreground">
              إذا تُرك فارغًا يُستخدم تاريخ اليوم.
            </p>
            <FieldError message={errors.endedAt?.message} />
          </div>
        </form>

        <EditDialogFooter
          formId={formId}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel="إغلاق السجل"
        />
      </DialogContent>
    </Dialog>
  );
}
