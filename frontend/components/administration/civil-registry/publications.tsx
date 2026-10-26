"use client";

import { useState } from "react";
import Link from "next/link";
import { Loader2, ShieldAlert, Stamp } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { EmptyState } from "@/components/shared/empty-state";
import { DetailItem, DetailList, PageHeader, Panel, SectionHeader } from "@/components/shared/page-layout";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { StatusBadge } from "@/components/shared/status-badge";
import { useAuth } from "@/components/auth/auth-context";
import { cn } from "@/lib/utils";
import {
  useCivilImportOverview,
  useCivilImportPublication,
  useCivilImportPublications,
  useDecideCivilImportPublication,
} from "@/lib/api/civil-registry";
import type {
  CivilImportPublicationRequest,
  CivilImportPublicationStatus,
  CivilImportRejectionReason,
} from "@/lib/types/api/civil-registry";
import { AuditTimeline } from "@/components/administration/civil-registry/audit-history";
import { CivilRegistryNav, LoadingBlock, MonitorError, Num, ShortId } from "@/components/administration/civil-registry/parts";
import { CommandError } from "@/components/administration/civil-registry/run-actions";
import {
  COUNT_LABELS,
  JUSTIFICATION_LABELS,
  PUBLICATION_OUTCOME_LABELS,
  PUBLICATION_STATUS_LABELS,
  PUBLICATION_STATUS_TONES,
  REJECTION_LABELS,
  REQUEST_STATUS_LABELS,
  dateLabel,
  label,
} from "@/components/administration/civil-registry/labels";

// FB-CIVIL-01A-UI U3 — two-person publication approval (ADR §3.3, D-UI-6/11/12).
// Nothing here publishes: an approval queues the worker's PUBLISH request,
// which re-checks every condition (S-3). Codes, counts and names only.

export function PublicationStatusBadge({ status }: { status: CivilImportPublicationStatus }) {
  return (
    <span className="inline-flex" data-publication-status={status}>
      <StatusBadge tone={PUBLICATION_STATUS_TONES[status] ?? "neutral"}>{label(PUBLICATION_STATUS_LABELS, status)}</StatusBadge>
    </span>
  );
}

/** Time left before a pending request expires (D-UI-11), in whole hours / minutes. */
export function expiryLabel(p: Pick<CivilImportPublicationRequest, "status" | "expires_at">, now = Date.now()): string {
  if (p.status !== "PENDING_APPROVAL" || !p.expires_at) return "—";
  const minutes = Math.floor((new Date(p.expires_at).getTime() - now) / 60000);
  if (minutes <= 0) return "انتهت المهلة";
  const hours = Math.floor(minutes / 60);
  return hours > 0 ? `ينتهي خلال ${hours.toLocaleString("ar")} ساعة` : `ينتهي خلال ${minutes.toLocaleString("ar")} دقيقة`;
}

/** Always visible on the approval screens: publication on Production is disabled, and why approval may be unavailable. */
export function PublicationGateNotice() {
  const overview = useCivilImportOverview();
  const open = overview.data?.publication_enabled === true;

  return (
    <div
      className={cn(
        "flex items-start gap-2 rounded-lg border p-3 text-sm",
        open ? "border-warning/40 bg-warning-soft" : "border-stroke-subtle bg-surface-2",
      )}
      role="note"
      data-publication-gate={open ? "open" : "closed"}
    >
      <ShieldAlert className={cn("mt-0.5 size-4 shrink-0", open ? "text-warning" : "text-muted-foreground")} aria-hidden />
      <div className="flex flex-col gap-0.5">
        <p className="font-semibold">النشر على بيئة الإنتاج معطّل.</p>
        <p className="text-muted-foreground">
          {open
            ? "بوابة النشر مفتوحة في هذه البيئة فقط. يرفض عامل الاستيراد أي نشر دون بوابته المفتوحة ومرجع تفويض بالبيانات الحقيقية."
            : "بوابة النشر مغلقة: لا يمكن اعتماد أي طلب نشر. يمكن تقديم الطلبات ومراجعتها وسحبها."}
        </p>
      </div>
    </div>
  );
}

