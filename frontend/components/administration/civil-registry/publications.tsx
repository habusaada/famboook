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
import type { CivilImportPublicationRequest, CivilImportPublicationStatus } from "@/lib/types/api/civil-registry";
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

// FB-CIVIL-01A-UI U3 — single-operator publication (ADR §3.3, D-UI-6/11/12a):
// a SUPER_ADMIN confirms their own request, re-entering their password.
// Nothing here publishes: the confirmation queues the worker's PUBLISH
// request, which re-checks every condition (S-3). Codes, counts and names only.

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

/** Always visible on the publication screens: publication on Production is disabled, and why confirmation may be unavailable. */
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
            : "بوابة النشر مغلقة: لا يمكن تأكيد أي طلب نشر. يمكن تقديم الطلبات ومراجعتها وسحبها."}
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
  "WITHDRAWN",
  "EXPIRED",
];

/** /administration/civil-registry/approvals — publication requests awaiting confirmation, and the publication history. */
export function PublicationQueue() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<CivilImportPublicationStatus | null>(null);
  const list = useCivilImportPublications(status, page);

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="تأكيد النشر وسجله"
        description="طلبات نشر إصدارات مرجع الهوية المدنية: يقدّمها مدير النظام ويؤكدها بنفسه بإعادة إدخال كلمة المرور."
      />
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
                  <TableHead>أكّده</TableHead>
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

type DialogKind = "CONFIRM" | "WITHDRAW" | null;

/**
 * The requester's confirmation (D-UI-12a): the release id and its verified record
 * count are shown, typed back, and the password is re-entered. The password lives
 * only in this dialog and is cleared after a failed attempt.
 */
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
  const [password, setPassword] = useState("");
  const [acknowledged, setAcknowledged] = useState(false);
  const expected = publication.snapshot.expected_presence ?? null;
  const ready =
    kind === "CONFIRM"
      ? /^\d+$/.test(release) && /^\d+$/.test(presence) && password.length > 0 && acknowledged && expected !== null
      : true;

  const submit = () => {
    const decision =
      kind === "CONFIRM"
        ? { kind, id: publication.id, password, confirmReleaseId: Number(release), confirmExpectedPresence: Number(presence) }
        : { kind, id: publication.id };
    decide.mutate(decision, { onSuccess: onClose, onError: () => setPassword("") });
  };

  return (
    <Dialog open onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="max-w-lg" data-decision-confirm={kind}>
        <DialogHeader>
          <DialogTitle>{kind === "CONFIRM" ? "تأكيد النشر" : "سحب طلب النشر"}</DialogTitle>
          <DialogDescription>
            {kind === "CONFIRM"
              ? "بتأكيدك يتولى عامل الاستيراد النشر بعد التحقق من كل الشروط من جديد، ويصبح هذا الإصدار هو الإصدار النشط دفعة واحدة. لا يراجعه مستخدم آخر."
              : "سيُغلق طلبك نهائيًا دون نشر. يلزم طلب جديد لاحقًا."}
          </DialogDescription>
        </DialogHeader>
        {kind === "CONFIRM" && (
          <div className="flex flex-col gap-3">
            <DetailList className="rounded-lg border border-stroke-subtle bg-surface-2 px-3 py-1 sm:grid-cols-1" data-confirm-summary>
              <DetailItem label="رقم الإصدار">
                <span data-confirm-release>
                  <Num value={publication.release_id} />
                </span>
              </DetailItem>
              <DetailItem label="عدد السجلات المتحقَّق منها">
                <span data-confirm-count>{expected === null ? "—" : <Num value={expected} />}</span>
              </DetailItem>
            </DetailList>
            <p className="text-[13px] text-muted-foreground">للتأكيد اكتب رقم الإصدار وعدد السجلات كما يظهران أعلاه، ثم أدخل كلمة المرور.</p>
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
              عدد السجلات المتحقَّق منها
              <input
                inputMode="numeric"
                dir="ltr"
                className="h-9 rounded-md border border-input px-2"
                value={presence}
                onChange={(e) => setPresence(e.target.value.trim())}
                aria-label="عدد السجلات المتحقَّق منها"
              />
            </label>
            <label className="flex flex-col gap-1 text-sm">
              كلمة المرور
              <input
                type="password"
                autoComplete="current-password"
                dir="ltr"
                className="h-9 rounded-md border border-input px-2"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                aria-label="كلمة المرور"
              />
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4 accent-brand-600"
                checked={acknowledged}
                onChange={(e) => setAcknowledged(e.target.checked)}
              />
              راجعت الأعداد، وأؤكد نشر هذا الإصدار بصفتي مدير النظام.
            </label>
          </div>
        )}
        <CommandError error={decide.error} />
        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose}>
            رجوع
          </Button>
          <Button variant={kind === "CONFIRM" ? "default" : "destructive"} disabled={!ready || decide.isPending} onClick={submit}>
            {decide.isPending && <Loader2 className="size-4 animate-spin" />}
            {kind === "CONFIRM" ? "تأكيد النشر" : "تأكيد"}
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
          {(publication.data.can_confirm || publication.data.can_withdraw) && (
            <div className="flex flex-wrap gap-2" data-publication-actions>
              {publication.data.can_confirm && <Button onClick={() => setDialog("CONFIRM")}>تأكيد النشر</Button>}
              {publication.data.can_withdraw && (
                <Button variant="outline" onClick={() => setDialog("WITHDRAW")}>
                  سحب الطلب
                </Button>
              )}
            </div>
          )}
          {publication.data.status === "PENDING_APPROVAL" && (
            <p className="text-[13px] text-muted-foreground" data-own-request={publication.data.is_own ? "own" : "other"}>
              {!publication.data.is_own
                ? "يؤكد هذا الطلبَ مقدّمُه فقط."
                : publication.data.can_confirm
                  ? "هذا طلبك: راجع الأعداد، ثم أكّده بإعادة إدخال كلمة المرور."
                  : "هذا طلبك: لا يمكن تأكيده ما دامت بوابة النشر مغلقة."}
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
              <DetailItem label="أكّده">{publication.data.decided_by?.name ?? "—"}</DetailItem>
              <DetailItem label="تاريخ التأكيد">{dateLabel(publication.data.decided_at)}</DetailItem>
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
                <SectionHeader title="سجل التدقيق" description="أحداث عملية الاستيراد، ومنها الطلب والتأكيد والنشر." />
              </div>
              <AuditTimeline run={publication.data.run_id} />
            </Panel>
          )}
        </>
      )}
    </div>
  );
}
