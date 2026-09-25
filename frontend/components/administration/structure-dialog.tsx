"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import type { UseMutationResult } from "@tanstack/react-query";
import { Input } from "@/components/ui/input";
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
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";

// UX validation only — Laravel is authoritative (codes, uniqueness).
const schema = z.object({
  code: z.string(),
  name: z.string().max(150, "الاسم طويل جدًا"),
  sortOrder: z.string().regex(/^\d*$/, "الترتيب يجب أن يكون رقمًا صحيحًا"),
});

export type StructureValues = z.infer<typeof schema>;

const apiFieldToFormField = {
  code: "code",
  name: "name",
  sort_order: "sortOrder",
} as const;

/**
 * Create/edit dialog for a Clan, Branch Group or Branch. The code is
 * entered only at creation (it is immutable afterwards). A Branch Group's
 * name may be left empty (unnamed administrative container).
 */
export function StructureDialog<TPayload>({
  id,
  trigger,
  title,
  description,
  initial,
  withCode,
  nameRequired,
  withSortOrder,
  mutation,
  toPayload,
}: {
  id: string;
  trigger: React.ReactNode;
  title: string;
  description: string;
  initial: StructureValues;
  withCode: boolean;
  nameRequired: boolean;
  withSortOrder: boolean;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  mutation: UseMutationResult<any, Error, TPayload>;
  toPayload: (values: StructureValues) => TPayload;
}) {
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<StructureValues>({
    resolver: zodResolver(
      schema.superRefine((v, ctx) => {
        if (withCode && !v.code.trim()) {
          ctx.addIssue({ code: "custom", path: ["code"], message: "الرمز مطلوب" });
        }
        if (nameRequired && !v.name.trim()) {
          ctx.addIssue({ code: "custom", path: ["name"], message: "الاسم مطلوب" });
        }
      })
    ),
    defaultValues: initial,
  });
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField,
    setError,
    statusMessages: { 403: "لا تملك صلاحية إدارة العشائر والعائلات والفروع." },
  });

  return (
    <Dialog
      open={flow.open}
      onOpenChange={(next) => {
        if (next) reset(initial);
        flow.setOpen(next);
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id={id}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, toPayload);
          }}
          className="flex flex-col gap-4"
        >
          {withCode && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor={`${id}-code`}>الرمز</FieldLabel>
              <Input
                id={`${id}-code`}
                dir="ltr"
                className="text-end uppercase"
                placeholder="EXAMPLE_CODE"
                {...register("code", { setValueAs: (v: string) => v.trim().toUpperCase() })}
              />
              <p className="text-xs text-muted-foreground">
                أحرف إنجليزية كبيرة وأرقام و _ فقط. لا يمكن تغييره بعد الحفظ.
              </p>
              <FieldError message={errors.code?.message} />
            </div>
          )}

          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${id}-name`} optional={!nameRequired}>
              الاسم
            </FieldLabel>
            <Input id={`${id}-name`} {...register("name")} />
            {!nameRequired && (
              <p className="text-xs text-muted-foreground">
                يمكن ترك المجموعة بدون اسم؛ تُعرض حينها بأسماء فروعها.
              </p>
            )}
            <FieldError message={errors.name?.message} />
          </div>

          {withSortOrder && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor={`${id}-sort`} optional>
                الترتيب
              </FieldLabel>
              <Input
                id={`${id}-sort`}
                inputMode="numeric"
                dir="ltr"
                className="text-end"
                {...register("sortOrder")}
              />
              <FieldError message={errors.sortOrder?.message} />
            </div>
          )}
        </form>

        <EditDialogFooter
          formId={id}
          isPending={flow.isPending}
          onCancel={() => flow.setOpen(false)}
          submitLabel="حفظ"
        />
      </DialogContent>
    </Dialog>
  );
}