const FILTERS: (CivilImportPublicationStatus | null)[] = [
  null,
  "PENDING_APPROVAL",
  "APPROVED",
  "EXECUTED",
  "FAILED",
  "REJECTED",
  "WITHDRAWN",
  "EXPIRED",
];

/** /administration/civil-registry/approvals — the approval queue and the publication history. */
export function PublicationQueue() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<CivilImportPublicationStatus | null>(null);
  const list = useCivilImportPublications(status, page);

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="الموافقات وسجل النشر" description="طلبات نشر إصدارات مرجع الهوية المدنية: يقدّمها مستخدم ويعتمدها مستخدم آخر." />
      <CivilRegistryNav />
      <PublicationGateNotice />
      <div className="flex flex-wrap gap-1.5" role="group" aria-label="تصفية حسب الحالة">
        {FILTERS.map((f) => (
          <button
            key={f ?? "all"}
            type="button"
            aria-pressed={status === f}
            onClick={() => {
              setStatus(f);
              setPage(1);
            }}
            className={cn(
              "rounded-control border px-2.5 py-1 text-[13px] transition-colors",
              status === f ? "border-brand-600 bg-brand-50 font-semibold text-brand-700" : "border-stroke-subtle hover:bg-surface-hover",
            )}
          >
            {f ? PUBLICATION_STATUS_LABELS[f] : "الكل"}
          </button>
        ))}
      </div>
      <Panel flush>
        {list.isPending ? (
          <div className="p-4">
            <LoadingBlock />
          </div>
        ) : list.isError ? (
          <MonitorError error={list.error} onRetry={() => list.refetch()} />
        ) : list.data.data.length === 0 ? (
          <EmptyState icon={Stamp} title="لا توجد طلبات نشر" description="يُقدَّم طلب النشر من صفحة عملية استيراد متحقق منها." />
        ) : (
          <>
            <Table data-publications-table>
              <TableHeader>
                <TableRow>
                  <TableHead>الطلب</TableHead>
                  <TableHead>الحالة</TableHead>
                  <TableHead>الإصدار</TableHead>
                  <TableHead>الحضور المتوقع</TableHead>
                  <TableHead>قدّمه</TableHead>
                  <TableHead>قرّره</TableHead>
                  <TableHead>المهلة</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {list.data.data.map((p) => (
                  <TableRow key={p.id} data-publication={p.id}>
                    <TableCell>
                      <Link href={`/administration/civil-registry/approvals/${p.id}`} className="text-brand-700 hover:underline">
                        <ShortId id={p.id} />
                      </Link>
                    </TableCell>
                    <TableCell>
                      <PublicationStatusBadge status={p.status} />
                    </TableCell>
                    <TableCell>
                      <Num value={p.release_id} />
                    </TableCell>
                    <TableCell>
                      <Num value={p.snapshot.expected_presence} />
                    </TableCell>
                    <TableCell>{p.requested_by?.name ?? "—"}</TableCell>
                    <TableCell>{p.decided_by?.name ?? "—"}</TableCell>
                    <TableCell>{expiryLabel(p)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <RegistryPagination meta={list.data.meta} onPage={setPage} unit="طلب" />
          </>
        )}
      </Panel>
    </div>
  );
}

type DialogKind = "APPROVE" | "REJECT" | "WITHDRAW" | null;

function DecisionDialog({
  kind,
  publication,
  onClose,
}: {
  kind: Exclude<DialogKind, null>;
  publication: CivilImportPublicationRequest;
  onClose: () => void;
}) {
  const decide = useDecideCivilImportPublication();
  const [release, setRelease] = useState("");
  const [presence, setPresence] = useState("");
  const [reason, setReason] = useState<CivilImportRejectionReason | null>(null);
  const [acknowledged, setAcknowledged] = useState(false);
  const titles = { APPROVE: "اعتماد النشر", REJECT: "رفض طلب النشر", WITHDRAW: "سحب طلب النشر" };
  const descriptions = {
    APPROVE:
      "سيُسجَّل اعتمادك، ويتولى عامل الاستيراد النشر بعد التحقق من كل الشروط من جديد. يصبح الإصدار المعتمد هو الإصدار النشط دفعة واحدة. للتأكيد اكتب رقم الإصدار والحضور المتوقع.",
    REJECT: "سيُغلق الطلب نهائيًا دون نشر. يلزم طلب جديد لاحقًا.",
    WITHDRAW: "سيُغلق طلبك نهائيًا دون نشر. يلزم طلب جديد لاحقًا.",
  };
  const ready =
    kind === "APPROVE" ? /^\d+$/.test(release) && /^\d+$/.test(presence) && acknowledged : kind === "REJECT" ? reason !== null : true;

  const submit = () => {
    const decision =
      kind === "APPROVE"
        ? { kind, id: publication.id, confirmReleaseId: Number(release), confirmExpectedPresence: Number(presence) }
        : kind === "REJECT"
          ? { kind, id: publication.id, reasonCode: reason as CivilImportRejectionReason }
          : { kind, id: publication.id };
    decide.mutate(decision, { onSuccess: onClose });
  };

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-w-lg" data-decision-confirm={kind}>
        <DialogHeader>
          <DialogTitle>{titles[kind]}</DialogTitle>
          <DialogDescription>{descriptions[kind]}</DialogDescription>
        </DialogHeader>
        {kind === "APPROVE" && (
          <div className="flex flex-col gap-3">
            <label className="flex flex-col gap-1 text-sm">
              رقم الإصدار
              <input
                inputMode="numeric"
                dir="ltr"
                className="h-9 rounded-md border border-input px-2"
                value={release}
                onChange={(e) => setRelease(e.target.value.trim())}
                aria-label="رقم الإصدار"
              />
            </label>
            <label className="flex flex-col gap-1 text-sm">
              الحضور المتوقع
              <input
                inputMode="numeric"
                dir="ltr"
                className="h-9 rounded-md border border-input px-2"
                value={presence}
                onChange={(e) => setPresence(e.target.value.trim())}
                aria-label="الحضور المتوقع"
              />
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4 accent-brand-600"
                checked={acknowledged}
                onChange={(e) => setAcknowledged(e.target.checked)}
              />
              راجعت الأعداد، وهذا قرار مستقل عن مقدّم الطلب.
            </label>
          </div>
        )}
        {kind === "REJECT" && (
          <fieldset className="flex flex-col gap-1.5">
            <legend className="mb-1 text-sm font-medium">سبب الرفض</legend>
            {(Object.keys(REJECTION_LABELS) as CivilImportRejectionReason[]).map((code) => (
              <label key={code} className="flex items-center gap-2 text-sm">
                <input
                  type="radio"
                  name="rejection"
                  className="size-4 accent-brand-600"
                  checked={reason === code}
                  onChange={() => setReason(code)}
                />
                {REJECTION_LABELS[code]}
              </label>
            ))}
          </fieldset>
        )}
        <CommandError error={decide.error} />
        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose}>
            رجوع
          </Button>
          <Button variant={kind === "APPROVE" ? "default" : "destructive"} disabled={!ready || decide.isPending} onClick={submit}>
            {decide.isPending && <Loader2 className="size-4 animate-spin" />}
            تأكيد
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** /administration/civil-registry/approvals/[id] — one publication request. */
export function PublicationDetail({ id }: { id: string }) {
  const { can } = useAuth();
  const publication = useCivilImportPublication(id);
  const [dialog, setDialog] = useState<DialogKind>(null);

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="طلب نشر"
        description={
          <bdi dir="ltr" className="font-mono text-xs">
            {id}
          </bdi>
        }
        actions={publication.data ? <PublicationStatusBadge status={publication.data.status} /> : undefined}
      />
      <CivilRegistryNav />
      <PublicationGateNotice />
      {publication.isPending ? (
        <LoadingBlock rows={6} />
      ) : publication.isError ? (
        <MonitorError error={publication.error} onRetry={() => publication.refetch()} />
      ) : (
        <>
          {(publication.data.can_approve || publication.data.can_reject || publication.data.can_withdraw) && (
            <div className="flex flex-wrap gap-2" data-publication-actions>
              {publication.data.can_approve && <Button onClick={() => setDialog("APPROVE")}>اعتماد النشر</Button>}
              {publication.data.can_reject && (
                <Button variant="destructive" onClick={() => setDialog("REJECT")}>
                  رفض
                </Button>
              )}
              {publication.data.can_withdraw && (
                <Button variant="outline" onClick={() => setDialog("WITHDRAW")}>
                  سحب الطلب
                </Button>
              )}
            </div>
          )}
          {publication.data.is_own && publication.data.status === "PENDING_APPROVAL" && (
            <p className="text-[13px] text-muted-foreground" data-own-request>
              هذا طلبك: يعتمده أو يرفضه مستخدم آخر مخوّل.
            </p>
          )}
          {dialog && <DecisionDialog kind={dialog} publication={publication.data} onClose={() => setDialog(null)} />}

          <Panel>
            <SectionHeader title="الطلب" />
            <DetailList className="mt-3">
              <DetailItem label="الإصدار">
                <Link
                  href={`/administration/civil-registry/releases/${publication.data.release_id}`}
                  className="text-brand-700 hover:underline"
                >
                  <Num value={publication.data.release_id} />
                </Link>
              </DetailItem>
              <DetailItem label="الإصدار النشط عند الطلب">
                {publication.data.base_release_id ? <Num value={publication.data.base_release_id} /> : "لا يوجد (أول إصدار)"}
              </DetailItem>
              <DetailItem label="عملية الاستيراد">
                <Link href={`/administration/civil-registry/imports/${publication.data.run_id}`} className="text-brand-700 hover:underline">
                  <ShortId id={publication.data.run_id} />
                </Link>
              </DetailItem>
              <DetailItem label="المبرر">{label(JUSTIFICATION_LABELS, publication.data.justification_code)}</DetailItem>
              <DetailItem label="قدّمه">{publication.data.requested_by?.name ?? "—"}</DetailItem>
              <DetailItem label="تاريخ التقديم">{dateLabel(publication.data.requested_at)}</DetailItem>
              <DetailItem label="المهلة">
                <span data-expiry>
                  {publication.data.status === "PENDING_APPROVAL" ? expiryLabel(publication.data) : dateLabel(publication.data.expires_at)}
                </span>
              </DetailItem>
              <DetailItem label="قرّره">{publication.data.decided_by?.name ?? "—"}</DetailItem>
              <DetailItem label="تاريخ القرار">{dateLabel(publication.data.decided_at)}</DetailItem>
              {publication.data.decision_reason_code && (
                <DetailItem label="سبب الرفض">{label(REJECTION_LABELS, publication.data.decision_reason_code)}</DetailItem>
              )}
              {publication.data.outcome_code && (
                <DetailItem label="النتيجة">
                  <span data-outcome={publication.data.outcome_code}>
                    {label(PUBLICATION_OUTCOME_LABELS, publication.data.outcome_code)}
                  </span>
                </DetailItem>
              )}
              {publication.data.publish_request && (
                <DetailItem label="تنفيذ النشر لدى العامل">
                  {label(REQUEST_STATUS_LABELS, publication.data.publish_request.status)}
                </DetailItem>
              )}
            </DetailList>
          </Panel>

          <Panel>
            <SectionHeader title="الأعداد عند الطلب" description="المقارنة بالإصدار النشط كما سُجّلت عند تقديم الطلب: أعداد فقط." />
            <DetailList className="mt-3" data-publication-snapshot>
              {Object.entries(COUNT_LABELS)
                .filter(([key]) => publication.data.snapshot[key as keyof typeof publication.data.snapshot] != null)
                .map(([key, text]) => (
                  <DetailItem key={key} label={text}>
                    <Num value={publication.data.snapshot[key as keyof typeof publication.data.snapshot]} />
                  </DetailItem>
                ))}
            </DetailList>
          </Panel>

          {can("civil-import.audit.view") && (
            <Panel flush>
              <div className="px-4 pt-4 sm:px-5">
                <SectionHeader title="سجل التدقيق" description="أحداث عملية الاستيراد، ومنها الطلب والقرار والنشر." />
              </div>
              <AuditTimeline run={publication.data.run_id} />
            </Panel>
          )}
        </>
      )}
    </div>
  );
}
