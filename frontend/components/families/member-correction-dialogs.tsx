"use client";

import { useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Info, Link2, MoreHorizontal, UserRoundX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  EditDialogFooter,
  FieldError,
  FieldLabel,
  SaveError,
} from "@/components/shared/edit-dialog-parts";
import { useAuth } from "@/components/auth/auth-context";
import { useCorrectMemberRelationship, useEndMembership } from "@/lib/api/families";
import { useRelationshipTypes } from "@/lib/api/reference";
import { useGuardedSave } from "@/lib/hooks/use-guarded-save";
import { relationshipLabel } from "@/lib/utils/relationship";
import type { FamilyMemberDetail } from "@/lib/types/api/family";

// Data-entry corrections of a current member (Pilot Readiness Slice C).
// UX only: the API authorizes (family-membership.update / .end) and keeps
// the household-head invariants.

type Dialogs = "relationship" | "end" | null;

/**
 * The row's "⋯" menu. Rendered only when the user holds at least one of
 * the correction permissions. Clicks inside (including the dialogs, whose
 * React events bubble through the portal) never reach the row, which
 * would otherwise navigate to the Person.
 */
export function MemberCorrectionMenu({
  familyCode,
  member,
}: {
  familyCode: string;
  member: FamilyMemberDetail;
}) {
  const { can } = useAuth();
  const [dialog, setDialog] = useState<Dialogs>(null);
  const canCorrect = can("family-membership.update") && !member.is_household_head;
  const canEnd = can("family-membership.end");

  if (!can("family-membership.update") && !canEnd) return null;

  return (
    <div onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()}>
      <DropdownMenu dir="rtl" modal={false}>
        <DropdownMenuTrigger asChild>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            aria-label={`إجراءات تصحيح: ${member.full_name}`}
            data-member-actions={member.person_code}
          >
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-56">
          {can("family-membership.update") && (
            <DropdownMenuItem disabled={!canCorrect} onSelect={() => setDialog("relationship")}>
              <Link2 />
              {member.is_household_head ? "صلة رب الأسرة ثابتة" : "تصحيح صلة القرابة"}
            </DropdownMenuItem>
          )}
          {canEnd && (
            <DropdownMenuItem
              variant="destructive"
              disabled={member.is_household_head}
              onSelect={() => setDialog("end")}
            >
              <UserRoundX />
              {member.is_household_head ? "لا يمكن إنهاء عضوية رب الأسرة" : "إنهاء العضوية في الأسرة"}
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>

      {/* Mounted only while open, so each opening starts from saved data. */}
      {dialog === "relationship" && (
        <CorrectRelationshipDialog familyCode={familyCode} member={member} onClose={() => setDialog(null)} />
      )}
      {dialog === "end" && (
        <EndMembershipDialog familyCode={familyCode} member={member} onClose={() => setDialog(null)} />
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------

const relationshipSchema = z.object({
  relationshipTypeId: z.string().min(1, "يرجى اختيار صلة القرابة"),
});
type RelationshipValues = z.infer<typeof relationshipSchema>;

function CorrectRelationshipDialog({
  familyCode,
  member,
  onClose,
}: {
  familyCode: string;
  member: FamilyMemberDetail;
  onClose: () => void;
}) {
  const { data, isLoading } = useRelationshipTypes();
  // HEAD belongs only to the household head; changing the head is a
  // separate operation, so it is never offered here.
  const options = (data?.data ?? []).filter((type) => type.code !== "HEAD");
  const {
    control,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RelationshipValues>({
    resolver: zodResolver(relationshipSchema),
    defaultValues: {
      relationshipTypeId: member.relationship_type ? String(member.relationship_type.id) : "",
    },
  });
  const mutation = useCorrectMemberRelationship(familyCode, member.person_code);
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField: { relationship_type_id: "relationshipTypeId" },
    setError,
    statusMessages: {
      403: "لا تملك صلاحية تصحيح صلة القرابة.",
      409: "هذا الشخص لم يعد فردًا حاليًا في هذه الأسرة. حدّث الصفحة.",
    },
    onSuccess: onClose,
  });
  const formId = `correct-relationship-${member.person_code}`;

  return (
    <Dialog open onOpenChange={(next) => !next && !flow.isPending && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تصحيح صلة القرابة</DialogTitle>
          <DialogDescription>
            تصحيح صلة <span className="font-medium text-foreground">{member.full_name}</span> برب الأسرة.
            لا يغيّر رب الأسرة ولا بيانات الشخص.
          </DialogDescription>
        </DialogHeader>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({ relationship_type_id: Number(values.relationshipTypeId) }));
          }}
          className="flex flex-col gap-4"
        >
          <p className="text-sm text-muted-foreground">
            الصلة الحالية:{" "}
            <span className="font-medium text-foreground">
              {relationshipLabel(member.relationship_type, member.gender)}
            </span>
          </p>
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`${formId}-type`}>الصلة الصحيحة</FieldLabel>
            <Controller
              control={control}
              name="relationshipTypeId"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange} disabled={isLoading}>
                  <SelectTrigger id={`${formId}-type`}>
                    <SelectValue placeholder={isLoading ? "جارٍ التحميل..." : "اختر صلة القرابة"} />
                  </SelectTrigger>
                  <SelectContent>
                    {options.map((type) => (
                      <SelectItem key={type.id} value={String(type.id)}>
                        {relationshipLabel(type, member.gender)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError message={errors.relationshipTypeId?.message} />
          </div>
        </form>

        <EditDialogFooter formId={formId} isPending={flow.isPending} onCancel={onClose} submitLabel="حفظ التصحيح" />
      </DialogContent>
    </Dialog>
  );
}

// ---------------------------------------------------------------------------

const endSchema = z.object({
  reason: z
    .string()
    .trim()
    .min(3, "يرجى كتابة سبب واضح (3 أحرف على الأقل)")
    .max(255, "السبب طويل جدًا (255 حرفًا كحد أقصى)"),
});
type EndValues = z.infer<typeof endSchema>;

function EndMembershipDialog({
  familyCode,
  member,
  onClose,
}: {
  familyCode: string;
  member: FamilyMemberDetail;
  onClose: () => void;
}) {
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<EndValues>({ resolver: zodResolver(endSchema), defaultValues: { reason: "" } });
  const mutation = useEndMembership(familyCode, member.person_code);
  const flow = useGuardedSave({
    mutation,
    apiFieldToFormField: { reason: "reason" },
    setError,
    statusMessages: {
      403: "لا تملك صلاحية إنهاء العضوية.",
      409: "لا يمكن إنهاء هذه العضوية: الشخص رب الأسرة الحالي أو لم يعد فردًا حاليًا فيها.",
    },
    onSuccess: onClose,
  });
  const formId = `end-membership-${member.person_code}`;

  return (
    <Dialog open onOpenChange={(next) => !next && !flow.isPending && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إنهاء العضوية في الأسرة</DialogTitle>
          <DialogDescription>
            إنهاء عضوية <span className="font-medium text-foreground">{member.full_name}</span> في هذه الأسرة.
          </DialogDescription>
        </DialogHeader>

        <Alert>
          <Info className="size-4" />
          <AlertDescription className="flex flex-col gap-1">
            <span>
              <strong>لا يحذف هذا الإجراء الشخص.</strong> يبقى الشخص مسجلًا في النظام، وتبقى عضويته
              السابقة محفوظة في السجل.
            </span>
            <span>لن يظهر ضمن أفراد الأسرة الحاليين، ولن يُضاف تلقائيًا إلى أسرة أخرى.</span>
          </AlertDescription>
        </Alert>

        <SaveError message={flow.submitError} />

        <form
          id={formId}
          onSubmit={(e) => {
            e.preventDefault();
            flow.submit(handleSubmit, (values) => ({ reason: values.reason.trim() }));
          }}
          className="flex flex-col gap-1.5"
        >
          <FieldLabel htmlFor={`${formId}-reason`}>سبب التصحيح</FieldLabel>
          <Textarea
            id={`${formId}-reason`}
            rows={2}
            placeholder="مثال: أُضيف إلى أسرة خاطئة أثناء الإدخال"
            {...register("reason")}
          />
          <FieldError message={errors.reason?.message} />
        </form>

        <EditDialogFooter formId={formId} isPending={flow.isPending} onCancel={onClose} submitLabel="تأكيد إنهاء العضوية" />
      </DialogContent>
    </Dialog>
  );
}
