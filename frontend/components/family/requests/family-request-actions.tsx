"use client";

import { useRef, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertTriangle, Loader2, MessageSquareReply, XCircle } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Textarea } from "@/components/ui/textarea";
import { FieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { ApiError } from "@/lib/api/client";
import {
  type FamilyChangeRequestAction,
  type FamilyChangeRequestMutation,
  familyChangeRequestErrorMessage,
  useFamilyChangeRequestMutation,
} from "@/lib/api/family-change-requests";
import {
  WORKFLOW_TEXT_MAX,
  resubmitFamilyChangeRequestSchema,
  type ResubmitFamilyChangeRequestValues,
} from "@/lib/schemas/change-request";
import type { ChangeRequestOutcome } from "@/lib/types/api/change-request";

export type FamilyActionNotice = { tone: "success" | "info"; message: string };

const SUCCESS: Record<FamilyChangeRequestAction, string> = {
  resubmit: "أُرسل الاستكمال إلى فريق المراجعة.",
  cancel: "أُلغي الطلب. لم يتغير سجل أسرتك.",
};

function noticeFor(action: FamilyChangeRequestAction, outcome: ChangeRequestOutcome): FamilyActionNotice {
  return outcome.replayed ? { tone: "info", message: "لم يتغير شيء: سبق تنفيذ هذا الإجراء على الطلب." } : { tone: "success", message: SUCCESS[action] };
}

type Mutation = ReturnType<typeof useFamilyChangeRequestMutation>;

/**
 * At most one send in flight, never retried. Any failure keeps the dialog
 * open with a safe message; the request and the history are re-read in the
 * background (onSettled), so after a 409 the page shows the current state.
 */
function useSend(mutation: Mutation, onDone: (notice: FamilyActionNotice) => void, onFieldError?: (message: string) => void) {
  const [error, setError] = useState<string | null>(null);
  const sending = useRef(false);

  function send(input: FamilyChangeRequestMutation, close: () => void) {
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
        const field = e instanceof ApiError && e.status === 422 ? e.validationErrors?.response?.[0] : undefined;
        if (field && onFieldError) onFieldError(field);
        setError(familyChangeRequestErrorMessage(e));
      },
    });
  }

  return { error, setError, send };
}

function ActionError({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <Alert variant="destructive" data-family-action-error>
      <AlertTriangle className="size-4" />
      <AlertTitle>لم يُنفَّذ الإجراء</AlertTitle>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}

/** «إرسال الاستكمال»: the reply to a clarification. The proposal itself never changes. */
export function ResubmitDialog({ mutation, onDone }: { mutation: Mutation; onDone: (n: FamilyActionNotice) => void }) {
  const [open, setOpen] = useState(false);
  const form = useForm<ResubmitFamilyChangeRequestValues>({
    resolver: zodResolver(resubmitFamilyChangeRequestSchema),
    defaultValues: { response: "" },
  });
  const { error, setError, send } = useSend(mutation, onDone, (message) => form.setError("response", { type: "server", message }));
  const response = useWatch({ control: form.control, name: "response" });

  const submit = form.handleSubmit((values) =>
    send({ action: "resubmit", response: values.response }, () => {
      setOpen(false);
      form.reset();
    })
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
        <Button className="h-11 w-full gap-2 rounded-xl sm:w-auto" data-family-action="resubmit">
          <MessageSquareReply className="size-4" aria-hidden />
          إرسال الاستكمال
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إرسال الاستكمال</DialogTitle>
          <DialogDescription>يُرسل ردّك إلى فريق المراجعة ليتابع مراجعة الطلب. لا يغيّر الرد التعديل المطلوب في الطلب.</DialogDescription>
        </DialogHeader>
        <form id="family-resubmit-form" onSubmit={submit} className="flex flex-col gap-2" noValidate>
          <div className="flex items-center justify-between gap-2">
            <FieldLabel htmlFor="family-resubmit-response">ردّك على طلب الاستكمال</FieldLabel>
            <span className="text-xs text-subtle-foreground tabular-nums" data-family-counter>
              {response.length.toLocaleString("ar")} / {WORKFLOW_TEXT_MAX.toLocaleString("ar")}
            </span>
          </div>
          <Textarea
            id="family-resubmit-response"
            rows={5}
            maxLength={WORKFLOW_TEXT_MAX}
            aria-invalid={Boolean(form.formState.errors.response)}
            aria-required
            {...form.register("response")}
          />
          <FieldError message={form.formState.errors.response?.message} />
        </form>
        <ActionError message={error} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            رجوع
          </Button>
          <Button type="submit" form="family-resubmit-form" disabled={mutation.isPending} data-family-confirm="resubmit">
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            إرسال الاستكمال
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** «إلغاء الطلب»: confirmation only. Cancelling never touches the registry. */
export function CancelDialog({ mutation, onDone }: { mutation: Mutation; onDone: (n: FamilyActionNotice) => void }) {
  const [open, setOpen] = useState(false);
  const { error, setError, send } = useSend(mutation, onDone);

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
        <Button variant="outline" className="h-11 w-full gap-2 rounded-xl text-danger sm:w-auto" data-family-action="cancel">
          <XCircle className="size-4" aria-hidden />
          إلغاء الطلب
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إلغاء الطلب؟</DialogTitle>
          <DialogDescription>يوقف الإلغاء مراجعة هذا الطلب نهائيًا، ولا يغيّر شيئًا في سجل أسرتك. يمكنك تقديم طلب جديد لاحقًا عند الحاجة.</DialogDescription>
        </DialogHeader>
        <ActionError message={error} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            رجوع
          </Button>
          <Button type="button" variant="destructive" onClick={() => send({ action: "cancel" }, () => setOpen(false))} disabled={mutation.isPending} data-family-confirm="cancel">
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            إلغاء الطلب
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
