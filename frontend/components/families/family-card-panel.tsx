"use client";

import { useState } from "react";
import { AlertCircle, IdCard, RefreshCw, ShieldOff, TriangleAlert } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { AppCard } from "@/components/shared/app-card";
import { Code, DetailItem, DetailList, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError } from "@/lib/api/client";
import {
  type StaffFamilyCard,
  type StaffRevokeReason,
  useIssueFamilyCard,
  useReissueFamilyCard,
  useRevokeFamilyCard,
  useStaffFamilyCard,
} from "@/lib/api/family-cards";
import { formatDateTime } from "@/lib/utils/date";
import { FAMILY_CARD_TITLE, familyCardRevokeReasonLabels, familyCardStatusLabels } from "@/lib/utils/family-card";

const STAFF_REASONS: StaffRevokeReason[] = ["ADMINISTRATIVE", "COMPROMISED"];

/** The server's fixed Arabic refusal, or a generic message. */
function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 403) return "لا تملك صلاحية تنفيذ هذا الإجراء.";
    if (error.message422) return error.message422;
  }
  return "تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.";
}

function ActionError({ error }: { error: unknown }) {
  if (!error) return null;
  return (
    <Alert variant="destructive">
      <AlertCircle className="size-4" />
      <AlertDescription>{errorMessage(error)}</AlertDescription>
    </Alert>
  );
}

