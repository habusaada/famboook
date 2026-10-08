"use client";

import { useRef, useState } from "react";
import { useForm, useWatch, type Path, type UseFormSetError, type FieldValues } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertTriangle, CheckCircle2, ClipboardCheck, Loader2, Search, Undo2, XCircle } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { FieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { useAuth } from "@/components/auth/auth-context";
import { ApiError } from "@/lib/api/client";
import {
  changeRequestErrorMessage,
  useChangeRequestAction,
  type ChangeRequestActionInput,
} from "@/lib/api/change-requests";
import {
  REJECTION_REASONS,
  WORKFLOW_TEXT_MAX,
  rejectChangeRequestSchema,
  returnChangeRequestSchema,
  type RejectChangeRequestValues,
  type ReturnChangeRequestValues,
} from "@/lib/schemas/change-request";
import type { ChangeRequestAction, ChangeRequestDetail, ChangeRequestOutcome } from "@/lib/types/api/change-request";
import {
  changeRequestActionLabel,
  changeRequestActionPermissions,
  rejectionReasonLabels,
} from "@/lib/utils/change-request";

type Mutation = ReturnType<typeof useChangeRequestAction>;

const SUCCESS: Record<ChangeRequestAction, string> = {
  start_review: "بدأت مراجعة الطلب.",
  return: "أُعيد الطلب إلى الأسرة لاستكمال المعلومات.",
  approve: "اعتُمد الطلب. لم تتغير بيانات السجل بعد — طبّق التعديل عند الجاهزية.",
  reject: "رُفض الطلب.",
  apply: "طُبّق التعديل على سجل الأسرة.",
};

export type ActionNotice = { tone: "success" | "info"; message: string };

function noticeFor(action: ChangeRequestAction, outcome: ChangeRequestOutcome): ActionNotice {
  return outcome.replayed
    ? { tone: "info", message: "لم يتغير شيء: سبق تنفيذ هذا الإجراء على الطلب." }
    : { tone: "success", message: SUCCESS[action] };
}

/**
 * Send one action, at most one in flight. A 422 with field errors is mapped
 * onto the form; any other refusal is shown as a safe message and the
 * dialog stays open (the request itself is re-read in the background).
 */
function useActionSend<TValues extends FieldValues>(
  mutation: Mutation,
  onDone: (notice: ActionNotice) => void,
  fields: Record<string, Path<TValues>> = {},
  setFieldError?: UseFormSetError<TValues>
) {
  const [error, setError] = useState<string | null>(null);
  const sending = useRef(false);

  function send(input: ChangeRequestActionInput, close: () => void) {
    if (sending.current) return;
    sending.current = true;
    setError(null);
    mutation.mutate(input, {
      onSettled: () => {
        sending.current = false;
      },
      onSuccess: (outcome) => {
        close();
        onDone(noticeFor(input.action, outcome));
      },
      onError: (e) => {
        if (e instanceof ApiError && e.status === 422 && setFieldError) {
          for (const [apiField, messages] of Object.entries(e.validationErrors ?? {})) {
            const field = fields[apiField];
            if (field && messages[0]) setFieldError(field, { type: "server", message: messages[0] });
          }
        }
        setError(changeRequestErrorMessage(e));
      },
    });
  }

  return { error, setError, send };
}

function ActionError({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <Alert variant="destructive" data-action-error>
      <AlertTriangle className="size-4" />
      <AlertTitle>لم يُنفَّذ الإجراء</AlertTitle>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}

function Counter({ value }: { value: string }) {
  return (
    <span className="text-xs text-subtle-foreground tabular-nums">
      {value.length.toLocaleString("ar")} / {WORKFLOW_TEXT_MAX.toLocaleString("ar")}
    </span>
  );
}

// ------------------------------------------------------------------ confirm-only actions

function ConfirmActionDialog({
  action,
  mutation,
  onDone,
  title,
  description,
  warning,
  confirmLabel,
  icon: Icon,
}: {
  action: "approve" | "apply";
  mutation: Mutation;
  onDone: (notice: ActionNotice) => void;
  title: string;
  description: string;
  warning?: string;
  confirmLabel: string;
  icon: typeof CheckCircle2;
}) {
  const [open, setOpen] = useState(false);
  const { error, setError, send } = useActionSend(mutation, onDone);

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (mutation.isPending) return;
        setOpen(next);
        if (next) setError(null);
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant={action === "apply" ? "default" : "outline"} data-action={action}>
          <Icon className="size-4" />
          {confirmLabel}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {warning && (
          <Alert>
            <AlertTriangle className="size-4" />
            <AlertDescription>{warning}</AlertDescription>
          </Alert>
        )}
        <ActionError message={error} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button type="button" onClick={() => send({ action }, () => setOpen(false))} disabled={mutation.isPending} data-confirm={action}>
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ------------------------------------------------------------------ return for clarification

function ReturnDialog({ mutation, onDone, canWriteNote }: { mutation: Mutation; onDone: (n: ActionNotice) => void; canWriteNote: boolean }) {
  const [open, setOpen] = useState(false);
  const form = useForm<ReturnChangeRequestValues>({
    resolver: zodResolver(returnChangeRequestSchema),
    defaultValues: { publicMessage: "", internalNote: "" },
  });
  const { error, setError, send } = useActionSend<ReturnChangeRequestValues>(
    mutation,
    onDone,
    { public_message: "publicMessage", internal_note: "internalNote" },
    form.setError
  );
  const publicMessage = useWatch({ control: form.control, name: "publicMessage" });
  const internalNote = useWatch({ control: form.control, name: "internalNote" });

  const submit = form.handleSubmit((values) =>
    send(
      {
        action: "return",
        body: {
          public_message: values.publicMessage,
          ...(canWriteNote && values.internalNote.trim() ? { internal_note: values.internalNote } : {}),
        },
      },
      () => {
        setOpen(false);
        form.reset();
      }
    )
  );

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (mutation.isPending) return;
        setOpen(next);
        if (next) setError(null);
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant="outline" data-action="return">
          <Undo2 className="size-4" />
          {changeRequestActionLabel("return")}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إرجاع الطلب للاستكمال</DialogTitle>
          <DialogDescription>اطلب من الأسرة المعلومات الناقصة. لا تتغير البيانات المطلوبة ولا سجل الأسرة.</DialogDescription>
        </DialogHeader>
        <form id="return-change-request" onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="return-public-message">رسالة الاستيضاح للأسرة</FieldLabel>
            <p className="text-xs text-muted-foreground" id="return-public-hint">
              تظهر هذه الرسالة للأسرة كما هي.
            </p>
            <Textarea
              id="return-public-message"
              rows={4}
              aria-describedby="return-public-hint"
              aria-invalid={Boolean(form.formState.errors.publicMessage)}
              {...form.register("publicMessage")}
            />
            <div className="flex items-center justify-between">
              <FieldError message={form.formState.errors.publicMessage?.message} />
              <Counter value={publicMessage} />
            </div>
          </div>
          {canWriteNote && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="return-internal-note" optional>
                ملاحظة داخلية
              </FieldLabel>
              <p className="text-xs text-muted-foreground" id="return-internal-hint">
                للموظفين فقط — لا تظهر للأسرة.
              </p>
              <Textarea id="return-internal-note" rows={3} aria-describedby="return-internal-hint" {...form.register("internalNote")} />
              <div className="flex items-center justify-between">
                <FieldError message={form.formState.errors.internalNote?.message} />
                <Counter value={internalNote} />
              </div>
            </div>
          )}
          <ActionError message={error} />
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button type="submit" form="return-change-request" disabled={mutation.isPending} data-confirm="return">
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            إرجاع للاستكمال
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ------------------------------------------------------------------ reject

function RejectDialog({
  mutation,
  onDone,
  canWriteNote,
  approved,
}: {
  mutation: Mutation;
  onDone: (n: ActionNotice) => void;
  canWriteNote: boolean;
  /** An APPROVED request is rejected only as NO_LONGER_APPLICABLE (after a refused apply). */
  approved: boolean;
}) {
  const [open, setOpen] = useState(false);
  const reasons = approved ? (["NO_LONGER_APPLICABLE"] as const) : REJECTION_REASONS;
  const form = useForm<RejectChangeRequestValues>({
    resolver: zodResolver(rejectChangeRequestSchema),
    defaultValues: { reason: approved ? "NO_LONGER_APPLICABLE" : "", publicMessage: "", internalNote: "" },
  });
  const { error, setError, send } = useActionSend<RejectChangeRequestValues>(
    mutation,
    onDone,
    { rejection_reason_code: "reason", public_message: "publicMessage", internal_note: "internalNote" },
    form.setError
  );
  const reason = useWatch({ control: form.control, name: "reason" });
  const publicMessage = useWatch({ control: form.control, name: "publicMessage" });

  const submit = form.handleSubmit((values) =>
    send(
      {
        action: "reject",
        body: {
          rejection_reason_code: values.reason,
          ...(values.publicMessage.trim() ? { public_message: values.publicMessage } : {}),
          ...(canWriteNote && values.internalNote.trim() ? { internal_note: values.internalNote } : {}),
        },
      },
      () => {
        setOpen(false);
        form.reset();
      }
    )
  );

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (mutation.isPending) return;
        setOpen(next);
        if (next) setError(null);
      }}
    >
      <DialogTrigger asChild>
        <Button size="sm" variant="destructive" data-action="reject">
          <XCircle className="size-4" />
          {changeRequestActionLabel("reject")}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>رفض الطلب</DialogTitle>
          <DialogDescription>
            {approved
              ? "تعذّر تطبيق هذا الطلب المعتمد لأنه لم يعد قابلًا للتنفيذ. يُرفض بهذا السبب وحده."
              : "الرفض نهائي. يمكن للأسرة تقديم طلب جديد إذا لزم."}
          </DialogDescription>
        </DialogHeader>
        <form id="reject-change-request" onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="reject-reason">سبب الرفض</FieldLabel>
            <Select value={reason} onValueChange={(value) => form.setValue("reason", value, { shouldValidate: true })} disabled={approved}>
              <SelectTrigger id="reject-reason" className="w-full" aria-invalid={Boolean(form.formState.errors.reason)}>
                <SelectValue placeholder="اختر السبب" />
              </SelectTrigger>
              <SelectContent>
                {reasons.map((code) => (
                  <SelectItem key={code} value={code}>
                    {rejectionReasonLabels[code]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldError message={form.formState.errors.reason?.message} />
          </div>
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor="reject-public-message" optional={reason !== "OTHER"}>
              رسالة الرفض للأسرة
            </FieldLabel>
            <p className="text-xs text-muted-foreground" id="reject-public-hint">
              تظهر هذه الرسالة للأسرة كما هي{reason === "OTHER" ? "، وهي مطلوبة عند اختيار «سبب آخر»" : ""}.
            </p>
            <Textarea id="reject-public-message" rows={3} aria-describedby="reject-public-hint" {...form.register("publicMessage")} />
            <div className="flex items-center justify-between">
              <FieldError message={form.formState.errors.publicMessage?.message} />
              <Counter value={publicMessage} />
            </div>
          </div>
          {canWriteNote && (
            <div className="flex flex-col gap-1.5">
              <FieldLabel htmlFor="reject-internal-note" optional>
                ملاحظة داخلية
              </FieldLabel>
              <p className="text-xs text-muted-foreground" id="reject-internal-hint">
                للموظفين فقط — لا تظهر للأسرة.
              </p>
              <Textarea id="reject-internal-note" rows={3} aria-describedby="reject-internal-hint" {...form.register("internalNote")} />
              <FieldError message={form.formState.errors.internalNote?.message} />
            </div>
          )}
          <ActionError message={error} />
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            إلغاء
          </Button>
          <Button type="submit" form="reject-change-request" variant="destructive" disabled={mutation.isPending} data-confirm="reject">
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            رفض الطلب
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ------------------------------------------------------------------ the action bar

/**
 * The workflow actions offered on a request: the server's available_actions
 * (status, permissions, handler availability, the refused-apply rule),
 * intersected with the user's permissions. A UX layer only — every action is
 * re-authorized and re-checked by the API and the Domain Action.
 */
export function ChangeRequestActions({ request, onNotice }: { request: ChangeRequestDetail; onNotice: (n: ActionNotice | null) => void }) {
  const { can } = useAuth();
  const mutation = useChangeRequestAction(request.id, request.family.family_code);
  const [startError, setStartError] = useState<string | null>(null);
  const startSending = useRef(false);
  const actions = request.available_actions.filter((action) => can(changeRequestActionPermissions[action]));
  const canWriteNote = can("change-request.view-internal-notes");
  const retry = request.apply_failures.count > 0;

  if (actions.length === 0) {
    return <p className="text-sm text-muted-foreground" data-no-actions>لا توجد إجراءات متاحة لك على هذا الطلب في حالته الحالية.</p>;
  }

  function startReview() {
    if (startSending.current) return;
    startSending.current = true;
    setStartError(null);
    onNotice(null);
    mutation.mutate(
      { action: "start_review" },
      {
        onSettled: () => {
          startSending.current = false;
        },
        onSuccess: (outcome) => onNotice(noticeFor("start_review", outcome)),
        onError: (e) => setStartError(changeRequestErrorMessage(e)),
      }
    );
  }

  return (
    <div className="flex flex-col gap-3" data-actions>
      <div className="flex flex-wrap items-center gap-2">
        {actions.includes("start_review") && (
          <Button size="sm" onClick={startReview} disabled={mutation.isPending} data-action="start_review">
            {mutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Search className="size-4" />}
            {changeRequestActionLabel("start_review")}
          </Button>
        )}
        {actions.includes("return") && <ReturnDialog mutation={mutation} onDone={onNotice} canWriteNote={canWriteNote} />}
        {actions.includes("approve") && (
          <ConfirmActionDialog
            action="approve"
            mutation={mutation}
            onDone={onNotice}
            icon={ClipboardCheck}
            title="اعتماد الطلب"
            description="اعتماد الطلب لا يغيّر بيانات السجل مباشرة. يجب تطبيق التعديل بعد الاعتماد."
            confirmLabel={changeRequestActionLabel("approve")}
          />
        )}
        {actions.includes("apply") && (
          <ConfirmActionDialog
            action="apply"
            mutation={mutation}
            onDone={onNotice}
            icon={CheckCircle2}
            title={retry ? "إعادة محاولة تطبيق التعديل" : "تطبيق التعديل على سجل الأسرة"}
            description="عند النجاح يُحدَّث سجل الأسرة الرسمي بالبيانات المعتمدة. يتحقق النظام أولًا من أن البيانات المسجلة لم تتغير منذ تقديم الطلب."
            warning="هذا الإجراء يغيّر بيانات السجل الرسمية ولا يُعاد تلقائيًا عند الفشل."
            confirmLabel={changeRequestActionLabel("apply", retry)}
          />
        )}
        {actions.includes("reject") && (
          <RejectDialog mutation={mutation} onDone={onNotice} canWriteNote={canWriteNote} approved={request.status === "APPROVED"} />
        )}
      </div>
      <ActionError message={startError} />
    </div>
  );
}
