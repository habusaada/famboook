"use client";

import { useRef, useState } from "react";
import { Controller, type UseFormRegisterReturn, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertTriangle, CheckSquare, Loader2, PhoneCall, Send } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { FieldError, FieldLabel } from "@/components/shared/edit-dialog-parts";
import { ApiError } from "@/lib/api/client";
import {
  FOLLOWUP_CLOSE_REASONS,
  FOLLOWUP_CONTACT_OUTCOMES,
  FOLLOWUP_NOTE_MAX,
  type FollowupCloseReason,
  type FollowupContactOutcome,
  type FollowupSide,
  useCloseFollowup,
  useRecordFollowupContact,
} from "@/lib/api/followups";
import {
  type FollowupCloseFormValues,
  type FollowupContactFormValues,
  followupCloseSchema,
  followupContactSchema,
  noteValue,
} from "@/lib/schemas/followup";
import { NOTE_GUIDANCE, closeReasonLabels, contactOutcomeLabels, followupErrorCode, followupErrorMessage } from "@/lib/utils/followup";

export type FollowupNotice = { tone: "success" | "info" | "warning"; message: string };

/** A 409 means the follow-up moved (closed, expired, owner changed): the page shows it, not the form. */
const moved = (error: unknown) => error instanceof ApiError && error.status === 409 && followupErrorCode(error) !== "FOLLOWUP_CLIENT_REFERENCE_CONFLICT";

function NoteField({ id, value, error, register }: { id: string; value: string; error?: string; register: UseFormRegisterReturn }) {
  return (
    <div className="flex flex-col gap-1.5">
      <FieldLabel htmlFor={id} optional>
        ملاحظة داخلية
      </FieldLabel>
      <Textarea id={id} rows={3} dir="auto" maxLength={FOLLOWUP_NOTE_MAX} aria-invalid={Boolean(error)} aria-describedby={`${id}-guidance`} {...register} />
      <div className="flex items-start justify-between gap-3">
        <p id={`${id}-guidance`} className="text-xs leading-relaxed text-muted-foreground" data-followup-note-guidance>
          {NOTE_GUIDANCE}
        </p>
        <span className="shrink-0 text-xs text-subtle-foreground tabular-nums" aria-hidden>
          {value.length.toLocaleString("ar")}/{FOLLOWUP_NOTE_MAX.toLocaleString("ar")}
        </span>
      </div>
      <FieldError message={error} />
    </div>
  );
}

/**
 * Record a contact attempt. A contact never closes the follow-up and is
 * never the family's acknowledgement.
 *
 * Idempotency: ONE client_reference per contact — a retry of the same
 * outcome and note (after a lost response, a 503 or a 429) re-sends the SAME
 * reference, so the server replays it and never records it twice; a changed
 * outcome or note is a different contact and gets a new reference. Never
 * re-sent automatically. A success clears the reference: the next contact is
 * a new one.
 */
