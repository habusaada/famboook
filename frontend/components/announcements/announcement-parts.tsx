"use client";

import { useRef, useState } from "react";
import { AlertTriangle, Loader2 } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from "@/components/ui/dialog";
import { DetailItem, DetailList } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import {
  type AnnouncementAction,
  type AnnouncementDetail,
  type AnnouncementLevel,
  type AnnouncementOutcome,
  type AnnouncementStatus,
  useAnnouncementAction,
} from "@/lib/api/announcements";
import { announcementErrorMessage, announcementStatusLabels, announcementStatusTones, statisticsBasisLabels } from "@/lib/utils/announcement";

// Shared pieces of announcement management (FB-NOTIFY-01C.3c), side-agnostic:
// the Staff screens now, the Coordinator screens later. Every state is text.

const fmt = (n: number) => n.toLocaleString("ar");

export function AnnouncementStatusBadge({ status }: { status: AnnouncementStatus }) {
  return (
    <StatusBadge tone={announcementStatusTones[status] ?? "neutral"}>
      <span data-announcement-status={status}>{announcementStatusLabels[status] ?? status}</span>
    </StatusBadge>
  );
}

export function AnnouncementLevelBadge({ level }: { level: AnnouncementLevel }) {
  return level === "IMPORTANT" ? (
    <StatusBadge tone="warning">
      <span data-announcement-level="IMPORTANT">مهم</span>
    </StatusBadge>
  ) : (
    <StatusBadge tone="neutral">
      <span data-announcement-level="NORMAL">عادي</span>
    </StatusBadge>
  );
}

/**
 * The server's aggregate statistics — never a family. "No activated account"
 * is shown apart from "available but unviewed" (ADR-03); acknowledgement
 * figures exist for IMPORTANT messages only.
 */
export function AnnouncementStatisticsPanel({ announcement }: { announcement: AnnouncementDetail }) {
  const { statistics, statistics_basis: basis } = announcement;
  if (!basis) return null;

  return (
    <div className="flex flex-col gap-3" data-announcement-statistics={basis}>
      <p className="text-[13px] text-muted-foreground">
        أساس الإحصاءات: <span className="font-medium text-foreground">{statisticsBasisLabels[basis]}</span>
      </p>
      {statistics ? (
        <DetailList>
          <DetailItem label="المستلمون">{fmt(statistics.recipients)}</DetailItem>
          <DetailItem label="أسر لديها حساب مفعّل">{fmt(statistics.with_account)}</DetailItem>
          <DetailItem label="أسر بلا حساب مفعّل">{fmt(statistics.without_account)}</DetailItem>
          <DetailItem label="اطّلعت على الإعلان">{fmt(statistics.viewed)}</DetailItem>
          <DetailItem label="لم تطّلع بعد">{fmt(statistics.not_viewed)}</DetailItem>
          {statistics.acknowledged !== null && <DetailItem label="أكّدت الاطلاع">{fmt(statistics.acknowledged)}</DetailItem>}
          {statistics.acknowledged_late !== null && <DetailItem label="أكّدت متأخرة">{fmt(statistics.acknowledged_late)}</DetailItem>}
          {statistics.pending_acknowledgement !== null && <DetailItem label="بانتظار التأكيد">{fmt(statistics.pending_acknowledgement)}</DetailItem>}
        </DetailList>
      ) : (
        <p className="text-sm text-muted-foreground" data-announcement-no-statistics>
          لا تتوفر إحصاءات على هذا الأساس.
        </p>
      )}
    </div>
  );
}

export type AnnouncementNotice = { tone: "success" | "info"; message: string };

type DialogCopy = {
  trigger: string;
  title: string;
  description: string;
  confirm: string;
  success: string;
  replayed: string;
  destructive?: boolean;
  icon: React.ReactNode;
};

/**
 * One confirmed action (publish / cancel / retry): explicit confirmation, at
 * most one request in flight, never retried. A failure — a 503 included —
 * keeps the dialog open with a safe message; the detail is re-read, so the
 * page always shows the server's current state.
 */
export function AnnouncementActionDialog({
  side,
  id,
  action,
  copy,
  onDone,
}: {
  side: Parameters<typeof useAnnouncementAction>[0];
  id: string;
  action: AnnouncementAction;
  copy: DialogCopy;
  onDone: (notice: AnnouncementNotice, outcome: AnnouncementOutcome) => void;
}) {
  const mutation = useAnnouncementAction(side, id);
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const sending = useRef(false);

  function confirm() {
    if (sending.current) return;
    sending.current = true;
    setError(null);
    mutation.mutate(action, {
      onSettled: () => {
        sending.current = false;
      },
      onSuccess: (outcome) => {
        setOpen(false);
        onDone({ tone: outcome.replayed ? "info" : "success", message: outcome.replayed ? copy.replayed : copy.success }, outcome);
      },
      onError: (e) => setError(announcementErrorMessage(e)),
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
        <Button variant={copy.destructive ? "outline" : "default"} className={copy.destructive ? "text-danger" : undefined} data-announcement-action={action}>
          {copy.icon}
          {copy.trigger}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{copy.title}</DialogTitle>
          <DialogDescription>{copy.description}</DialogDescription>
        </DialogHeader>
        {error && (
          <Alert variant="destructive" data-announcement-action-error>
            <AlertTriangle className="size-4" />
            <AlertTitle>لم يُنفَّذ الإجراء</AlertTitle>
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={mutation.isPending}>
            رجوع
          </Button>
          <Button
            type="button"
            variant={copy.destructive ? "destructive" : "default"}
            onClick={confirm}
            disabled={mutation.isPending}
            data-announcement-confirm={action}
          >
            {mutation.isPending && <Loader2 className="size-4 animate-spin" />}
            {copy.confirm}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Which actions the account may offer: the state's available_actions AND the permission. */
export function permittedActions(
  announcement: Pick<AnnouncementDetail, "available_actions" | "status">,
  can: { create: boolean; send: boolean }
): AnnouncementAction[] {
  return announcement.available_actions.filter((action) => {
    if (action === "PUBLISH" || action === "RETRY") return can.send;
    // CANCEL: create for a DRAFT; send as well once publication was requested.
    return can.create && (announcement.status === "DRAFT" || can.send);
  });
}