/** A confirmation dialog around one card operation; closes only on success. */
function ConfirmAction({
  trigger,
  title,
  description,
  confirmLabel,
  destructive,
  pending,
  error,
  onConfirm,
  onOpen,
  children,
  confirmDisabled,
}: {
  trigger: React.ReactNode;
  title: string;
  description: string;
  confirmLabel: string;
  destructive?: boolean;
  pending: boolean;
  error: unknown;
  onConfirm: (close: () => void) => void;
  onOpen: () => void;
  children?: React.ReactNode;
  confirmDisabled?: boolean;
}) {
  const [open, setOpen] = useState(false);

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (pending) return;
        if (next) onOpen();
        setOpen(next);
      }}
    >
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {children}
        <ActionError error={error} />
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={pending}>
            إلغاء
          </Button>
          <Button
            type="button"
            variant={destructive ? "destructive" : "default"}
            disabled={pending || confirmDisabled}
            onClick={() => onConfirm(() => setOpen(false))}
          >
            {pending ? "جارٍ التنفيذ..." : confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function History({ history }: { history: StaffFamilyCard[] }) {
  const past = history.filter((card) => card.status !== "ACTIVE");
  if (past.length === 0) return null;

  return (
    <section className="mt-3" aria-labelledby="family-card-history-title">
      <h3 id="family-card-history-title" className="text-sm font-semibold">
        سجل البطاقات
      </h3>
      <ul className="mt-1 flex flex-col gap-1.5" data-family-card-history>
        {past.map((card) => (
          <li key={card.credential_number} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-[13px]">
            <Code>{card.credential_number}</Code>
            <span className="text-muted-foreground">
              {familyCardStatusLabels[card.status]}
              {card.revoke_reason && ` — ${familyCardRevokeReasonLabels[card.revoke_reason]}`}
              {card.revoked_at && ` · ${formatDateTime(card.revoked_at)}`}
              {card.revoked_by && ` · ${card.revoked_by}`}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

/**
 * «البطاقة الرقمية» on the Staff Family overview (PWA-8.2, docs/11
 * FP-ADR-070): the Family's card number, status, dates and history, and the
 * issue / revoke / reissue operations each behind its own family-card.*
 * permission (UX only — the API decides). Never a token or QR: Staff reprint
 * arrives with the PDF (PWA-8.3).
 */
export function FamilyCardPanel({ familyCode }: { familyCode: string }) {
  const { can } = useAuth();
  const canView = can("family-card.view");
  const query = useStaffFamilyCard(familyCode, canView);
  const issue = useIssueFamilyCard(familyCode);
  const revoke = useRevokeFamilyCard(familyCode);
  const reissue = useReissueFamilyCard(familyCode);
  const [reason, setReason] = useState<StaffRevokeReason | null>(null);

  if (!canView) return null;

  const state = query.data;
  const active = state?.active ?? null;

  const actions = state ? (
    <div className="flex flex-wrap gap-2">
      {!active && can("family-card.issue") && (
        <ConfirmAction
          trigger={
            <Button size="sm" variant="outline" data-family-card-issue>
              <IdCard className="size-4" />
              إصدار بطاقة
            </Button>
          }
          title="إصدار بطاقة الأسرة الرقمية"
          description="تُصدر بطاقة جديدة برقم بطاقة ورمز QR جديدين لهذه الأسرة."
          confirmLabel="إصدار البطاقة"
          pending={issue.isPending}
          error={issue.error}
          onOpen={() => issue.reset()}
          onConfirm={(close) => issue.mutate(undefined, { onSuccess: close })}
        />
      )}
      {active && can("family-card.reissue") && (
        <ConfirmAction
          trigger={
            <Button size="sm" variant="outline" data-family-card-reissue>
              <RefreshCw className="size-4" />
              إعادة إصدار البطاقة
            </Button>
          }
          title="إعادة إصدار البطاقة"
          description="سيُلغى رمز QR الحالي ورقم البطاقة، وتُصدر بطاقة جديدة برقم ورمز جديدين."
          confirmLabel="إعادة الإصدار"
          pending={reissue.isPending}
          error={reissue.error}
          onOpen={() => reissue.reset()}
          onConfirm={(close) => reissue.mutate(undefined, { onSuccess: close })}
        />
      )}
      {active && can("family-card.revoke") && (
        <ConfirmAction
          trigger={
            <Button size="sm" variant="destructive" data-family-card-revoke>
              <ShieldOff className="size-4" />
              إلغاء البطاقة
            </Button>
          }
          title="إلغاء البطاقة"
          description="لن يعود رمز QR الحالي صالحًا للتحقق."
          confirmLabel="تأكيد الإلغاء"
          destructive
          pending={revoke.isPending}
          error={revoke.error}
          confirmDisabled={reason === null}
          onOpen={() => {
            revoke.reset();
            setReason(null);
          }}
          onConfirm={(close) => reason && revoke.mutate({ reason }, { onSuccess: close })}
        >
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">سبب الإلغاء</legend>
            {STAFF_REASONS.map((option) => (
              <label key={option} className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-lg border px-3 text-sm has-[:checked]:border-primary">
                <input
                  type="radio"
                  name="family-card-revoke-reason"
                  value={option}
                  checked={reason === option}
                  onChange={() => setReason(option)}
                  className="size-4 accent-[var(--primary)]"
                />
                {familyCardRevokeReasonLabels[option]}
              </label>
            ))}
          </fieldset>
        </ConfirmAction>
      )}
    </div>
  ) : undefined;

  return (
    <AppCard aria-labelledby="family-card-panel-title" data-family-card-panel>
      <SectionHeader
        title={<span id="family-card-panel-title">{FAMILY_CARD_TITLE}</span>}
        description="بطاقة تحقق رقمية ضمن نظام Famboook، وليست وثيقة هوية رسمية."
        action={actions}
      />
      {state ? (
        <>
          {active ? (
            <DetailList className="mt-3">
              <DetailItem label="رقم البطاقة">
                <Code>{active.credential_number}</Code>
              </DetailItem>
              <DetailItem label="الحالة">
                <StatusBadge tone="success">{familyCardStatusLabels.ACTIVE}</StatusBadge>
              </DetailItem>
              <DetailItem label="تاريخ الإصدار">{formatDateTime(active.issued_at)}</DetailItem>
              <DetailItem label="أصدرها">{active.issued_by ?? "النظام"}</DetailItem>
            </DetailList>
          ) : (
            <p className="mt-3 text-sm text-muted-foreground" data-family-card-none>
              لا توجد بطاقة رقمية سارية لهذه الأسرة.
            </p>
          )}
          <History history={state.history} />
        </>
      ) : query.isError ? (
        <p className="mt-3 flex items-center gap-2 text-sm text-muted-foreground" role="alert">
          <TriangleAlert className="size-4" aria-hidden />
          تعذّر تحميل بيانات البطاقة.
        </p>
      ) : (
        <div className="mt-3 flex flex-col gap-2" aria-busy="true">
          <Skeleton className="h-5 w-48" />
          <Skeleton className="h-5 w-32" />
        </div>
      )}
    </AppCard>
  );
}