export function FollowupContactForm({ side, id, onNotice }: { side: FollowupSide; id: string; onNotice: (notice: FollowupNotice) => void }) {
  const mutation = useRecordFollowupContact(side, id);
  const form = useForm<FollowupContactFormValues>({ resolver: zodResolver(followupContactSchema), defaultValues: { outcome: "", note: "" } });
  const note = useWatch({ control: form.control, name: "note" }) ?? "";
  const attempt = useRef<{ key: string; reference: string } | null>(null);
  const sending = useRef(false);
  const [error, setError] = useState<{ message: string; retry: boolean } | null>(null);

  function send(values: FollowupContactFormValues) {
    if (sending.current) return;
    const input = { outcome: values.outcome as FollowupContactOutcome, note: noteValue(values.note) };
    const key = JSON.stringify(input);
    if (attempt.current?.key !== key) attempt.current = { key, reference: crypto.randomUUID() };

    sending.current = true;
    setError(null);
    mutation.mutate(
      { ...input, client_reference: attempt.current.reference },
      {
        onSettled: () => {
          sending.current = false;
        },
        onSuccess: (result) => {
          attempt.current = null;
          form.reset({ outcome: "", note: "" });
          onNotice(
            result.replayed
              ? { tone: "info", message: "سبق تسجيل هذا التواصل؛ لم يُسجَّل مرة أخرى." }
              : { tone: "success", message: "سُجّل التواصل. تبقى المتابعة مفتوحة حتى تُغلق." }
          );
        },
        onError: (e) => {
          if (followupErrorCode(e) === "FOLLOWUP_CLIENT_REFERENCE_CONFLICT") attempt.current = null;
          if (moved(e)) {
            onNotice({ tone: "warning", message: followupErrorMessage(e, "contact") });
            return;
          }
          if (e instanceof ApiError && e.status === 422) {
            for (const [field, messages] of Object.entries(e.validationErrors ?? {})) {
              if ((field === "outcome" || field === "note") && messages[0]) form.setError(field, { type: "server", message: messages[0] });
            }
          }
          // The same contact may be re-sent as is: the same reference is kept.
          const retry = !(e instanceof ApiError) || e.status === 503 || e.status === 429;
          setError({ message: followupErrorMessage(e, "contact"), retry });
        },
      }
    );
  }

  const errors = form.formState.errors;
  return (
    <form onSubmit={(event) => void form.handleSubmit(send)(event)} noValidate className="flex flex-col gap-3" aria-label="تسجيل تواصل" data-followup-contact-form>
      <div className="flex flex-col gap-1.5">
        <FieldLabel htmlFor={`followup-contact-outcome-${id}`}>نتيجة التواصل</FieldLabel>
        <Controller
          control={form.control}
          name="outcome"
          render={({ field }) => (
            <Select value={field.value} onValueChange={field.onChange}>
              <SelectTrigger id={`followup-contact-outcome-${id}`} className="h-11! w-full sm:w-72" aria-invalid={Boolean(errors.outcome)} data-followup-contact-outcome>
                <SelectValue placeholder="اختر النتيجة" />
              </SelectTrigger>
              <SelectContent>
                {FOLLOWUP_CONTACT_OUTCOMES.map((o) => (
                  <SelectItem key={o} value={o}>
                    {contactOutcomeLabels[o]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        />
        <FieldError message={errors.outcome?.message} />
      </div>
      <NoteField id={`followup-contact-note-${id}`} value={note} error={errors.note?.message} register={form.register("note")} />
      {error && (
        <Alert variant="destructive" data-followup-contact-error>
          <AlertTriangle className="size-4" />
          <AlertTitle>لم يُسجَّل التواصل</AlertTitle>
          <AlertDescription>{error.message}</AlertDescription>
        </Alert>
      )}
      <Button type="submit" className="h-11 w-full gap-2 sm:w-auto sm:self-start" disabled={mutation.isPending} data-followup-contact-submit>
        {mutation.isPending ? <Loader2 className="size-4 animate-spin" aria-hidden /> : error?.retry ? <Send className="size-4" aria-hidden /> : <PhoneCall className="size-4" aria-hidden />}
        {error?.retry ? "إعادة الإرسال" : "تسجيل التواصل"}
      </Button>
    </form>
  );
}

/**
 * Close a follow-up manually, after explicit confirmation: a reason code and
 * an optional note. At most one request in flight and NEVER retried
 * automatically; after any failure the follow-up is re-read (a lost response
 * may have closed it). Closing is never the family's view or acknowledgement.
 */
export function FollowupCloseDialog({ side, id, onNotice }: { side: FollowupSide; id: string; onNotice: (notice: FollowupNotice) => void }) {
  const mutation = useCloseFollowup(side, id);
  const form = useForm<FollowupCloseFormValues>({ resolver: zodResolver(followupCloseSchema), defaultValues: { reason: "", note: "" } });
  const note = useWatch({ control: form.control, name: "note" }) ?? "";
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const sending = useRef(false);

  function confirm(values: FollowupCloseFormValues) {
    if (sending.current) return;
    sending.current = true;
    setError(null);
    mutation.mutate(
      { reason: values.reason as FollowupCloseReason, note: noteValue(values.note) },
      {
        onSettled: () => {
          sending.current = false;
        },
        onSuccess: () => {
          setOpen(false);
          form.reset({ reason: "", note: "" });
          onNotice({ tone: "success", message: "أُغلقت المتابعة يدويًا." });
        },
        onError: (e) => {
          if (moved(e)) {
            setOpen(false);
            onNotice({ tone: "warning", message: followupErrorMessage(e, "close") });
            return;
          }
          if (e instanceof ApiError && e.status === 422) {
            for (const [field, messages] of Object.entries(e.validationErrors ?? {})) {
              if ((field === "reason" || field === "note") && messages[0]) form.setError(field, { type: "server", message: messages[0] });
            }
          }
          setError(followupErrorMessage(e, "close"));
        },
      }
    );
  }

  const errors = form.formState.errors;
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
        <Button variant="outline" className="h-11 w-full gap-2 sm:w-auto" data-followup-close-trigger>
          <CheckSquare className="size-4" aria-hidden />
          إغلاق المتابعة يدويًا
        </Button>
      </DialogTrigger>
      <DialogContent>
        <form onSubmit={(event) => void form.handleSubmit(confirm)(event)} noValidate className="flex flex-col gap-4" aria-label="إغلاق المتابعة يدويًا" data-followup-close-form>
          <DialogHeader>
            <DialogTitle>إغلاق المتابعة يدويًا؟</DialogTitle>
            <DialogDescription>
              تُغلق هذه المرحلة من المتابعة نهائيًا بنتيجة «أُغلقت يدويًا» ولا يمكن إعادة فتحها. لا يُعدّ الإغلاق اطلاعًا أو تأكيدًا من الأسرة، ولا يغيّر إحصاءات الإعلان.
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-1.5">
            <FieldLabel htmlFor={`followup-close-reason-${id}`}>سبب الإغلاق</FieldLabel>
            <Controller
              control={form.control}
              name="reason"
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id={`followup-close-reason-${id}`} className="h-11! w-full" aria-invalid={Boolean(errors.reason)} data-followup-close-reason>
                    <SelectValue placeholder="اختر السبب" />
                  </SelectTrigger>
                  <SelectContent>
                    {FOLLOWUP_CLOSE_REASONS.map((r) => (
                      <SelectItem key={r} value={r}>
                        {closeReasonLabels[r]}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError message={errors.reason?.message} />
          </div>
          <NoteField id={`followup-close-note-${id}`} value={note} error={errors.note?.message} register={form.register("note")} />
          {error && (
            <Alert variant="destructive" data-followup-close-error>
              <AlertTriangle className="size-4" />
              <AlertTitle>لم تُغلق المتابعة</AlertTitle>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
              رجوع
            </Button>
            <Button type="submit" variant="destructive" disabled={mutation.isPending} data-followup-close-confirm>
              {mutation.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />}
              تأكيد الإغلاق
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
