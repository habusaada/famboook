"use client";

import { useRef, useState } from "react";
import { AlertTriangle, CheckCircle2, Loader2 } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { type FamilyNotificationOutcome, useFamilyNotificationMutation } from "@/lib/api/family-notifications";
import { familyNotificationErrorMessage } from "@/lib/utils/family-notification";

export type NotificationNotice = { tone: "success" | "info"; message: string };

/**
 * «تأكيد الاطلاع» for an IMPORTANT notification: an explicit confirmation —
 * opening the message is never an acknowledgement. One send in flight,
 * never retried; a failure keeps the dialog open with a safe message (the
 * detail is re-read, so after a 409 the page shows the current state).
 */
export function AcknowledgeDialog({ id, onDone }: { id: string; onDone: (notice: NotificationNotice) => void }) {
  const mutation = useFamilyNotificationMutation(id, "acknowledge");
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const sending = useRef(false);

  function confirm() {
    if (sending.current) return;
    sending.current = true;
    setError(null);
    mutation.mutate(undefined, {
      onSettled: () => {
        sending.current = false;
      },
      onSuccess: (outcome: FamilyNotificationOutcome) => {
        setOpen(false);
        onDone(
          outcome.replayed
            ? { tone: "info", message: "سبق تأكيد الاطلاع على هذا الإشعار." }
            : { tone: "success", message: "تم تأكيد اطلاعك على الإشعار." }
        );
      },
      onError: (e) => setError(familyNotificationErrorMessage(e)),
    });
  }

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
        <Button className="h-11 w-full gap-2 rounded-xl sm:w-auto" data-family-notification-action="acknowledge">
          <CheckCircle2 className="size-4" aria-hidden />
          تأكيد الاطلاع
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>تأكيد الاطلاع على الإشعار؟</DialogTitle>
          <DialogDescription>
            بالتأكيد تُقرّ بأنك قرأت هذا الإشعار المهم واطلعت على مضمونه. يُسجَّل التأكيد مرة واحدة ولا يمكن التراجع عنه.
          </DialogDescription>
        </DialogHeader>
        {error && (
          <Alert variant="destructive" data-family-notification-error>
            <AlertTriangle className="size-4" />
            <AlertTitle>لم يُنفَّذ التأكيد</AlertTitle>
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            رجوع
          </Button>
          <Button type="button" onClick={confirm} disabled={mutation.isPending} data-family-notification-confirm="acknowledge">
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            تأكيد الاطلاع
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
